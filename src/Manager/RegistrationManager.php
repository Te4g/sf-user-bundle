<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Manager;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserFactoryInterface;
use Te4g\UserBundle\Event\AccountRegisteredEvent;
use Te4g\UserBundle\Util\EmailNormalizer;

final readonly class RegistrationManager
{
    public function __construct(
        private AccountRepositoryInterface $repository,
        private AccountUserFactoryInterface $userFactory,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailVerificationManager $emailVerificationManager,
        private EventDispatcherInterface $eventDispatcher,
        private EmailNormalizer $emailNormalizer = new EmailNormalizer(),
    ) {
    }

    public function register(string $email, string $plainPassword): void
    {
        $normalizedEmail = $this->emailNormalizer->normalize($email);
        $existingUser = $this->repository->findOneByEmail($normalizedEmail);

        if (null !== $existingUser) {
            if (!$existingUser->isVerified()) {
                $this->emailVerificationManager->send($existingUser);
            }

            return;
        }

        $user = $this->userFactory->create($normalizedEmail);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        try {
            $this->repository->save($user);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $this->eventDispatcher->dispatch(new AccountRegisteredEvent($user));
        $this->emailVerificationManager->send($user);
    }
}
