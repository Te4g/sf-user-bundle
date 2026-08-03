<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Exception;

final class CurrentPasswordInvalid extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The current password is invalid.');
    }
}
