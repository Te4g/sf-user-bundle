<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Manager;

use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Util\EmailNormalizer;

final readonly class MagicLinkManager
{
    public function __construct(
        private AccountRepositoryInterface $repository,
        private EmailNormalizer $emailNormalizer,
        private EmailVerificationManager $emailVerificationManager,
        private LoginLinkHandlerInterface $loginLinkHandler,
        private AccountMailerInterface $mailer,
    ) {
    }

    public function request(string $email): void
    {
        $user = $this->repository->findOneByEmail($this->emailNormalizer->normalize($email));

        if (null === $user) {
            return;
        }

        if (!$user->isVerified()) {
            $this->emailVerificationManager->send($user);

            return;
        }

        $loginLink = $this->loginLinkHandler->createLoginLink($user);
        $this->mailer->sendMagicLink($user, $loginLink->getUrl());
    }
}
