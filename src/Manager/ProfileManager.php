<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Manager;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Event\EmailChangedEvent;
use Te4g\UserBundle\Event\EmailChangeRequestedEvent;
use Te4g\UserBundle\Exception\CurrentPasswordInvalid;
use Te4g\UserBundle\Exception\EmailChangeFailed;
use Te4g\UserBundle\Util\EmailNormalizer;

final readonly class ProfileManager
{
    public function __construct(
        private AccountRepositoryInterface $repository,
        private UserPasswordHasherInterface $passwordHasher,
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private AccountMailerInterface $mailer,
        private ResetPasswordRequestRepositoryInterface $resetPasswordRequestRepository,
        private EventDispatcherInterface $eventDispatcher,
        private EmailNormalizer $emailNormalizer = new EmailNormalizer(),
    ) {
    }

    public function requestEmailChange(
        AccountUserInterface $user,
        string $newEmail,
        #[\SensitiveParameter] string $currentPassword,
    ): void {
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new CurrentPasswordInvalid();
        }

        $id = $user->getId();

        if (null === $id) {
            throw new EmailChangeFailed();
        }

        $pendingEmail = $this->emailNormalizer->normalize($newEmail);

        if (null !== $this->repository->findOneByEmail($pendingEmail)) {
            throw new EmailChangeFailed();
        }

        $user->setPendingEmail($pendingEmail);
        $this->repository->save($user);

        $components = $this->verifyEmailHelper->generateSignature(
            'te4g_user_email_change_confirm',
            (string) $id,
            $pendingEmail,
            ['id' => (string) $id],
        );

        $this->mailer->sendEmailChange($user, $components->getSignedUrl());
        $this->eventDispatcher->dispatch(new EmailChangeRequestedEvent($user));
    }

    public function confirmEmailChange(
        #[\SensitiveParameter] string $signedUri,
        int|string $accountId,
    ): AccountUserInterface {
        $user = $this->repository->findById($accountId);
        $pendingEmail = $user?->getPendingEmail();

        if (null === $user || null === $pendingEmail) {
            throw new EmailChangeFailed();
        }

        try {
            $this->verifyEmailHelper->validateEmailConfirmation($signedUri, (string) $accountId, $pendingEmail);
        } catch (VerifyEmailExceptionInterface) {
            throw new EmailChangeFailed();
        }

        $previousEmail = $user->getEmail();
        $user->setEmail($pendingEmail);
        $user->setPendingEmail(null);
        $this->repository->save($user);
        $this->resetPasswordRequestRepository->removeRequests($user);
        $this->eventDispatcher->dispatch(new EmailChangedEvent($user, $previousEmail));

        return $user;
    }
}
