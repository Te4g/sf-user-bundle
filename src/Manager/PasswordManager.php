<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Manager;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Event\PasswordChangedEvent;
use Te4g\UserBundle\Event\PasswordChangeOrigin;
use Te4g\UserBundle\Exception\CurrentPasswordInvalid;

final readonly class PasswordManager
{
    public function __construct(
        private AccountRepositoryInterface $repository,
        private ResetPasswordRequestRepositoryInterface $resetPasswordRequestRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function change(
        AccountUserInterface $user,
        #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword,
    ): void {
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new CurrentPasswordInvalid();
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->repository->save($user);
        $this->resetPasswordRequestRepository->removeRequests($user);
        $this->eventDispatcher->dispatch(new PasswordChangedEvent($user, PasswordChangeOrigin::Authenticated));
    }
}
