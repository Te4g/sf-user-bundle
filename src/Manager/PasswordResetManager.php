<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Manager;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\Persistence\ResetPasswordRequestRepositoryInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Event\PasswordChangedEvent;
use Te4g\UserBundle\Event\PasswordChangeOrigin;
use Te4g\UserBundle\Exception\PasswordResetFailed;
use Te4g\UserBundle\Util\EmailNormalizer;

final readonly class PasswordResetManager
{
    public function __construct(
        private AccountRepositoryInterface $repository,
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private ResetPasswordRequestRepositoryInterface $resetPasswordRequestRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private UrlGeneratorInterface $urlGenerator,
        private AccountMailerInterface $mailer,
        private EventDispatcherInterface $eventDispatcher,
        private EmailNormalizer $emailNormalizer = new EmailNormalizer(),
    ) {
    }

    public function request(string $email): void
    {
        $user = $this->repository->findOneByEmail($this->emailNormalizer->normalize($email));

        if (null === $user) {
            return;
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            throw new PasswordResetFailed();
        }

        $absoluteUrl = $this->urlGenerator->generate(
            'te4g_user_password_reset',
            ['token' => $resetToken->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $this->mailer->sendPasswordReset($user, $absoluteUrl);
    }

    public function reset(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $plainPassword,
    ): AccountUserInterface {
        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            throw new PasswordResetFailed();
        }

        if (!$user instanceof AccountUserInterface) {
            throw new PasswordResetFailed();
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->repository->save($user);

        try {
            $this->resetPasswordHelper->removeResetRequest($token);
        } catch (ResetPasswordExceptionInterface) {
            throw new PasswordResetFailed();
        }

        $this->resetPasswordRequestRepository->removeRequests($user);
        $this->eventDispatcher->dispatch(new PasswordChangedEvent($user, PasswordChangeOrigin::Reset));

        return $user;
    }
}
