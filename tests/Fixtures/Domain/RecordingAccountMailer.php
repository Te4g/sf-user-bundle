<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\Domain;

use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;

final class RecordingAccountMailer implements AccountMailerInterface
{
    /** @var list<array{type: 'verification'|'magic_link'|'password_reset'|'email_change', user: AccountUserInterface, absoluteUrl: string}> */
    public array $messages = [];

    public function sendVerification(AccountUserInterface $user, string $absoluteUrl): void
    {
        $this->record('verification', $user, $absoluteUrl);
    }

    public function sendMagicLink(AccountUserInterface $user, string $absoluteUrl): void
    {
        $this->record('magic_link', $user, $absoluteUrl);
    }

    public function sendPasswordReset(AccountUserInterface $user, string $absoluteUrl): void
    {
        $this->record('password_reset', $user, $absoluteUrl);
    }

    public function sendEmailChange(AccountUserInterface $user, string $absoluteUrl): void
    {
        $this->record('email_change', $user, $absoluteUrl);
    }

    /** @param 'verification'|'magic_link'|'password_reset'|'email_change' $type */
    private function record(string $type, AccountUserInterface $user, string $absoluteUrl): void
    {
        $this->messages[] = [
            'type' => $type,
            'user' => $user,
            'absoluteUrl' => $absoluteUrl,
        ];
    }
}
