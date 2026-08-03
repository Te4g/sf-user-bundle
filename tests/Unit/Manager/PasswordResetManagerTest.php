<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Te4g\UserBundle\Event\PasswordChangedEvent;
use Te4g\UserBundle\Event\PasswordChangeOrigin;
use Te4g\UserBundle\Exception\PasswordResetFailed;
use Te4g\UserBundle\Manager\PasswordResetManager;
use Te4g\UserBundle\Tests\Fixtures\Domain\InMemoryAccountRepository;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordingAccountMailer;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;

final class PasswordResetManagerTest extends TestCase
{
    private InMemoryAccountRepository $repository;

    private ResetTestHelper $resetHelper;

    private ResetTestRequestRepository $resetRequestRepository;

    private ResetTestPasswordHasher $passwordHasher;

    private ResetTestUrlGenerator $urlGenerator;

    private RecordingAccountMailer $mailer;

    private ResetTestEventDispatcher $events;

    protected function setUp(): void
    {
        $this->repository = new InMemoryAccountRepository();
        $this->resetHelper = new ResetTestHelper();
        $this->resetRequestRepository = new ResetTestRequestRepository();
        $this->passwordHasher = new ResetTestPasswordHasher();
        $this->urlGenerator = new ResetTestUrlGenerator();
        $this->mailer = new RecordingAccountMailer();
        $this->events = new ResetTestEventDispatcher();
    }

    public function testUnknownResetRequestDoesNotGenerateOrSendAndDoesNotThrow(): void
    {
        $this->manager()->request('missing@example.com');

        self::assertSame([], $this->resetHelper->generatedFor);
        self::assertSame([], $this->urlGenerator->calls);
        self::assertSame([], $this->mailer->messages);
    }

    public function testKnownResetRequestGeneratesAnAbsoluteTokenUrlAndMailsIt(): void
    {
        $user = $this->user('user@example.com');
        $this->repository->save($user);

        $this->manager()->request(' USER@EXAMPLE.COM ');

        self::assertSame([$user], $this->resetHelper->generatedFor);
        self::assertSame([[
            'route' => 'te4g_user_password_reset',
            'parameters' => ['token' => 'native-reset-token'],
            'referenceType' => UrlGeneratorInterface::ABSOLUTE_URL,
        ]], $this->urlGenerator->calls);
        self::assertSame([[
            'type' => 'password_reset',
            'user' => $user,
            'absoluteUrl' => 'https://example.test/reset-password/native-reset-token',
        ]], $this->mailer->messages);
    }

    public function testResetHashesSavesInvalidatesEveryRequestAndDispatchesResetEvent(): void
    {
        $user = $this->user('user@example.com');
        $this->resetHelper->resolvedUsers['valid-token'] = $user;

        $resolved = $this->manager()->reset('valid-token', 'new secure password');

        self::assertSame($user, $resolved);
        self::assertSame('NEW_PASSWORD_HASH', $user->getPassword());
        self::assertSame([[$user, 'new secure password']], $this->passwordHasher->hashedPasswords);
        self::assertSame([$user], $this->repository->saved);
        self::assertSame(['valid-token'], $this->resetHelper->removedTokens);
        self::assertSame([$user], $this->resetRequestRepository->removedFor);
        self::assertCount(1, $this->events->events);
        $event = $this->events->events[0];
        self::assertInstanceOf(PasswordChangedEvent::class, $event);
        self::assertSame($user, $event->getUser());
        self::assertSame(PasswordChangeOrigin::Reset, $event->getOrigin());
        self::assertStringNotContainsString('valid-token', serialize($event));
        self::assertStringNotContainsString('new secure password', serialize($event));
    }

    public function testInvalidResetTokenIsTranslatedWithoutSecretOrSideEffects(): void
    {
        $this->resetHelper->validationFailure = new ResetTestSensitiveException('invalid secret-token-value');

        try {
            $this->manager()->reset('secret-token-value', 'new secret password');
            self::fail('An invalid reset token must fail.');
        } catch (PasswordResetFailed $exception) {
            self::assertStringNotContainsString('secret-token-value', $exception->getMessage());
            self::assertStringNotContainsString('new secret password', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertSame([], $this->passwordHasher->hashedPasswords);
        self::assertSame([], $this->repository->saved);
        self::assertSame([], $this->resetHelper->removedTokens);
        self::assertSame([], $this->resetRequestRepository->removedFor);
        self::assertSame([], $this->events->events);
    }

    public function testResetRejectsAResolvedObjectOutsideTheAccountContract(): void
    {
        $this->resetHelper->resolvedUsers['valid-token'] = new \stdClass();

        $this->expectException(PasswordResetFailed::class);

        try {
            $this->manager()->reset('valid-token', 'new secret password');
        } finally {
            self::assertSame([], $this->passwordHasher->hashedPasswords);
            self::assertSame([], $this->repository->saved);
            self::assertSame([], $this->resetHelper->removedTokens);
            self::assertSame([], $this->resetRequestRepository->removedFor);
            self::assertSame([], $this->events->events);
        }
    }

    public function testResetTokenGenerationFailureIsTranslatedWithoutLeakingUpstreamDetails(): void
    {
        $user = $this->user('user@example.com');
        $this->repository->save($user);
        $this->resetHelper->generationFailure = new ResetTestSensitiveException('throttled native-reset-token');

        try {
            $this->manager()->request('user@example.com');
            self::fail('An upstream reset-token failure must be translated.');
        } catch (PasswordResetFailed $exception) {
            self::assertStringNotContainsString('native-reset-token', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertSame([], $this->urlGenerator->calls);
        self::assertSame([], $this->mailer->messages);
    }

    private function manager(): PasswordResetManager
    {
        return new PasswordResetManager(
            $this->repository,
            $this->resetHelper,
            $this->resetRequestRepository,
            $this->passwordHasher,
            $this->urlGenerator,
            $this->mailer,
            $this->events,
        );
    }

    private function user(string $email): TestAccountUser
    {
        $user = new TestAccountUser();
        $user->setEmail($email);

        return $user;
    }
}

final class ResetTestHelper implements ResetPasswordHelperInterface
{
    /** @var list<object> */
    public array $generatedFor = [];

    /** @var array<string, object> */
    public array $resolvedUsers = [];

    /** @var list<string> */
    public array $removedTokens = [];

    public ?ResetPasswordExceptionInterface $generationFailure = null;

    public ?ResetPasswordExceptionInterface $validationFailure = null;

    public function generateResetToken(object $user): ResetPasswordToken
    {
        $this->generatedFor[] = $user;

        if (null !== $this->generationFailure) {
            throw $this->generationFailure;
        }

        return new ResetPasswordToken('native-reset-token', new \DateTimeImmutable('+1 hour'), time());
    }

    public function validateTokenAndFetchUser(string $fullToken): object
    {
        if (null !== $this->validationFailure) {
            throw $this->validationFailure;
        }

        return $this->resolvedUsers[$fullToken] ?? new \stdClass();
    }

    public function removeResetRequest(string $fullToken): void
    {
        $this->removedTokens[] = $fullToken;
    }

    public function getTokenLifetime(): int
    {
        return 3600;
    }
}

final class ResetTestRequestRepository implements ResetPasswordRequestRepositoryInterface
{
    /** @var list<object> */
    public array $removedFor = [];

    public function createResetPasswordRequest(object $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken): ResetPasswordRequestInterface
    {
        throw new \LogicException('The manager does not create request records directly.');
    }

    public function getUserIdentifier(object $user): string
    {
        throw new \LogicException('The manager does not resolve reset user identifiers.');
    }

    public function persistResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        throw new \LogicException('The manager delegates persistence to the reset helper.');
    }

    public function findResetPasswordRequest(string $selector): ?ResetPasswordRequestInterface
    {
        throw new \LogicException('The manager delegates validation to the reset helper.');
    }

    public function getMostRecentNonExpiredRequestDate(object $user): ?\DateTimeInterface
    {
        throw new \LogicException('The manager delegates throttling to the reset helper.');
    }

    public function removeResetPasswordRequest(ResetPasswordRequestInterface $resetPasswordRequest): void
    {
        throw new \LogicException('The manager removes requests by account.');
    }

    public function removeExpiredResetPasswordRequests(): int
    {
        throw new \LogicException('The manager does not clean expired requests.');
    }

    public function removeRequests(object $user): void
    {
        $this->removedFor[] = $user;
    }
}

final class ResetTestPasswordHasher implements UserPasswordHasherInterface
{
    /** @var list<array{PasswordAuthenticatedUserInterface, string}> */
    public array $hashedPasswords = [];

    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): string
    {
        $this->hashedPasswords[] = [$user, $plainPassword];

        return 'NEW_PASSWORD_HASH';
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): bool
    {
        throw new \LogicException('Password reset does not validate the current password.');
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        return false;
    }
}

final class ResetTestUrlGenerator implements UrlGeneratorInterface
{
    /** @var list<array{route: string, parameters: array<string, string>, referenceType: int}> */
    public array $calls = [];

    private RequestContext $context;

    public function __construct()
    {
        $this->context = new RequestContext();
    }

    public function setContext(RequestContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }

    /** @param array<string, string> $parameters */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        $this->calls[] = [
            'route' => $name,
            'parameters' => $parameters,
            'referenceType' => $referenceType,
        ];

        return 'https://example.test/reset-password/'.$parameters['token'];
    }
}

final class ResetTestEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->events[] = $event;

        return $event;
    }
}

final class ResetTestSensitiveException extends \RuntimeException implements ResetPasswordExceptionInterface
{
    public function getReason(): string
    {
        return $this->getMessage();
    }
}
