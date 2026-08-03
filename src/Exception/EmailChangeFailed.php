<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Exception;

final class EmailChangeFailed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Email change failed.');
    }
}
