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
        $this->send($user, 'Verify your email address', 'email/verify.html.twig', $absoluteUrl);
    }

    public function sendMagicLink(User $user, string $absoluteUrl): void
    {
        $this->send($user, 'Your sign-in link', 'email/magic_link.html.twig', $absoluteUrl);
    }

    public function sendPasswordReset(User $user, string $absoluteUrl): void
    {
        $this->send($user, 'Reset your password', 'email/password_reset.html.twig', $absoluteUrl);
    }

    private function send(User $user, string $subject, string $template, string $absoluteUrl): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($user->getEmail())
            ->subject($subject)
            ->htmlTemplate($template)
            ->context(['absolute_url' => $absoluteUrl])
        ;

        $this->mailer->send($email);
    }
}
