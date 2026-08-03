<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Contract;

interface AccountMailerInterface
{
    public function sendVerification(AccountUserInterface $user, string $absoluteUrl): void;

    public function sendMagicLink(AccountUserInterface $user, string $absoluteUrl): void;

    public function sendPasswordReset(AccountUserInterface $user, string $absoluteUrl): void;

    public function sendEmailChange(AccountUserInterface $user, string $absoluteUrl): void;
}
