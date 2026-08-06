<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class PasswordService
{
    public function __construct(
        private UserRepository $users,
        private ResetPasswordRequestRepository $resetRequests,
        private UserPasswordHasherInterface $hasher,
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

        $user->setPassword($this->hasher->hashPassword($user, $newPassword));
        $this->users->save($user);
        $this->resetRequests->removeRequests($user);
    }
}
