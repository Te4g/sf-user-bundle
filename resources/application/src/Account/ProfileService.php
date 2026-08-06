<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Mailer\AccountMailer;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
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
        private EntityManagerInterface $entityManager,
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
        Request $request,
        int $userId,
    ): User {
        $user = $this->users->find($userId);
        $pendingEmail = $user?->getPendingEmail();
        if (!$user instanceof User || null === $pendingEmail) {
            throw new \RuntimeException('Account update failed.');
        }

        try {
            $this->verifyEmailHelper->validateEmailConfirmationFromRequest($request, (string) $userId, $pendingEmail);
        } catch (VerifyEmailExceptionInterface) {
            throw new \RuntimeException('Account update failed.');
        }

        $existing = $this->users->findOneByNormalizedEmail($pendingEmail);
        if ($existing instanceof User && $existing !== $user) {
            throw new \RuntimeException('Account update failed.');
        }

        try {
            $promoted = $this->entityManager->wrapInTransaction(function () use ($user, $userId, $pendingEmail): bool {
                if (!$this->users->promotePendingEmail($userId, $pendingEmail)) {
                    return false;
                }

                $this->resetRequests->removeRequests($user);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            throw new \RuntimeException('Account update failed.');
        }

        if (!$promoted) {
            throw new \RuntimeException('Account update failed.');
        }

        $this->entityManager->refresh($user);

        return $user;
    }
}
