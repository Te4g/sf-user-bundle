<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Te4g\UserBundle\Security\AccountUserProvider;
use Te4g\UserBundle\Tests\Fixtures\Domain\OperationTrace;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;
use Te4g\UserBundle\Tests\Fixtures\Domain\TracingAccountRepository;
use Te4g\UserBundle\Tests\Fixtures\Persistence\TestUser;
use Te4g\UserBundle\Util\EmailNormalizer;

final class AccountUserProviderTest extends TestCase
{
    public function testLoadUserByIdentifierNormalizesBeforeRepositoryLookup(): void
    {
        $trace = new OperationTrace();
        $repository = new TracingAccountRepository($trace);
        $user = $this->user(17, 'user@example.com');
        $repository->save($user);
        $trace->clear();

        $loaded = $this->provider($repository)->loadUserByIdentifier(' User@Example.COM ');

        self::assertSame($user, $loaded);
        self::assertSame(['lookup:user@example.com'], $trace->operations());
    }

    public function testUnknownIdentifierProducesGenericFailureWithOnlyThePrivateNormalizedIdentifier(): void
    {
        $provider = $this->provider(new TracingAccountRepository(new OperationTrace()));

        try {
            $provider->loadUserByIdentifier(' Missing@Example.COM ');
            self::fail('An unknown identifier must not load an account.');
        } catch (UserNotFoundException $exception) {
            self::assertSame('Username could not be found.', $exception->getMessageKey());
            self::assertSame('missing@example.com', $exception->getUserIdentifier());
            self::assertStringNotContainsString('missing@example.com', $exception->getMessage());
            self::assertStringNotContainsString('Missing@Example.COM', $exception->getMessage());
        }
    }

    public function testRefreshLoadsTheAccountByItsNonNullIdentifier(): void
    {
        $trace = new OperationTrace();
        $repository = new TracingAccountRepository($trace);
        $user = $this->user('account-17', 'user@example.com');
        $repository->save($user);
        $trace->clear();

        $refreshed = $this->provider($repository)->refreshUser($user);

        self::assertSame($user, $refreshed);
        self::assertSame(['load:account-17'], $trace->operations());
    }

    #[DataProvider('unsupportedRefreshUsers')]
    public function testRefreshRejectsUnsupportedOrUnpersistedUsers(TestAccountUser|InMemoryUser $user): void
    {
        $trace = new OperationTrace();
        $provider = $this->provider(new TracingAccountRepository($trace));

        try {
            $provider->refreshUser($user);
            self::fail('An unsupported user must not be refreshed.');
        } catch (UnsupportedUserException $exception) {
            self::assertStringNotContainsString($user->getUserIdentifier(), $exception->getMessage());
        }

        self::assertSame([], $trace->operations());
    }

    /** @return iterable<string, array{TestAccountUser|InMemoryUser}> */
    public static function unsupportedRefreshUsers(): iterable
    {
        yield 'user outside the account contract' => [new InMemoryUser('outside@example.com', 'hash')];
        yield 'account without a persisted identifier' => [new TestAccountUser()];
    }

    public function testRefreshMissingAccountProducesGenericFailure(): void
    {
        $user = $this->user(404, 'missing@example.com');

        try {
            $this->provider(new TracingAccountRepository(new OperationTrace()))->refreshUser($user);
            self::fail('A missing persisted account must not be refreshed.');
        } catch (UserNotFoundException $exception) {
            self::assertSame('Username could not be found.', $exception->getMessageKey());
            self::assertNull($exception->getUserIdentifier());
            self::assertStringNotContainsString('missing@example.com', $exception->getMessage());
            self::assertStringNotContainsString('404', $exception->getMessage());
        }
    }

    public function testSupportsOnlyConfiguredAccountClassAndItsAccountSubclasses(): void
    {
        $provider = $this->provider(new TracingAccountRepository(new OperationTrace()));

        self::assertTrue($provider->supportsClass(TestAccountUser::class));
        self::assertTrue($provider->supportsClass(ProviderAccountUserSubclass::class));
        self::assertFalse($provider->supportsClass(TestUser::class));
        self::assertFalse($provider->supportsClass(\stdClass::class));
    }

    public function testPasswordUpgradeSetsAndSavesOnlyTheSuppliedHash(): void
    {
        $trace = new OperationTrace();
        $repository = new TracingAccountRepository($trace);
        $user = new PasswordTracingAccountUser($trace);
        $user->setId(17);
        $user->setEmail('user@example.com');
        $user->setPassword('old-hash');
        $repository->save($user);
        $trace->clear();

        $this->provider($repository)->upgradePassword($user, 'new-supplied-hash');

        self::assertSame('new-supplied-hash', $user->getPassword());
        self::assertSame(['set-password', 'save'], $trace->operations());
    }

    public function testPasswordUpgradeRejectsAUserOutsideTheAccountContract(): void
    {
        $trace = new OperationTrace();
        $provider = $this->provider(new TracingAccountRepository($trace));

        try {
            $provider->upgradePassword(new InMemoryUser('outside@example.com', 'old-hash'), 'new-hash');
            self::fail('A user outside the account contract must not be upgraded.');
        } catch (UnsupportedUserException $exception) {
            self::assertStringNotContainsString('outside@example.com', $exception->getMessage());
        }

        self::assertSame([], $trace->operations());
    }

    private function provider(TracingAccountRepository $repository): AccountUserProvider
    {
        return new AccountUserProvider($repository, new EmailNormalizer(), TestAccountUser::class);
    }

    private function user(int|string $id, string $email): TestAccountUser
    {
        $user = new TestAccountUser();
        $user->setId($id);
        $user->setEmail($email);

        return $user;
    }
}

final class ProviderAccountUserSubclass extends TestAccountUser
{
}

final class PasswordTracingAccountUser extends TestAccountUser
{
    public function __construct(private readonly OperationTrace $trace)
    {
    }

    public function setPassword(string $password): void
    {
        $this->trace->record('set-password');

        parent::setPassword($password);
    }
}
