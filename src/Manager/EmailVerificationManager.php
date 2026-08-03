<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Manager;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Event\AccountVerifiedEvent;
use Te4g\UserBundle\Exception\VerificationFailed;

final readonly class EmailVerificationManager
{
    public function __construct(
        private AccountRepositoryInterface $repository,
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private AccountMailerInterface $mailer,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function send(AccountUserInterface $user): void
    {
        $id = $user->getId();

        if (null === $id) {
            throw new VerificationFailed();
        }

        $components = $this->verifyEmailHelper->generateSignature(
            'te4g_user_verify_email',
            (string) $id,
            $user->getEmail(),
            ['id' => (string) $id],
        );

        $this->mailer->sendVerification($user, $components->getSignedUrl());
    }

    public function verify(string $signedUri, int|string $accountId): AccountUserInterface
    {
        $user = $this->repository->findById($accountId);

        if (null === $user) {
            throw new VerificationFailed();
        }

        try {
            $this->verifyEmailHelper->validateEmailConfirmation($signedUri, (string) $accountId, $user->getEmail());
        } catch (VerifyEmailExceptionInterface) {
            throw new VerificationFailed();
        }

        $user->setVerified(true);
        $this->repository->save($user);
        $this->eventDispatcher->dispatch(new AccountVerifiedEvent($user));

        return $user;
    }
}
