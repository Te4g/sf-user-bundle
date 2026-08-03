<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Manager;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Event\AccountRegisteredEvent;
use Te4g\UserBundle\Event\AccountVerifiedEvent;
use Te4g\UserBundle\Manager\EmailVerificationManager;
use Te4g\UserBundle\Manager\RegistrationManager;
use Te4g\UserBundle\Tests\Fixtures\Domain\FakeAccountUserFactory;
use Te4g\UserBundle\Tests\Fixtures\Domain\InMemoryAccountRepository;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordingAccountMailer;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;

final class RegistrationManagerTest extends TestCase
{
    public function testRegisterPersistsHashedUnverifiedUserDispatchesEventAndSendsVerification(): void
    {
        $repository = new InMemoryAccountRepository();
        $mailer = new RecordingAccountMailer();
        $events = new RecordedEvents();
        $manager = new RegistrationManager(
            $repository,
            new FakeAccountUserFactory(),
            new PrefixingPasswordHasher(),
            new EmailVerificationManager($repository, new SigningVerifyEmailHelper(), $mailer, $events),
            $events,
        );

        $manager->register(' New@Example.com ', 'correct horse battery staple');

        $user = $repository->findOneByEmail('new@example.com');

        self::assertInstanceOf(TestAccountUser::class, $user);
        self::assertSame('HASHED:correct horse battery staple', $user->getPassword());
        self::assertFalse($user->isVerified());
        self::assertSame([[
            'type' => 'verification',
            'user' => $user,
            'absoluteUrl' => 'https://example.test/verify/1',
        ]], $mailer->messages);
        self::assertSame([$user], $events->eventsOf(AccountRegisteredEvent::class));
    }

    public function testVerifiedDuplicateDoesNotMutateOrResendVerification(): void
    {
        $repository = new InMemoryAccountRepository();
        $existing = new TestAccountUser();
        $existing->setId(42);
        $existing->setEmail('user@example.com');
        $existing->setPassword('existing-password');
        $existing->setVerified(true);
        $repository->save($existing);
        $mailer = new RecordingAccountMailer();
        $events = new RecordedEvents();
        $manager = new RegistrationManager(
            $repository,
            new FakeAccountUserFactory(),
            new PrefixingPasswordHasher(),
            new EmailVerificationManager($repository, new SigningVerifyEmailHelper(), $mailer, $events),
            $events,
        );

        $manager->register(' USER@example.com ', 'unused password');

        self::assertSame($existing, $repository->findOneByEmail('user@example.com'));
        self::assertSame('existing-password', $existing->getPassword());
        self::assertCount(1, $repository->saved);
        self::assertSame([], $mailer->messages);
        self::assertSame([], $events->eventsOf(AccountRegisteredEvent::class));
    }

    public function testUnverifiedDuplicateResendsVerificationWithoutChangingTheAccount(): void
    {
        $repository = new InMemoryAccountRepository();
        $existing = new TestAccountUser();
        $existing->setId(42);
        $existing->setEmail('user@example.com');
        $existing->setPassword('existing-password');
        $repository->save($existing);
        $mailer = new RecordingAccountMailer();
        $events = new RecordedEvents();
        $manager = new RegistrationManager(
            $repository,
            new FakeAccountUserFactory(),
            new PrefixingPasswordHasher(),
            new EmailVerificationManager($repository, new SigningVerifyEmailHelper(), $mailer, $events),
            $events,
        );

        $manager->register(' USER@example.com ', 'unused password');

        self::assertSame('existing-password', $existing->getPassword());
        self::assertSame([[
            'type' => 'verification',
            'user' => $existing,
            'absoluteUrl' => 'https://example.test/verify/42',
        ]], $mailer->messages);
        self::assertSame([], $events->eventsOf(AccountRegisteredEvent::class));
    }

    public function testUniqueConstraintViolationKeepsRegistrationNeutral(): void
    {
        $repository = new UniqueViolationRepository();
        $mailer = new RecordingAccountMailer();
        $events = new RecordedEvents();
        $manager = new RegistrationManager(
            $repository,
            new FakeAccountUserFactory(),
            new PrefixingPasswordHasher(),
            new EmailVerificationManager($repository, new SigningVerifyEmailHelper(), $mailer, $events),
            $events,
        );

        $manager->register('user@example.com', 'password');

        self::assertSame([], $mailer->messages);
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

final class RecordedEvents extends EventDispatcher
{
    /** @var list<object> */
    private array $events = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->events[] = $event;

        return parent::dispatch($event, $eventName);
    }

    /**
     * @param class-string<AccountRegisteredEvent|AccountVerifiedEvent> $eventClass
     *
     * @return list<AccountUserInterface>
     */
    public function eventsOf(string $eventClass): array
    {
        $users = [];

        foreach ($this->events as $event) {
            if (!$event instanceof $eventClass) {
                continue;
            }

            $users[] = $event->getUser();
        }

        return $users;
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
