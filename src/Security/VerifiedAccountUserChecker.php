<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Te4g\UserBundle\Contract\AccountUserInterface;
use Te4g\UserBundle\Exception\UnverifiedAccount;

final class VerifiedAccountUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof AccountUserInterface && !$user->isVerified()) {
            throw new UnverifiedAccount();
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
