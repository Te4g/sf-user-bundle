<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Manager;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkDetails;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Te4g\UserBundle\Manager\EmailVerificationManager;
use Te4g\UserBundle\Manager\MagicLinkManager;
use Te4g\UserBundle\Tests\Fixtures\Domain\InMemoryAccountRepository;
use Te4g\UserBundle\Tests\Fixtures\Domain\OperationTrace;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordedEvents;
use Te4g\UserBundle\Tests\Fixtures\Domain\RecordingAccountMailer;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;
use Te4g\UserBundle\Tests\Fixtures\Domain\TracingAccountRepository;
use Te4g\UserBundle\Util\EmailNormalizer;

final class MagicLinkManagerTest extends TestCase
{
    private InMemoryAccountRepository $repository;

    private RecordingAccountMailer $mailer;

    private RecordingLoginLinkHandler $loginLinkHandler;

    protected function setUp(): void
    {
        $this->repository = new InMemoryAccountRepository();
        $this->mailer = new RecordingAccountMailer();
        $this->loginLinkHandler = new RecordingLoginLinkHandler();
    }

    #[DataProvider('accountStates')]
    public function testMagicRequestNeverReturnsAccountExistence(
        ?TestAccountUser $user,
        int $expectedMagicMails,
        int $expectedVerificationMails,
        int $expectedLoginLinks,
    ): void {
        if (null !== $user) {
            $this->repository->save($user);
        }

        $this->manager()->request('user@example.com');

        self::assertCount($expectedMagicMails, $this->messagesOfType('magic_link'));
        self::assertCount($expectedVerificationMails, $this->messagesOfType('verification'));
        self::assertCount($expectedLoginLinks, $this->loginLinkHandler->createdFor);
        self::assertSame(1 === $expectedLoginLinks ? [$user] : [], $this->loginLinkHandler->createdFor);
    }

    /** @return iterable<string, array{?TestAccountUser, int, int, int}> */
    public static function accountStates(): iterable
    {
        yield 'unknown' => [null, 0, 0, 0];
        yield 'unverified' => [self::user(17, false), 0, 1, 0];
        yield 'verified' => [self::user(17, true), 1, 0, 1];
    }

    public function testRequestNormalizesBeforeLookup(): void
    {
        $trace = new OperationTrace();
        $repository = new TracingAccountRepository($trace);
        $manager = $this->manager($repository);

        $manager->request(' User@Example.COM ');

        self::assertSame(['lookup:user@example.com'], $trace->operations());
    }

    public function testVerifiedRequestMailsTheNativeLoginLinkUrl(): void
    {
        $user = self::user(17, true);
        $this->repository->save($user);

        $this->manager()->request('user@example.com');

        self::assertSame([$user], $this->loginLinkHandler->createdFor);
        self::assertSame([[
            'type' => 'magic_link',
            'user' => $user,
            'absoluteUrl' => 'https://example.test/login/check?token=native',
        ]], $this->mailer->messages);
    }

    private function manager(?TracingAccountRepository $repository = null): MagicLinkManager
    {
        $accountRepository = $repository ?? $this->repository;
        $verificationManager = new EmailVerificationManager(
            $accountRepository,
            new MagicLinkVerifyEmailHelper(),
            $this->mailer,
            new RecordedEvents(),
        );

        return new MagicLinkManager(
            $accountRepository,
            new EmailNormalizer(),
            $verificationManager,
            $this->loginLinkHandler,
            $this->mailer,
        );
    }

    /** @return list<array{type: 'verification'|'magic_link'|'password_reset'|'email_change', user: \Te4g\UserBundle\Contract\AccountUserInterface, absoluteUrl: string}> */
    private function messagesOfType(string $type): array
    {
        return array_values(array_filter(
            $this->mailer->messages,
            static fn (array $message): bool => $type === $message['type'],
        ));
    }

    private static function user(int|string $id, bool $verified): TestAccountUser
    {
        $user = new TestAccountUser();
        $user->setId($id);
        $user->setEmail('user@example.com');
        $user->setVerified($verified);

        return $user;
    }
}

final class RecordingLoginLinkHandler implements LoginLinkHandlerInterface
{
    /** @var list<UserInterface> */
    public array $createdFor = [];

    public function createLoginLink(UserInterface $user, ?Request $request = null, ?int $lifetime = null): LoginLinkDetails
    {
        $this->createdFor[] = $user;

        return new LoginLinkDetails(
            'https://example.test/login/check?token=native',
            new \DateTimeImmutable('+10 minutes'),
        );
    }

    public function consumeLoginLink(Request $request): UserInterface
    {
        throw new \LogicException('MagicLinkManager never consumes login links.');
    }
}

final class MagicLinkVerifyEmailHelper implements VerifyEmailHelperInterface
{
    /** @param array<string, string> $extraParams */
    public function generateSignature(string $routeName, string $userId, string $userEmail, array $extraParams = []): VerifyEmailSignatureComponents
    {
        return new VerifyEmailSignatureComponents(
            new \DateTimeImmutable('+1 hour'),
            'https://example.test/verify/'.$userId,
            time(),
        );
    }

    public function validateEmailConfirmation(string $signedUrl, string $userId, string $userEmail): void
    {
    }
}
