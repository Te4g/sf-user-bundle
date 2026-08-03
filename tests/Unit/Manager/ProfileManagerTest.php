<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\InvalidSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Event\EmailChangedEvent;
use Te4g\UserBundle\Event\EmailChangeRequestedEvent;
use Te4g\UserBundle\Exception\CurrentPasswordInvalid;
use Te4g\UserBundle\Exception\EmailChangeFailed;
use Te4g\UserBundle\Manager\ProfileManager;
use Te4g\UserBundle\Tests\Fixtures\Domain\OperationTrace;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;

final class ProfileManagerTest extends TestCase
{
    public function testRequestKeepsActiveEmailAndPersistsNormalizedPendingAddressBeforeNotification(): void
    {
        $trace = new OperationTrace();
        $repository = new ProfileTestRepository($trace);
        $hasher = new ProfileTestPasswordHasher(true, $trace);
        $helper = new ProfileTestVerifyEmailHelper($trace);
        $mailer = new ProfileTestMailer($trace);
        $events = new ProfileTestEventDispatcher($trace);
        $user = $this->user(41, 'old@example.com', $trace);
        $trace->clear();

        $this->manager($repository, $hasher, $helper, $mailer, new ProfileTestResetRepository($trace), $events)
            ->requestEmailChange($user, ' New@Example.com ', 'valid-password');

        self::assertSame('old@example.com', $user->getEmail());
        self::assertSame('new@example.com', $user->getPendingEmail());
        self::assertSame([[$user, 'valid-password']], $hasher->validatedPasswords);
        self::assertSame(['new@example.com'], $repository->lookedUpEmails);
        self::assertSame([$user], $repository->saved);
        self::assertSame([[
            'route' => 'te4g_user_email_change_confirm',
            'userId' => '41',
            'email' => 'new@example.com',
            'extraParams' => ['id' => '41'],
        ]], $helper->generatedSignatures);
        self::assertSame([[
            'user' => $user,
            'absoluteUrl' => 'https://example.test/email-change/41',
        ]], $mailer->messages);
        self::assertSame(
            ['validate-password', 'lookup:new@example.com', 'set-pending:new@example.com', 'save', 'sign:new@example.com', 'mail', 'event:requested'],
            $trace->operations(),
        );
        self::assertCount(1, $events->events);
        self::assertInstanceOf(EmailChangeRequestedEvent::class, $events->events[0]);
        self::assertSame($user, $events->events[0]->getUser());
    }

    public function testRequestRejectsInvalidPasswordBeforeLookupOrMutation(): void
    {
        $trace = new OperationTrace();
        $repository = new ProfileTestRepository($trace);
        $hasher = new ProfileTestPasswordHasher(false, $trace);
        $user = $this->user(41, 'old@example.com', $trace);
        $trace->clear();

        try {
            $this->manager(repository: $repository, hasher: $hasher)
                ->requestEmailChange($user, 'new@example.com', 'wrong-password');
            self::fail('An email change must require the current password.');
        } catch (CurrentPasswordInvalid $exception) {
            self::assertStringNotContainsString('wrong-password', $exception->getMessage());
        }

        self::assertSame([[$user, 'wrong-password']], $hasher->validatedPasswords);
        self::assertNull($user->getPendingEmail());
        self::assertSame([], $repository->lookedUpEmails);
        self::assertSame([], $repository->saved);
        self::assertSame(['validate-password'], $trace->operations());
    }

    public function testRequestRejectsDuplicateNormalizedPendingEmailWithoutChangingState(): void
    {
        $trace = new OperationTrace();
        $repository = new ProfileTestRepository($trace);
        $existing = $this->user(52, 'new@example.com');
        $repository->indexedUsers['new@example.com'] = $existing;
        $repository->resetRecords();
        $user = $this->user(41, 'old@example.com', $trace);
        $trace->clear();

        try {
            $this->manager(repository: $repository, hasher: new ProfileTestPasswordHasher(true, $trace))
                ->requestEmailChange($user, ' NEW@example.COM ', 'valid-password');
            self::fail('An address already assigned to an account must be rejected.');
        } catch (EmailChangeFailed $exception) {
            self::assertStringNotContainsString('new@example.com', strtolower($exception->getMessage()));
        }

        self::assertSame(['new@example.com'], $repository->lookedUpEmails);
        self::assertNull($user->getPendingEmail());
        self::assertSame([], $repository->saved);
        self::assertSame(['validate-password', 'lookup:new@example.com'], $trace->operations());
    }

    public function testRequestRejectsAccountWithoutIdentifierBeforePendingStateIsSaved(): void
    {
        $user = $this->user(null, 'old@example.com');
        $repository = new ProfileTestRepository();

        $this->expectException(EmailChangeFailed::class);

        try {
            $this->manager(repository: $repository)
                ->requestEmailChange($user, 'new@example.com', 'valid-password');
        } finally {
            self::assertNull($user->getPendingEmail());
            self::assertSame([], $repository->saved);
        }
    }

    public function testConfirmationValidatesLatestPendingAddressThenSwapsAndInvalidatesResetRequests(): void
    {
        $trace = new OperationTrace();
        $repository = new ProfileTestRepository($trace);
        $user = $this->user('account-41', 'old@example.com', $trace);
        $user->setPendingEmail('new@example.com');
        $repository->indexedUsersById['account-41'] = $user;
        $repository->resetRecords();
        $trace->clear();
        $helper = new ProfileTestVerifyEmailHelper($trace);
        $resetRequests = new ProfileTestResetRepository($trace);
        $events = new ProfileTestEventDispatcher($trace);

        $confirmed = $this->manager(
            repository: $repository,
            helper: $helper,
            resetRequests: $resetRequests,
            events: $events,
        )->confirmEmailChange('https://example.test/email-change/account-41?signature=signed', 'account-41');

        self::assertSame($user, $confirmed);
        self::assertSame('new@example.com', $user->getEmail());
        self::assertNull($user->getPendingEmail());
        self::assertSame([[
            'signedUri' => 'https://example.test/email-change/account-41?signature=signed',
            'userId' => 'account-41',
            'email' => 'new@example.com',
        ]], $helper->validationAttempts);
        self::assertSame([$user], $repository->saved);
        self::assertSame([$user], $resetRequests->removedFor);
        self::assertSame(
            ['load:account-41', 'validate-signature:new@example.com', 'set-email:new@example.com', 'set-pending:null', 'save', 'remove-account-resets', 'event:changed'],
            $trace->operations(),
        );
        self::assertCount(1, $events->events);
        self::assertInstanceOf(EmailChangedEvent::class, $events->events[0]);
        self::assertSame($user, $events->events[0]->getUser());
        self::assertSame('old@example.com', $events->events[0]->getPreviousEmail());
        self::assertStringNotContainsString('signature=signed', serialize($events->events[0]));
    }

    public function testConfirmationRejectsSupersededSignedAddressWithoutMutatingCurrentPendingState(): void
    {
        $trace = new OperationTrace();
        $repository = new ProfileTestRepository($trace);
        $user = $this->user(41, 'old@example.com');
        $user->setPendingEmail('latest@example.com');
        $repository->indexedUsersById['41'] = $user;
        $repository->resetRecords();
        $helper = new ProfileTestVerifyEmailHelper($trace, throwOnValidation: true);
        $resetRequests = new ProfileTestResetRepository($trace);
        $events = new ProfileTestEventDispatcher($trace);

        try {
            $this->manager(repository: $repository, helper: $helper, resetRequests: $resetRequests, events: $events)
                ->confirmEmailChange('https://example.test/email-change/41?signature=old-secret', 41);
            self::fail('A confirmation for a superseded pending address must fail.');
        } catch (EmailChangeFailed $exception) {
            self::assertStringNotContainsString('old-secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertSame([[
            'signedUri' => 'https://example.test/email-change/41?signature=old-secret',
            'userId' => '41',
            'email' => 'latest@example.com',
        ]], $helper->validationAttempts);
        self::assertSame('old@example.com', $user->getEmail());
        self::assertSame('latest@example.com', $user->getPendingEmail());
        self::assertSame([], $repository->saved);
        self::assertSame([], $resetRequests->removedFor);
        self::assertSame([], $events->events);
        self::assertSame(['load:41', 'validate-signature:latest@example.com'], $trace->operations());
    }

    public function testConfirmationRedactsSignedUriFromTranslatedExceptionTrace(): void
    {
        $previousExceptionArgsSetting = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $repository = new ProfileTestRepository();
        $user = $this->user(41, 'old@example.com');
        $user->setPendingEmail('new@example.com');
        $repository->indexedUsersById['41'] = $user;
        $helper = new ProfileTestVerifyEmailHelper(throwOnValidation: true);

        try {
            $this->manager(repository: $repository, helper: $helper)
                ->confirmEmailChange('https://example.test/email-change/41?signature=trace-secret', 41);
            self::fail('An invalid email-change signature must fail.');
        } catch (EmailChangeFailed $exception) {
            $confirmationFrame = null;

            foreach ($exception->getTrace() as $frame) {
                if (ProfileManager::class === ($frame['class'] ?? null) && 'confirmEmailChange' === $frame['function']) {
                    $confirmationFrame = $frame;
                    break;
                }
            }

            self::assertNotNull($confirmationFrame);

            if (!isset($confirmationFrame['args'])) {
                self::fail('The confirmation trace frame must expose redacted arguments for this test.');
            }

            self::assertInstanceOf(\SensitiveParameterValue::class, $confirmationFrame['args'][0]);
            self::assertSame(41, $confirmationFrame['args'][1]);
        } finally {
            if (false !== $previousExceptionArgsSetting) {
                ini_set('zend.exception_ignore_args', $previousExceptionArgsSetting);
            }
        }
    }

    public function testConfirmationRejectsMissingPendingStateBeforeSignatureValidation(): void
    {
        $repository = new ProfileTestRepository();
        $user = $this->user(41, 'old@example.com');
        $repository->indexedUsersById['41'] = $user;
        $repository->resetRecords();
        $helper = new ProfileTestVerifyEmailHelper();

        $this->expectException(EmailChangeFailed::class);

        try {
            $this->manager(repository: $repository, helper: $helper)
                ->confirmEmailChange('https://example.test/email-change/41?signature=secret', 41);
        } finally {
            self::assertSame([], $helper->validationAttempts);
            self::assertSame([], $repository->saved);
        }
    }

    public function testConfirmationRejectsUnknownAccountAfterExactLoadWithoutAnyOtherSideEffect(): void
    {
        $trace = new OperationTrace();
        $repository = new ProfileTestRepository($trace);
        $helper = new ProfileTestVerifyEmailHelper($trace);
        $mailer = new ProfileTestMailer($trace);
        $resetRequests = new ProfileTestResetRepository($trace);
        $events = new ProfileTestEventDispatcher($trace);

        try {
            $this->manager($repository, helper: $helper, mailer: $mailer, resetRequests: $resetRequests, events: $events)
                ->confirmEmailChange('https://example.test/email-change/missing?signature=unknown-secret', 'missing-account');
            self::fail('An unknown account ID must not confirm an email change.');
        } catch (EmailChangeFailed $exception) {
            self::assertSame('Email change failed.', $exception->getMessage());
            self::assertStringNotContainsString('missing-account', $exception->getMessage());
            self::assertStringNotContainsString('unknown-secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertSame(['load:missing-account'], $trace->operations());
        self::assertSame([], $helper->validationAttempts);
        self::assertSame([], $helper->generatedSignatures);
        self::assertSame([], $repository->saved);
        self::assertSame([], $resetRequests->removedFor);
        self::assertSame([], $mailer->messages);
        self::assertSame([], $events->events);
    }

    private function manager(
        ?ProfileTestRepository $repository = null,
        ?ProfileTestPasswordHasher $hasher = null,
        ?ProfileTestVerifyEmailHelper $helper = null,
        ?ProfileTestMailer $mailer = null,
        ?ProfileTestResetRepository $resetRequests = null,
        ?ProfileTestEventDispatcher $events = null,
    ): ProfileManager {
        return new ProfileManager(
            $repository ?? new ProfileTestRepository(),
            $hasher ?? new ProfileTestPasswordHasher(),
            $helper ?? new ProfileTestVerifyEmailHelper(),
            $mailer ?? new ProfileTestMailer(),
            $resetRequests ?? new ProfileTestResetRepository(),
            $events ?? new ProfileTestEventDispatcher(),
        );
    }

    private function user(int|string|null $id, string $email, ?OperationTrace $trace = null): TestAccountUser
    {
        $user = null === $trace ? new TestAccountUser() : new ProfileTestUser($trace);
        $user->setId($id);
        $user->setEmail($email);

        return $user;
    }
}

final class ProfileTestUser extends TestAccountUser
{
    public function __construct(private readonly OperationTrace $trace)
    {
    }

    public function setEmail(string $email): void
    {
        $this->trace->record('set-email:'.$email);
        parent::setEmail($email);
    }

    public function setPendingEmail(?string $email): void
    {
        $this->trace->record('set-pending:'.($email ?? 'null'));
        parent::setPendingEmail($email);
    }
}

final class ProfileTestRepository implements \Te4g\UserBundle\Contract\AccountRepositoryInterface
{
    /** @var array<string, AccountUserInterface> */
    public array $indexedUsers = [];

    /** @var array<int|string, AccountUserInterface> */
    public array $indexedUsersById = [];

    /** @var list<string> */
    public array $lookedUpEmails = [];

    /** @var list<AccountUserInterface> */
    public array $saved = [];

    public function __construct(private readonly ?OperationTrace $trace = null)
    {
    }

    public function findOneByEmail(string $email): ?AccountUserInterface
    {
        $this->trace?->record('lookup:'.$email);
        $this->lookedUpEmails[] = $email;

        return $this->indexedUsers[$email] ?? null;
    }

    public function findById(int|string $id): ?AccountUserInterface
    {
        $this->trace?->record('load:'.$id);

        return $this->indexedUsersById[(string) $id] ?? null;
    }

    public function save(AccountUserInterface $user): void
    {
        $this->trace?->record('save');
        $this->saved[] = $user;
    }

    public function remove(AccountUserInterface $user): void
    {
        throw new \LogicException('Profile changes do not remove accounts.');
    }

    public function resetRecords(): void
    {
        $this->lookedUpEmails = [];
        $this->saved = [];
    }
}

final class ProfileTestPasswordHasher implements UserPasswordHasherInterface
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
        throw new \LogicException('Profile changes do not hash passwords.');
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

final class ProfileTestVerifyEmailHelper implements VerifyEmailHelperInterface
{
    /** @var list<array{route: string, userId: string, email: string, extraParams: array<string, string>}> */
    public array $generatedSignatures = [];

    /** @var list<array{signedUri: string, userId: string, email: string}> */
    public array $validationAttempts = [];

    public function __construct(
        private readonly ?OperationTrace $trace = null,
        private readonly bool $throwOnValidation = false,
    ) {
    }

    /** @param array<string, string> $extraParams */
    public function generateSignature(string $routeName, string $userId, string $userEmail, array $extraParams = []): VerifyEmailSignatureComponents
    {
        $this->trace?->record('sign:'.$userEmail);
        $this->generatedSignatures[] = [
            'route' => $routeName,
            'userId' => $userId,
            'email' => $userEmail,
            'extraParams' => $extraParams,
        ];

        return new VerifyEmailSignatureComponents(new \DateTimeImmutable('+1 hour'), 'https://example.test/email-change/'.$userId, time());
    }

    public function validateEmailConfirmation(string $signedUrl, string $userId, string $userEmail): void
    {
        $this->trace?->record('validate-signature:'.$userEmail);
        $this->validationAttempts[] = [
            'signedUri' => $signedUrl,
            'userId' => $userId,
            'email' => $userEmail,
        ];

        if ($this->throwOnValidation) {
            throw new InvalidSignatureException();
        }
    }
}

final class ProfileTestMailer implements \Te4g\UserBundle\Contract\AccountMailerInterface
{
    /** @var list<array{user: AccountUserInterface, absoluteUrl: string}> */
    public array $messages = [];

    public function __construct(private readonly ?OperationTrace $trace = null)
    {
    }

    public function sendVerification(AccountUserInterface $user, string $absoluteUrl): void
    {
        throw new \LogicException('Profile changes do not send verification messages.');
    }

    public function sendMagicLink(AccountUserInterface $user, string $absoluteUrl): void
    {
        throw new \LogicException('Profile changes do not send magic links.');
    }

    public function sendPasswordReset(AccountUserInterface $user, string $absoluteUrl): void
    {
        throw new \LogicException('Profile changes do not send password reset messages.');
    }

    public function sendEmailChange(AccountUserInterface $user, string $absoluteUrl): void
    {
        $this->trace?->record('mail');
        $this->messages[] = ['user' => $user, 'absoluteUrl' => $absoluteUrl];
    }
}

final class ProfileTestResetRepository implements ResetPasswordRequestRepositoryInterface
{
    /** @var list<object> */
    public array $removedFor = [];

    public function __construct(private readonly ?OperationTrace $trace = null)
    {
    }

    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): ResetPasswordRequestInterface
    {
        throw new \LogicException('Profile changes do not create reset requests.');
    }

    public function getUserIdentifier(object $user): string
    {
        throw new \LogicException('Profile changes do not resolve reset identifiers.');
    }

    public function persistResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        throw new \LogicException('Profile changes do not persist reset requests.');
    }

    public function findResetPasswordRequest(string $selector): ?ResetPasswordRequestInterface
    {
        throw new \LogicException('Profile changes do not find reset requests.');
    }

    public function getMostRecentNonExpiredRequestDate(object $user): ?\DateTimeInterface
    {
        throw new \LogicException('Profile changes do not inspect reset requests.');
    }

    public function removeResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        throw new \LogicException('Profile changes remove requests by account.');
    }

    public function removeExpiredResetPasswordRequests(): int
    {
        throw new \LogicException('Profile changes do not clean expired reset requests.');
    }

    public function removeRequests(object $user): void
    {
        $this->trace?->record('remove-account-resets');
        $this->removedFor[] = $user;
    }
}

final class ProfileTestEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function __construct(private readonly ?OperationTrace $trace = null)
    {
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        if ($event instanceof EmailChangeRequestedEvent) {
            $this->trace?->record('event:requested');
        }

        if ($event instanceof EmailChangedEvent) {
            $this->trace?->record('event:changed');
        }

        $this->events[] = $event;

        return $event;
    }
}
