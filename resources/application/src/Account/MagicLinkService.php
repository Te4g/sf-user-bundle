<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Mailer\AccountMailer;
use App\Repository\UserRepository;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

final readonly class MagicLinkService
{
    public function __construct(
        private UserRepository $users,
        private EmailNormalizer $normalizer,
        private EmailVerificationService $verification,
        private LoginLinkHandlerInterface $loginLinkHandler,
        private AccountMailer $mailer,
    ) {
    }

    public function send(string $email): void
    {
        $user = $this->users->findOneByNormalizedEmail($this->normalizer->normalize($email));
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isVerified()) {
            $this->verification->send($user);

            return;
        }

        $loginLink = $this->loginLinkHandler->createLoginLink($user);
        $this->mailer->sendMagicLink($user, $loginLink->getUrl());
    }
}
