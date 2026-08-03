<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Manager;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Event\AccountRegisteredEvent;
use Te4g\UserBundle\Manager\EmailVerificationManager;
use Te4g\UserBundle\Manager\RegistrationManager;
use Te4g\UserBundle\Tests\Fixtures\Domain\OperationTrace;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordedEvents;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;
use Te4g\UserBundle\Tests\Fixtures\Domain\TracingAccountRepository;

final class RegistrationManagerTest extends TestCase
{
    public function testRegisterPersistsHashedUnverifiedUserDispatchesEventAndSendsVerification(): void
    {
        $trace = new OperationTrace();
        $repository = new TracingAccountRepository($trace);
        $mailer = new TracingAccountMailer($trace);
        $events = new RecordedEvents($trace);
        $manager = new RegistrationManager(
            $repository,
            new TracingAccountUserFactory($trace),
            new TracingPasswordHasher($trace),
            new EmailVerificationManager($repository, new RegistrationVerifyEmailHelper(), $mailer, $events),
            $events,
        );

        $manager->register(' New@Example.com ', 'correct horse battery staple');

        $user = $repository->saved[0] ?? null;

        self::assertInstanceOf(TestAccountUser::class, $user);
        self::assertSame('HASHED:correct horse battery staple', $user->getPassword());
        self::assertFalse($user->isVerified());
        self::assertSame([$user], $mailer->verifiedUsers);
        self::assertSame([$user], $events->eventsOf(AccountRegisteredEvent::class));
        self::assertSame([
            'lookup:new@example.com',
            'create',
            'hash',
            'save',
            'event:registered',
            'verification-send',
        ], $trace->operations());
    }

    public function testVerifiedDuplicateDoesNotMutateOrResendVerification(): void
    {
        $trace = new OperationTrace();
        $repository = new TracingAccountRepository($trace);
        $existing = new TestAccountUser();
        $existing->setId(42);
        $existing->setEmail('user@example.com');
        $existing->setPassword('existing-password');
        $existing->setVerified(true);
        $repository->save($existing);
        $trace->clear();
        $mailer = new TracingAccountMailer($trace);
        $events = new RecordedEvents($trace);
        $manager = new RegistrationManager(
            $repository,
            new TracingAccountUserFactory(new OperationTrace()),
            new PrefixingPasswordHasher(),
            new EmailVerificationManager($repository, new RegistrationVerifyEmailHelper(), $mailer, $events),
            $events,
        );

        $manager->register(' USER@example.com ', 'unused password');

        self::assertSame('existing-password', $existing->getPassword());
        self::assertCount(1, $repository->saved);
        self::assertSame([], $mailer->verifiedUsers);
        self::assertSame([], $events->eventsOf(AccountRegisteredEvent::class));
        self::assertSame(['lookup:user@example.com'], $trace->operations());
    }

    public function testUnverifiedDuplicateResendsVerificationWithoutChangingTheAccount(): void
    {
        $trace = new OperationTrace();
        $repository = new TracingAccountRepository($trace);
        $existing = new TestAccountUser();
        $existing->setId(42);
        $existing->setEmail('user@example.com');
        $existing->setPassword('existing-password');
        $repository->save($existing);
        $trace->clear();
        $mailer = new TracingAccountMailer($trace);
        $events = new RecordedEvents($trace);
        $manager = new RegistrationManager(
            $repository,
            new TracingAccountUserFactory(new OperationTrace()),
            new PrefixingPasswordHasher(),
            new EmailVerificationManager($repository, new RegistrationVerifyEmailHelper(), $mailer, $events),
            $events,
        );

        $manager->register(' USER@example.com ', 'unused password');

        self::assertSame('existing-password', $existing->getPassword());
        self::assertSame([$existing], $mailer->verifiedUsers);
        self::assertSame([], $events->eventsOf(AccountRegisteredEvent::class));
        self::assertSame(['lookup:user@example.com', 'verification-send'], $trace->operations());
    }

    public function testUniqueConstraintViolationKeepsRegistrationNeutral(): void
    {
        $repository = new UniqueViolationRepository();
        $mailer = new TracingAccountMailer(new OperationTrace());
        $events = new RecordedEvents();
        $manager = new RegistrationManager(
            $repository,
            new TracingAccountUserFactory(new OperationTrace()),
            new PrefixingPasswordHasher(),
            new EmailVerificationManager($repository, new RegistrationVerifyEmailHelper(), $mailer, $events),
            $events,
        );

        $manager->register('user@example.com', 'password');

        self::assertSame([], $mailer->verifiedUsers);
        self::assertSame([], $events->eventsOf(AccountRegisteredEvent::class));
    }
}

final class PrefixingPasswordHasher implements UserPasswordHasherInterface
{
    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): string
    {
        return 'HASHED:'.$plainPassword;
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): bool
    {
        return false;
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        return false;
    }
}

final class TracingAccountUserFactory implements \Te4g\UserBundle\Contract\AccountUserFactoryInterface
{
    private int $nextId = 1;

    public function __construct(private readonly OperationTrace $trace)
    {
    }

    public function create(string $normalizedEmail): AccountUserInterface
    {
        $this->trace->record('create');

        $user = new TestAccountUser();
        $user->setId($this->nextId++);
        $user->setEmail($normalizedEmail);

        return $user;
    }
}

final class TracingPasswordHasher implements UserPasswordHasherInterface
{
    public function __construct(private readonly OperationTrace $trace)
    {
    }

    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): string
    {
        $this->trace->record('hash');

        return 'HASHED:'.$plainPassword;
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): bool
    {
        return false;
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        return false;
    }
}

final class TracingAccountMailer implements \Te4g\UserBundle\Contract\AccountMailerInterface
{
    /** @var list<AccountUserInterface> */
    public array $verifiedUsers = [];

    public function __construct(private readonly OperationTrace $trace)
    {
    }

    public function sendVerification(AccountUserInterface $user, string $absoluteUrl): void
    {
        $this->trace->record('verification-send');
        $this->verifiedUsers[] = $user;
    }

    public function sendMagicLink(AccountUserInterface $user, string $absoluteUrl): void
    {
    }

    public function sendPasswordReset(AccountUserInterface $user, string $absoluteUrl): void
    {
    }

    public function sendEmailChange(AccountUserInterface $user, string $absoluteUrl): void
    {
    }
}

final class RegistrationVerifyEmailHelper implements VerifyEmailHelperInterface
{
    /** @param array<string, string> $extraParams */
    public function generateSignature(string $routeName, string $userId, string $userEmail, array $extraParams = []): VerifyEmailSignatureComponents
    {
        return new VerifyEmailSignatureComponents(new \DateTimeImmutable('+1 hour'), 'https://example.test/verify/'.$userId, time());
    }

    public function validateEmailConfirmation(string $signedUrl, string $userId, string $userEmail): void
    {
    }
}

final class UniqueViolationRepository implements AccountRepositoryInterface
{
    public function findOneByEmail(string $email): ?AccountUserInterface
    {
        return null;
    }

    public function findById(int|string $id): ?AccountUserInterface
    {
        return null;
    }

    public function save(AccountUserInterface $user): void
    {
        throw new UniqueConstraintViolationException(new RegistrationTestDriverException(), null);
    }

    public function remove(AccountUserInterface $user): void
    {
    }
}

final class RegistrationTestDriverException extends \RuntimeException implements DriverException
{
    public function getSQLState(): ?string
    {
        return null;
    }
}
