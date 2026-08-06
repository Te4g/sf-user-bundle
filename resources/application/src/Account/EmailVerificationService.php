<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Mailer\AccountMailer;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final readonly class EmailVerificationService
{
    public function __construct(
        private UserRepository $users,
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private AccountMailer $mailer,
    ) {
    }

    public function send(User $user): void
    {
        $id = $user->getId();
        if (null === $id) {
            throw new \LogicException('Verification requires a persisted user.');
        }

        $signature = $this->verifyEmailHelper->generateSignature(
            'app_verify_email',
            (string) $id,
            $user->getEmail(),
            ['id' => $id],
        );

        $this->mailer->sendVerification($user, $signature->getSignedUrl());
    }

    public function verify(Request $request, int $userId): User
    {
        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            throw new \RuntimeException('Email verification failed.');
        }

        try {
            $this->verifyEmailHelper->validateEmailConfirmationFromRequest($request, (string) $userId, $user->getEmail());
        } catch (VerifyEmailExceptionInterface) {
            throw new \RuntimeException('Email verification failed.');
        }

        if (!$user->isVerified()) {
            $user->setVerified(true);
            $this->users->save($user);
        }

        return $user;
    }
}
