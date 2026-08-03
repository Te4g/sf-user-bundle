<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Manager;

use PHPUnit\Framework\TestCase;
use SymfonyCasts\Bundle\VerifyEmail\Exception\InvalidSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Te4g\UserBundle\Event\AccountVerifiedEvent;
use Te4g\UserBundle\Exception\VerificationFailed;
use Te4g\UserBundle\Manager\EmailVerificationManager;
use Te4g\UserBundle\Tests\Fixtures\Domain\InMemoryAccountRepository;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordingAccountMailer;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;

final class EmailVerificationManagerTest extends TestCase
{
    public function testSendGeneratesRouteBoundToTheCurrentEmailAndMailsTheSignedUrl(): void
    {
        $repository = new InMemoryAccountRepository();
        $mailer = new RecordingAccountMailer();
        $helper = new SigningVerifyEmailHelper();
        $events = new RecordedEvents();
        $user = $this->user(9, 'user@example.com');

        (new EmailVerificationManager($repository, $helper, $mailer, $events))->send($user);

        self::assertSame([[
            'route' => 'te4g_user_verify_email',
            'userId' => '9',
            'email' => 'user@example.com',
            'extraParams' => ['id' => '9'],
        ]], $helper->generatedSignatures);
        self::assertSame([[
            'type' => 'verification',
            'user' => $user,
            'absoluteUrl' => 'https://example.test/verify/9',
        ]], $mailer->messages);
    }

    public function testVerifyMarksTheLoadedUserVerifiedSavesItAndDispatchesEvent(): void
    {
        $repository = new InMemoryAccountRepository();
        $user = $this->user(9, 'user@example.com');
        $repository->save($user);
        $events = new RecordedEvents();

        $verified = (new EmailVerificationManager($repository, new SigningVerifyEmailHelper(), new RecordingAccountMailer(), $events))
            ->verify('https://example.test/verify/9?token=secret', '9');

        self::assertSame($user, $verified);
        self::assertTrue($user->isVerified());
        self::assertCount(2, $repository->saved);
        self::assertSame([$user], $events->eventsOf(AccountVerifiedEvent::class));
    }

    public function testVerifyRejectsAnUnknownAccountWithoutDisclosingTheSignedUri(): void
    {
        $manager = new EmailVerificationManager(
            new InMemoryAccountRepository(),
            new SigningVerifyEmailHelper(),
            new RecordingAccountMailer(),
            new RecordedEvents(),
        );

        try {
            $manager->verify('https://example.test/verify/404?token=secret', 404);
            self::fail('An unknown account must not be verified.');
        } catch (VerificationFailed $exception) {
            self::assertStringNotContainsString('token=secret', $exception->getMessage());
        }
    }

    public function testVerifyTurnsAnInvalidSignatureIntoGenericVerificationFailure(): void
    {
        $repository = new InMemoryAccountRepository();
        $repository->save($this->user(9, 'user@example.com'));
        $manager = new EmailVerificationManager(
            $repository,
            new SigningVerifyEmailHelper(throwOnValidation: true),
            new RecordingAccountMailer(),
            new RecordedEvents(),
        );

        try {
            $manager->verify('https://example.test/verify/9?token=secret', 9);
            self::fail('An invalid signature must not verify the account.');
        } catch (VerificationFailed $exception) {
            self::assertStringNotContainsString('token=secret', $exception->getMessage());
        }
    }

    private function user(int|string $id, string $email): TestAccountUser
    {
        $user = new TestAccountUser();
        $user->setId($id);
        $user->setEmail($email);

        return $user;
    }
}

final class SigningVerifyEmailHelper implements VerifyEmailHelperInterface
{
    /** @var list<array{route: string, userId: string, email: string, extraParams: array<string, string>}> */
    public array $generatedSignatures = [];

    public function __construct(private readonly bool $throwOnValidation = false)
    {
    }

    /** @param array<string, string> $extraParams */
    public function generateSignature(string $routeName, string $userId, string $userEmail, array $extraParams = []): VerifyEmailSignatureComponents
    {
        $this->generatedSignatures[] = [
            'route' => $routeName,
            'userId' => $userId,
            'email' => $userEmail,
            'extraParams' => $extraParams,
        ];

        return new VerifyEmailSignatureComponents(new \DateTimeImmutable('+1 hour'), 'https://example.test/verify/'.$userId, time());
    }

    public function validateEmailConfirmation(string $signedUrl, string $userId, string $userEmail): void
    {
        if ($this->throwOnValidation) {
            throw new InvalidSignatureException();
        }
    }
}
