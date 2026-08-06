<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Mailer\AccountMailer;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final readonly class ProfileService
{
    public function __construct(
        private UserRepository $users,
        private EmailNormalizer $normalizer,
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private AccountMailer $mailer,
        private ResetPasswordRequestRepository $resetRequests,
    ) {
    }

    public function updateName(User $user, string $name): void
    {
        $user->setName(trim($name));
        $this->users->save($user);
    }

    public function requestEmailChange(
        User $user,
        #[\SensitiveParameter] string $newEmail,
    ): void {
        $normalized = $this->normalizer->normalize($newEmail);
        if ($normalized === $user->getEmail()) {
            return;
        }

        if ($this->users->findOneByNormalizedEmail($normalized) instanceof User) {
            throw new \RuntimeException('Account update failed.');
        }

        $id = $user->getId();
        if (null === $id) {
            throw new \RuntimeException('Account update failed.');
        }

        $user->setPendingEmail($normalized);
        $this->users->save($user);

        $signature = $this->verifyEmailHelper->generateSignature(
            'app_account_email_verify',
            (string) $id,
            $normalized,
            ['id' => $id],
        );
        $this->mailer->sendEmailChange($user, $signature->getSignedUrl());
    }

    public function confirmEmailChange(
        #[\SensitiveParameter] string $signedUri,
        int $userId,
    ): User {
        $user = $this->users->find($userId);
        $pendingEmail = $user?->getPendingEmail();
        if (!$user instanceof User || null === $pendingEmail) {
            throw new \RuntimeException('Account update failed.');
        }

        try {
            $this->verifyEmailHelper->validateEmailConfirmation($signedUri, (string) $userId, $pendingEmail);
        } catch (VerifyEmailExceptionInterface) {
            throw new \RuntimeException('Account update failed.');
        }

        $existing = $this->users->findOneByNormalizedEmail($pendingEmail);
        if ($existing instanceof User && $existing !== $user) {
            throw new \RuntimeException('Account update failed.');
        }

        $activeEmail = $user->getEmail();
        $user->setEmail($pendingEmail);
        $user->setPendingEmail(null);

        try {
            $this->users->save($user);
        } catch (UniqueConstraintViolationException) {
            $user->setEmail($activeEmail);
            $user->setPendingEmail($pendingEmail);

            throw new \RuntimeException('Account update failed.');
        }

        $this->resetRequests->removeRequests($user);

        return $user;
    }
}
