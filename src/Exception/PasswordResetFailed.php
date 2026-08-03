<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Exception;

final class PasswordResetFailed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Password reset failed.');
    }
}
