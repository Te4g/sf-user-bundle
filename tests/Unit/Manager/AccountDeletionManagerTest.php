<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Te4g\UserBundle\Contract\AccountDeletionGuardInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Event\AfterAccountDeletedEvent;
use Te4g\UserBundle\Event\BeforeAccountDeletedEvent;
use Te4g\UserBundle\Exception\AccountDeletionRejected;
use Te4g\UserBundle\Exception\CurrentPasswordInvalid;
use Te4g\UserBundle\Manager\AccountDeletionManager;
use Te4g\UserBundle\Tests\Fixtures\Domain\OperationTrace;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;

final class AccountDeletionManagerTest extends TestCase
{
    private DeletionTestPasswordHasher $lastHasher;

    public function testInvalidPasswordStopsBeforeGuardsEventsAndPersistence(): void
    {
        $trace = new OperationTrace();
        $repository = new DeletionTestRepository($trace);
        $hasher = new DeletionTestPasswordHasher(false, $trace);
        $guard = new DeletionTestGuard('guard', $trace);
        $events = new DeletionTestEventDispatcher($trace);
        $user = $this->user(41, 'user@example.com');
        $repository->store($user);
        $trace->clear();

        try {
            $this->manager($repository, $hasher, [$guard], $events)
                ->delete($user, 'wrong-password');
            self::fail('Account deletion must require the current password.');
        } catch (CurrentPasswordInvalid $exception) {
            self::assertStringNotContainsString('wrong-password', $exception->getMessage());
        }

        self::assertSame([[$user, 'wrong-password']], $hasher->validatedPasswords);
        self::assertSame([], $guard->users);
        self::assertSame([], $events->events);
        self::assertSame([], $repository->removed);
        self::assertSame($user, $repository->findOneByEmail('user@example.com'));
        self::assertSame(['validate-password', 'lookup:user@example.com'], $trace->operations());
    }

    public function testGuardRejectionStopsRemainingGuardsBeforeEventsAndPersistence(): void
    {
        $trace = new OperationTrace();
        $repository = new DeletionTestRepository($trace);
        $first = new DeletionTestGuard('priority-100', $trace);
        $rejecting = new DeletionTestGuard('priority-20', $trace, reject: true);
        $last = new DeletionTestGuard('priority--10', $trace);
        $events = new DeletionTestEventDispatcher($trace);
        $user = $this->user(41, 'user@example.com');
        $repository->store($user);
        $trace->clear();

        $this->expectException(AccountDeletionRejected::class);
        $this->expectExceptionMessage('account_has_orders');

        try {
            $this->manager($repository, new DeletionTestPasswordHasher(true, $trace), [$first, $rejecting, $last], $events)
                ->delete($user, 'valid-password');
        } finally {
            self::assertSame([$user], $first->users);
            self::assertSame([$user], $rejecting->users);
            self::assertSame([], $last->users);
            self::assertSame([], $events->events);
            self::assertSame([], $repository->removed);
            self::assertSame($user, $repository->findOneByEmail('user@example.com'));
            self::assertSame(
                ['validate-password', 'guard:priority-100', 'guard:priority-20', 'lookup:user@example.com'],
                $trace->operations(),
            );
        }
    }

    public function testRepositoryFailureOccursAfterBeforeEventAndPreventsAfterEvent(): void
    {
        $trace = new OperationTrace();
        $repository = new DeletionTestRepository($trace, failOnRemove: true);
        $guard = new DeletionTestGuard('priority-10', $trace);
        $events = new DeletionTestEventDispatcher($trace);
        $user = $this->user(41, 'user@example.com');
        $repository->store($user);
        $trace->clear();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('configured deletion persistence failure');

        try {
            $this->manager($repository, new DeletionTestPasswordHasher(true, $trace), [$guard], $events)
                ->delete($user, 'valid-password');
        } finally {
            self::assertCount(1, $events->events);
            self::assertInstanceOf(BeforeAccountDeletedEvent::class, $events->events[0]);
            self::assertSame($user, $events->events[0]->getUser());
            self::assertSame([$user], $repository->removed);
            self::assertSame($user, $repository->findOneByEmail('user@example.com'));
            self::assertSame(
                ['validate-password', 'guard:priority-10', 'event:before', 'remove', 'lookup:user@example.com'],
                $trace->operations(),
            );
        }
    }

    public function testSuccessRunsAllGuardsInProvidedPriorityOrderAndDispatchesCapturedScalars(): void
    {
        $trace = new OperationTrace();
        $repository = new DeletionTestRepository($trace, mutateOnRemove: true);
        $events = new DeletionTestEventDispatcher($trace);
        $guards = [
            new DeletionTestGuard('priority-100', $trace),
            new DeletionTestGuard('priority-20', $trace),
            new DeletionTestGuard('priority--10', $trace),
        ];
        $user = $this->user('account-41', 'user@example.com');
        $repository->store($user);
        $trace->clear();

        $this->manager($repository, new DeletionTestPasswordHasher(true, $trace), $guards, $events)
            ->delete($user, 'valid-password');

        self::assertSame(
            ['validate-password', 'guard:priority-100', 'guard:priority-20', 'guard:priority--10', 'event:before', 'remove', 'event:after'],
            $trace->operations(),
        );
        self::assertSame([[$user, 'valid-password']], $this->lastHasher->validatedPasswords);
        self::assertSame([$user], $repository->removed);
        self::assertNull($repository->findOneByEmail('user@example.com'));
        self::assertCount(2, $events->events);
        self::assertInstanceOf(BeforeAccountDeletedEvent::class, $events->events[0]);
        self::assertSame($user, $events->events[0]->getUser());
        self::assertInstanceOf(AfterAccountDeletedEvent::class, $events->events[1]);
        self::assertSame('account-41', $events->events[1]->getAccountId());
        self::assertSame('user@example.com', $events->events[1]->getEmail());
    }

    public function testAfterListenerFailureIsLoggedWithoutReportingDeletionFailure(): void
    {
        $trace = new OperationTrace();
        $repository = new DeletionTestRepository($trace);
        $events = new DeletionTestEventDispatcher($trace, failAfter: true);
        $logger = new DeletionTestLogger();
        $user = $this->user(41, 'user@example.com');
        $repository->store($user);
        $trace->clear();

        $this->manager($repository, new DeletionTestPasswordHasher(true, $trace), [], $events, $logger)
            ->delete($user, 'valid-password');

        self::assertSame([$user], $repository->removed);
        self::assertNull($repository->findOneByEmail('user@example.com'));
        self::assertSame(['validate-password', 'event:before', 'remove', 'event:after', 'lookup:user@example.com'], $trace->operations());
        self::assertSame([[
            'level' => LogLevel::ERROR,
            'message' => 'An after-account-deleted listener failed after persistence completed.',
            'context' => ['exception_class' => \RuntimeException::class],
        ]], $logger->records);
    }

    public function testAfterListenerAndLoggerFailuresStayContainedAfterCompletedDeletion(): void
    {
        $trace = new OperationTrace();
        $repository = new DeletionTestRepository($trace, mutateOnRemove: true);
        $events = new DeletionTestEventDispatcher($trace, failAfter: true);
        $logger = new DeletionTestLogger(throwOnLog: true);
        $user = $this->user('account-41', 'user@example.com');
        $repository->store($user);
        $trace->clear();

        $this->manager($repository, new DeletionTestPasswordHasher(true, $trace), [], $events, $logger)
            ->delete($user, 'valid-password');

        self::assertSame([$user], $repository->removed);
        self::assertNull($repository->findOneByEmail('user@example.com'));
        self::assertSame(['validate-password', 'event:before', 'remove', 'event:after', 'lookup:user@example.com'], $trace->operations());
        self::assertCount(1, $events->afterAttempts);
        self::assertSame('account-41', $events->afterAttempts[0]->getAccountId());
        self::assertSame('user@example.com', $events->afterAttempts[0]->getEmail());
        self::assertSame([[
            'level' => LogLevel::ERROR,
            'message' => 'An after-account-deleted listener failed after persistence completed.',
            'context' => ['exception_class' => \RuntimeException::class],
        ]], $logger->records);
        self::assertStringNotContainsString('user@example.com', serialize($logger->records));
        self::assertStringNotContainsString('listener leaked', serialize($logger->records));
        self::assertStringNotContainsString('logger transport leaked', serialize($logger->records));
    }

    /** @param iterable<AccountDeletionGuardInterface> $guards */
    private function manager(
        DeletionTestRepository $repository,
        ?DeletionTestPasswordHasher $hasher = null,
        iterable $guards = [],
        ?DeletionTestEventDispatcher $events = null,
        ?DeletionTestLogger $logger = null,
    ): AccountDeletionManager {
        $this->lastHasher = $hasher ?? new DeletionTestPasswordHasher();

        return new AccountDeletionManager(
            $repository,
            $this->lastHasher,
            $guards,
            $events ?? new DeletionTestEventDispatcher(),
            $logger ?? new DeletionTestLogger(),
        );
    }

    private function user(int|string $id, string $email): TestAccountUser
    {
        $user = new TestAccountUser();
        $user->setId($id);
        $user->setEmail($email);

        return $user;
    }
}

final class DeletionTestRepository implements AccountRepositoryInterface
{
    /** @var list<AccountUserInterface> */
    public array $removed = [];

    /** @var array<string, AccountUserInterface> */
    private array $usersByEmail = [];

    public function __construct(
        private readonly ?OperationTrace $trace = null,
        private readonly bool $failOnRemove = false,
        private readonly bool $mutateOnRemove = false,
    ) {
    }

    public function store(AccountUserInterface $user): void
    {
        $this->usersByEmail[$user->getEmail()] = $user;
    }

    public function findOneByEmail(string $email): ?AccountUserInterface
    {
        $this->trace?->record('lookup:'.$email);

        return $this->usersByEmail[$email] ?? null;
    }

    public function findById(int|string $id): ?AccountUserInterface
    {
        foreach ($this->usersByEmail as $user) {
            if ((string) $user->getId() === (string) $id) {
                return $user;
            }
        }

        return null;
    }

    public function save(AccountUserInterface $user): void
    {
        throw new \LogicException('Deletion does not save accounts.');
    }

    public function remove(AccountUserInterface $user): void
    {
        $this->trace?->record('remove');
        $this->removed[] = $user;

        if ($this->failOnRemove) {
            throw new \RuntimeException('configured deletion persistence failure');
        }

        unset($this->usersByEmail[$user->getEmail()]);

        if ($this->mutateOnRemove && $user instanceof TestAccountUser) {
            $user->setId(999);
            $user->setEmail('removed@example.com');
        }
    }
}

final class DeletionTestPasswordHasher implements UserPasswordHasherInterface
{
    /** @var list<array{PasswordAuthenticatedUserInterface, string}> */
    public array $validatedPasswords = [];

    public function __construct(
        private readonly bool $valid = true,
        private readonly ?OperationTrace $trace = null,
    ) {
    }

    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): string
    {
        throw new \LogicException('Deletion does not hash passwords.');
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): bool
    {
        $this->trace?->record('validate-password');
        $this->validatedPasswords[] = [$user, $plainPassword];

        return $this->valid;
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        return false;
    }
}

final class DeletionTestGuard implements AccountDeletionGuardInterface
{
    /** @var list<AccountUserInterface> */
    public array $users = [];

    public function __construct(
        private readonly string $name,
        private readonly OperationTrace $trace,
        private readonly bool $reject = false,
    ) {
    }

    public function assertDeletionAllowed(AccountUserInterface $user): void
    {
        $this->trace->record('guard:'.$this->name);
        $this->users[] = $user;

        if ($this->reject) {
            throw new AccountDeletionRejected('account_has_orders');
        }
    }
}

final class DeletionTestEventDispatcher implements EventDispatcherInterface
{
    /** @var list<AfterAccountDeletedEvent> */
    public array $afterAttempts = [];

    /** @var list<object> */
    public array $events = [];

    public function __construct(
        private readonly ?OperationTrace $trace = null,
        private readonly bool $failAfter = false,
    ) {
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        if ($event instanceof BeforeAccountDeletedEvent) {
            $this->trace?->record('event:before');
        }

        if ($event instanceof AfterAccountDeletedEvent) {
            $this->trace?->record('event:after');
            $this->afterAttempts[] = $event;

            if ($this->failAfter) {
                throw new \RuntimeException('listener leaked user@example.com');
            }
        }

        $this->events[] = $event;

        return $event;
    }
}

final class DeletionTestLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function __construct(private readonly bool $throwOnLog = false)
    {
    }

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];

        if ($this->throwOnLog) {
            throw new \RuntimeException('logger transport leaked logger-secret@example.com');
        }
    }
}
