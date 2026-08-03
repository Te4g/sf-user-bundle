<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Exception;

final class VerificationFailed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Email verification failed.');
    }
}
