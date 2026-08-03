<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Util\EmailNormalizer;

/** @implements UserProviderInterface<AccountUserInterface> */
final readonly class AccountUserProvider implements PasswordUpgraderInterface, UserProviderInterface
{
    /** @param class-string $userClass */
    public function __construct(
        private AccountRepositoryInterface $repository,
        private EmailNormalizer $emailNormalizer,
        private string $userClass,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): AccountUserInterface
    {
        $normalizedIdentifier = $this->emailNormalizer->normalize($identifier);
        $user = $this->repository->findOneByEmail($normalizedIdentifier);

        if (null === $user) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($normalizedIdentifier);

            throw $exception;
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): AccountUserInterface
    {
        if (!$user instanceof AccountUserInterface || null === $user->getId() || !$this->supportsClass($user::class)) {
            throw new UnsupportedUserException();
        }

        $refreshedUser = $this->repository->findById($user->getId());

        if (null === $refreshedUser) {
            throw new UserNotFoundException();
        }

        return $refreshedUser;
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, $this->userClass, true) && is_a($class, AccountUserInterface::class, true);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof AccountUserInterface || !$this->supportsClass($user::class)) {
            throw new UnsupportedUserException();
        }

        $user->setPassword($newHashedPassword);
        $this->repository->save($user);
    }
}
