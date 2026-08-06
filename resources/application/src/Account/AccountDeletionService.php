<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class AccountDeletionService
{
    public function __construct(
        private UserRepository $users,
        private ResetPasswordRequestRepository $resetRequests,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function delete(User $user, #[\SensitiveParameter] string $currentPassword): void
    {
        if (!$this->hasher->isPasswordValid($user, $currentPassword)) {
            throw new \RuntimeException('Account deletion failed.');
        }

        $this->entityManager->wrapInTransaction(function () use ($user): void {
            $this->resetRequests->removeRequests($user);
            $this->users->remove($user, false);
        });
    }
}
