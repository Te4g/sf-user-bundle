<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Manager;

use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Te4g\UserBundle\Contract\AccountDeletionGuardInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Event\AfterAccountDeletedEvent;
use Te4g\UserBundle\Event\BeforeAccountDeletedEvent;
use Te4g\UserBundle\Exception\CurrentPasswordInvalid;

final readonly class AccountDeletionManager
{
    /** @param iterable<AccountDeletionGuardInterface> $guards */
    public function __construct(
        private AccountRepositoryInterface $repository,
        private UserPasswordHasherInterface $passwordHasher,
        private iterable $guards,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function delete(AccountUserInterface $user, #[\SensitiveParameter] string $currentPassword): void
    {
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new CurrentPasswordInvalid();
        }

        foreach ($this->guards as $guard) {
            $guard->assertDeletionAllowed($user);
        }

        $this->eventDispatcher->dispatch(new BeforeAccountDeletedEvent($user));

        $accountId = (string) $user->getId();
        $email = $user->getEmail();

        $this->repository->remove($user);

        try {
            $this->eventDispatcher->dispatch(new AfterAccountDeletedEvent($accountId, $email));
        } catch (\Throwable $listenerFailure) {
            try {
                $this->logger->error(
                    'An after-account-deleted listener failed after persistence completed.',
                    ['exception_class' => $listenerFailure::class],
                );
            } catch (\Throwable) {
            }
        }
    }
}
