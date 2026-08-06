<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Mailer\AccountMailer;
use App\Repository\ResetPasswordRequestRepository;
use App\Repository\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

final readonly class PasswordResetService
{
    public function __construct(
        private UserRepository $users,
        private EmailNormalizer $normalizer,
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private ResetPasswordRequestRepository $resetRequests,
        private UserPasswordHasherInterface $hasher,
        private UrlGeneratorInterface $urlGenerator,
        private AccountMailer $mailer,
    ) {
    }

    public function request(string $email): void
    {
        $user = $this->users->findOneByNormalizedEmail($this->normalizer->normalize($email));
        if (!$user instanceof User) {
            return;
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            return;
        }

        $absoluteUrl = $this->urlGenerator->generate(
            'app_forgot_password_reset',
            ['token' => $resetToken->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $this->mailer->sendPasswordReset($user, $absoluteUrl);
    }

    public function reset(
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $plainPassword,
    ): void {
        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface) {
            throw new \RuntimeException('Password reset failed.');
        }

        if (!$user instanceof User) {
            throw new \RuntimeException('Password reset failed.');
        }

        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $this->users->save($user);
        $this->resetPasswordHelper->removeResetRequest($token);
        $this->resetRequests->removeRequests($user);
    }
}
