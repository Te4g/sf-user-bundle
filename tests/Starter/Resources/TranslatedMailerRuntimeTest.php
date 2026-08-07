<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Resources;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;

final class TranslatedMailerRuntimeTest extends TestCase
{
    public function testVerificationEmailUsesTheRecipientsFrenchLocale(): void
    {
        $this->requireApplicationClasses();
        $translator = new Translator('en');
        $translator->addLoader('xlf', new XliffFileLoader());
        $translator->addResource('xlf', $this->applicationResources().'/translations/messages.fr.xlf', 'fr');
        $mailer = new TranslatedMailerRecordingMailer();
        $accountMailer = $this->newApplicationInstance('Mailer\\AccountMailer', $mailer, $translator);
        $user = $this->newApplicationInstance('Entity\\User');
        (new \ReflectionMethod($user, 'setEmail'))->invoke($user, 'recipient@example.com');
        (new \ReflectionMethod($user, 'setLocale'))->invoke($user, 'fr');

        (new \ReflectionMethod($accountMailer, 'sendVerification'))
            ->invoke($accountMailer, $user, 'https://example.test/verify');

        $email = $mailer->message();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('Vérifiez votre adresse e-mail', $email->getSubject());
        self::assertSame('fr', $email->getLocale());
        self::assertSame('email/verify.html.twig', $email->getHtmlTemplate());
        self::assertSame(['absolute_url' => 'https://example.test/verify'], $email->getContext());
    }

    private function requireApplicationClasses(): void
    {
        require_once $this->applicationResources().'/src/Entity/User.php';
        require_once $this->applicationResources().'/src/Mailer/AccountMailer.php';
    }

    private function newApplicationInstance(string $suffix, mixed ...$arguments): object
    {
        return (new \ReflectionClass($this->applicationClass($suffix)))->newInstance(...$arguments);
    }

    /** @return class-string */
    private function applicationClass(string $suffix): string
    {
        $class = 'App\\'.$suffix;
        if (!class_exists($class)) {
            throw new \LogicException(sprintf('Application class "%s" was not loaded.', $class));
        }

        return $class;
    }

    private function applicationResources(): string
    {
        return dirname(__DIR__, 3).'/resources/application';
    }
}

final class TranslatedMailerRecordingMailer implements MailerInterface
{
    private ?RawMessage $message = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->message = $message;
    }

    public function message(): RawMessage
    {
        if (!$this->message instanceof RawMessage) {
            throw new \LogicException('No email was sent.');
        }

        return $this->message;
    }
}
