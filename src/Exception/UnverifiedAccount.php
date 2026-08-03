<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Exception;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class UnverifiedAccount extends CustomUserMessageAccountStatusException
{
    public function __construct()
    {
        parent::__construct('te4g_user.security.account_unverified');
    }
}
