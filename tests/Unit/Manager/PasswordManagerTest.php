<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;
use Te4g\UserBundle\Event\PasswordChangedEvent;
use Te4g\UserBundle\Event\PasswordChangeOrigin;
use Te4g\UserBundle\Exception\CurrentPasswordInvalid;
use Te4g\UserBundle\Manager\PasswordManager;
use Te4g\UserBundle\Tests\Fixtures\Domain\InMemoryAccountRepository;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;

final class PasswordManagerTest extends TestCase
{
    public function testWrongCurrentPasswordIsRejectedWithoutCredentialOrPersistenceSideEffects(): void
    {
        $repository = new InMemoryAccountRepository();
        $resetRequests = new ChangeTestRequestRepository();
        $hasher = new ChangeTestPasswordHasher(false);
        $events = new ChangeTestEventDispatcher();
        $user = $this->user('ORIGINAL_HASH');
        $manager = new PasswordManager($repository, $resetRequests, $hasher, $events);

        try {
            $manager->change($user, 'wrong current password', 'new secret password');
            self::fail('A wrong current password must be rejected.');
        } catch (CurrentPasswordInvalid $exception) {
            self::assertStringNotContainsString('wrong current password', $exception->getMessage());
            self::assertStringNotContainsString('new secret password', $exception->getMessage());
        }

        self::assertSame([[$user, 'wrong current password']], $hasher->validatedPasswords);
        self::assertSame([], $hasher->hashedPasswords);
        self::assertSame('ORIGINAL_HASH', $user->getPassword());
        self::assertSame([], $repository->saved);
        self::assertSame([], $resetRequests->removedFor);
        self::assertSame([], $events->events);
    }

    public function testSuccessfulChangeHashesSavesInvalidatesResetsAndDispatchesAuthenticatedEvent(): void
    {
        $repository = new InMemoryAccountRepository();
        $resetRequests = new ChangeTestRequestRepository();
        $hasher = new ChangeTestPasswordHasher(true);
        $events = new ChangeTestEventDispatcher();
        $user = $this->user('ORIGINAL_HASH');

        (new PasswordManager($repository, $resetRequests, $hasher, $events))
            ->change($user, 'valid current password', 'new secret password');

        self::assertSame([[$user, 'valid current password']], $hasher->validatedPasswords);
        self::assertSame([[$user, 'new secret password']], $hasher->hashedPasswords);
        self::assertSame('NEW_PASSWORD_HASH', $user->getPassword());
        self::assertSame([$user], $repository->saved);
        self::assertSame([$user], $resetRequests->removedFor);
        self::assertCount(1, $events->events);
        $event = $events->events[0];
        self::assertInstanceOf(PasswordChangedEvent::class, $event);
        self::assertSame($user, $event->getUser());
        self::assertSame(PasswordChangeOrigin::Authenticated, $event->getOrigin());
        self::assertStringNotContainsString('valid current password', serialize($event));
        self::assertStringNotContainsString('new secret password', serialize($event));
    }

    private function user(string $password): TestAccountUser
    {
        $user = new TestAccountUser();
        $user->setEmail('user@example.com');
        $user->setPassword($password);

        return $user;
    }
}

final class ChangeTestPasswordHasher implements UserPasswordHasherInterface
{
    /** @var list<array{PasswordAuthenticatedUserInterface, string}> */
    public array $validatedPasswords = [];

    /** @var list<array{PasswordAuthenticatedUserInterface, string}> */
    public array $hashedPasswords = [];

    public function __construct(private readonly bool $passwordValid)
    {
    }

    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): string
    {
        $this->hashedPasswords[] = [$user, $plainPassword];

        return 'NEW_PASSWORD_HASH';
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): bool
    {
        $this->validatedPasswords[] = [$user, $plainPassword];

        return $this->passwordValid;
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        return false;
    }
}

final class ChangeTestRequestRepository implements ResetPasswordRequestRepositoryInterface
{
    /** @var list<object> */
    public array $removedFor = [];

    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): ResetPasswordRequestInterface
    {
        throw new \LogicException('Password change does not create reset requests.');
    }

    public function getUserIdentifier(object $user): string
    {
        throw new \LogicException('Password change does not resolve reset user identifiers.');
    }

    public function persistResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        throw new \LogicException('Password change does not persist reset requests.');
    }

    public function findResetPasswordRequest(string $selector): ?ResetPasswordRequestInterface
    {
        throw new \LogicException('Password change does not find reset requests.');
    }

    public function getMostRecentNonExpiredRequestDate(object $user): ?\DateTimeInterface
    {
        throw new \LogicException('Password change does not inspect reset requests.');
    }

    public function removeResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        throw new \LogicException('Password change removes requests by account.');
    }

    public function removeExpiredResetPasswordRequests(): int
    {
        throw new \LogicException('Password change does not clean expired reset requests.');
    }

    public function removeRequests(object $user): void
    {
        $this->removedFor[] = $user;
    }
}

final class ChangeTestEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->events[] = $event;

        return $event;
    }
}
