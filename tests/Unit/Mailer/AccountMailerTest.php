<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Mailer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Translation\IdentityTranslator;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Mailer\AccountMailer;
use Te4g\UserBundle\Tests\Fixtures\Domain\TestAccountUser;

final class AccountMailerTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string}> */
    public static function messages(): iterable
    {
        yield 'verification' => ['sendVerification', 'verification', 'email.verification.subject', '@mail/verify.html.twig'];
        yield 'magic link' => ['sendMagicLink', 'magic_link', 'email.magic_link.subject', '@mail/magic.html.twig'];
        yield 'password reset' => ['sendPasswordReset', 'password_reset', 'email.password_reset.subject', '@mail/reset.html.twig'];
        yield 'pending email' => ['sendEmailChange', 'email_change', 'email.email_change.subject', '@mail/change.html.twig'];
    }

    #[DataProvider('messages')]
    public function testSendsAllFourTypedMessagesWithMinimalContext(
        string $method,
        string $type,
        string $subject,
        string $template,
    ): void {
        $transport = new RecordingMailer();
        $logger = new RecordingLogger();
        $mailer = new AccountMailer($transport, $logger, new IdentityTranslator(), self::options());
        $user = new TestAccountUser();
        $user->setEmail('user@example.test');
        $user->setPendingEmail('pending@example.test');
        $url = 'https://example.test/action?token=secret';

        $mailer->{$method}($user, $url);

        self::assertCount(1, $transport->messages);
        $email = $transport->messages[0];
        self::assertSame($subject, $email->getSubject());
        self::assertSame($template, $email->getHtmlTemplate());
        self::assertSame(['absolute_url' => $url], $email->getContext());
        self::assertSame(
            'email_change' === $type ? 'pending@example.test' : 'user@example.test',
            $email->getTo()[0]->getAddress(),
        );
        self::assertSame('accounts@example.test', $email->getFrom()[0]->getAddress());
        self::assertSame('Accounts', $email->getFrom()[0]->getName());
        self::assertSame([], $logger->records);
    }

    #[DataProvider('messages')]
    public function testTransportFailureLogsOnlyMessageType(
        string $method,
        string $type,
        string $subject,
        string $template,
    ): void {
        $transport = new RecordingMailer(fail: true);
        $logger = new RecordingLogger();
        $mailer = new AccountMailer($transport, $logger, new IdentityTranslator(), self::options());
        $user = new TestAccountUser();
        $user->setEmail('user@example.test');
        $user->setPendingEmail('pending@example.test');

        $mailer->{$method}($user, 'https://example.test/action?token=secret');

        self::assertSame([[
            'level' => 'error',
            'message' => 'Account email delivery failed.',
            'context' => ['message_type' => $type],
        ]], $logger->records);
        $encoded = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret', $encoded);
        self::assertStringNotContainsString('user@example.test', $encoded);
        self::assertStringNotContainsString('transport-secret', $encoded);
    }

    public function testPendingEmailMessageIsSkippedWhenNoChangeIsPending(): void
    {
        $transport = new RecordingMailer();
        $mailer = new AccountMailer($transport, new RecordingLogger(), new IdentityTranslator(), self::options());

        $mailer->sendEmailChange(new TestAccountUser(), 'https://example.test/change?token=secret');

        self::assertSame([], $transport->messages);
    }

    private static function options(): UserBundleOptions
    {
        return new UserBundleOptions(
            true,
            TestAccountUser::class,
            'repository',
            'accounts@example.test',
            'Accounts',
            [],
            [
                'verification_email' => '@mail/verify.html.twig',
                'magic_link_email' => '@mail/magic.html.twig',
                'password_reset_email' => '@mail/reset.html.twig',
                'email_change_email' => '@mail/change.html.twig',
            ],
            true,
        );
    }
}

final class RecordingMailer implements MailerInterface
{
    /** @var list<TemplatedEmail> */
    public array $messages = [];

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($this->fail) {
            throw new TransportException('transport-secret');
        }

        if (!$message instanceof TemplatedEmail) {
            throw new \LogicException('Expected a templated email.');
        }
        $this->messages[] = $message;
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
