<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final readonly class AccountMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private string $fromEmail = 'no-reply@example.com',
        private string $fromName = 'Application',
    ) {
    }

    public function sendVerification(User $user, string $absoluteUrl): void
    {
        $this->send($user->getEmail(), 'Verify your email address', 'email/verify.html.twig', $absoluteUrl);
    }

    public function sendMagicLink(User $user, string $absoluteUrl): void
    {
        $this->send($user->getEmail(), 'Your sign-in link', 'email/magic_link.html.twig', $absoluteUrl);
    }

    public function sendPasswordReset(User $user, string $absoluteUrl): void
    {
        $this->send($user->getEmail(), 'Reset your password', 'email/password_reset.html.twig', $absoluteUrl);
    }

    public function sendEmailChange(User $user, string $absoluteUrl): void
    {
        $pendingEmail = $user->getPendingEmail();
        if (null === $pendingEmail) {
            return;
        }

        $this->send($pendingEmail, 'Confirm your new email address', 'email/email_change.html.twig', $absoluteUrl);
    }

    private function send(string $recipient, string $subject, string $template, string $absoluteUrl): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($recipient)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context(['absolute_url' => $absoluteUrl])
        ;

        $this->mailer->send($email);
    }
}
