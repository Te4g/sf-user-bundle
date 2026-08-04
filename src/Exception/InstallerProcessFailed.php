<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Exception;

final class InstallerProcessFailed extends \RuntimeException
{
    public static function failed(): self
    {
        return new self('Installer process failed.');
    }
}
