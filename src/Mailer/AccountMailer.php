<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Mailer;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;
use Te4g\UserBundle\Configuration\UserBundleOptions;
use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;

final readonly class AccountMailer implements AccountMailerInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private UserBundleOptions $options,
    ) {
    }

    public function sendVerification(AccountUserInterface $user, string $absoluteUrl): void
    {
        $this->send(
            'verification',
            $user->getEmail(),
            'email.verification.subject',
            $this->options->templates['verification_email'],
            $absoluteUrl,
        );
    }

    public function sendMagicLink(AccountUserInterface $user, string $absoluteUrl): void
    {
        $this->send(
            'magic_link',
            $user->getEmail(),
            'email.magic_link.subject',
            $this->options->templates['magic_link_email'],
            $absoluteUrl,
        );
    }

    public function sendPasswordReset(AccountUserInterface $user, string $absoluteUrl): void
    {
        $this->send(
            'password_reset',
            $user->getEmail(),
            'email.password_reset.subject',
            $this->options->templates['password_reset_email'],
            $absoluteUrl,
        );
    }

    public function sendEmailChange(AccountUserInterface $user, string $absoluteUrl): void
    {
        $pendingEmail = $user->getPendingEmail();

        if (null === $pendingEmail) {
            return;
        }

        $this->send(
            'email_change',
            $pendingEmail,
            'email.email_change.subject',
            $this->options->templates['email_change_email'],
            $absoluteUrl,
        );
    }

    private function send(
        string $messageType,
        string $recipient,
        string $subjectKey,
        string $template,
        string $absoluteUrl,
    ): void {
        $email = (new TemplatedEmail())
            ->from(new Address($this->options->fromEmail, $this->options->fromName))
            ->to($recipient)
            ->subject($this->translator->trans($subjectKey, domain: 'Te4gUserBundle'))
            ->htmlTemplate($template)
            ->context(['absolute_url' => $absoluteUrl])
        ;

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface) {
            $this->logger->error('Account email delivery failed.', [
                'message_type' => $messageType,
            ]);
        }
    }
}
