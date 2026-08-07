<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AccountMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private string $fromEmail = 'no-reply@example.com',
        private string $fromName = 'Application',
    ) {
    }

    public function sendVerification(User $user, string $absoluteUrl): void
    {
        $this->send(
            $user->getEmail(),
            $user->getLocale(),
            'email.verification.subject',
            'email/verify.html.twig',
            $absoluteUrl,
        );
    }

    public function sendMagicLink(User $user, string $absoluteUrl): void
    {
        $this->send(
            $user->getEmail(),
            $user->getLocale(),
            'email.magic_link.subject',
            'email/magic_link.html.twig',
            $absoluteUrl,
        );
    }

    public function sendPasswordReset(User $user, string $absoluteUrl): void
    {
        $this->send(
            $user->getEmail(),
            $user->getLocale(),
            'email.password_reset.subject',
            'email/password_reset.html.twig',
            $absoluteUrl,
        );
    }

    public function sendEmailChange(User $user, string $absoluteUrl): void
    {
        $pendingEmail = $user->getPendingEmail();
        if (null === $pendingEmail) {
            return;
        }

        $this->send(
            $pendingEmail,
            $user->getLocale(),
            'email.email_change.subject',
            'email/email_change.html.twig',
            $absoluteUrl,
        );
    }

    private function send(
        string $recipient,
        string $locale,
        string $subjectKey,
        string $template,
        string $absoluteUrl,
    ): void {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($recipient)
            ->subject($this->translator->trans($subjectKey, locale: $locale))
            ->htmlTemplate($template)
            ->locale($locale)
            ->context(['absolute_url' => $absoluteUrl])
        ;

        $this->mailer->send($email);
    }
}
