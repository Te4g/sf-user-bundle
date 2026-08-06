<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class RegistrationService
{
    public function __construct(
        private UserRepository $users,
        private EmailNormalizer $normalizer,
        private UserPasswordHasherInterface $hasher,
        private EmailVerificationService $verification,
    ) {
    }

    public function register(string $name, string $email, #[\SensitiveParameter] string $plainPassword): ?User
    {
        $normalized = $this->normalizer->normalize($email);
        $existing = $this->users->findOneByNormalizedEmail($normalized);

        if ($existing instanceof User) {
            if (!$existing->isVerified()) {
                $this->verification->send($existing);
            }

            return null;
        }

        $user = (new User())
            ->setName(trim($name))
            ->setEmail($normalized)
        ;
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));

        try {
            $this->users->save($user);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $this->verification->send($user);

        return $user;
    }
}
