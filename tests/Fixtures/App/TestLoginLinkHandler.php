<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\App;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkDetails;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

final class TestLoginLinkHandler implements LoginLinkHandlerInterface
{
    public function createLoginLink(UserInterface $user, ?Request $request = null, ?int $lifetime = null): LoginLinkDetails
    {
        return new LoginLinkDetails(
            'https://localhost/login-link/check?expires=2000000000&user='.
                rawurlencode($user->getUserIdentifier()).'&hash=test',
            new \DateTimeImmutable('+10 minutes'),
        );
    }

    public function consumeLoginLink(Request $request): UserInterface
    {
        throw new \LogicException('The test firewall does not consume login links.');
    }
}
