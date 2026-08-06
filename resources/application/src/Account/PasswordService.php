<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class PasswordService
{
    public function __construct(
        private UserRepository $users,
        private ResetPasswordRequestRepository $resetRequests,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function change(
        User $user,
        #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword,
    ): void {
        if (!$this->hasher->isPasswordValid($user, $currentPassword)) {
            throw new \RuntimeException('Password change failed.');
        }

        $currentHash = $user->getPassword();
        $newHash = $this->hasher->hashPassword($user, $newPassword);

        try {
            $this->entityManager->wrapInTransaction(function () use ($user, $newHash): void {
                $user->setPassword($newHash);
                $this->users->save($user, false);
                $this->resetRequests->removeRequests($user);
            });
        } catch (\Throwable $failure) {
            $user->setPassword($currentHash);

            throw $failure;
        }
    }
}
