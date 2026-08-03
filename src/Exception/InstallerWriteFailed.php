<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Exception;

final class InstallerWriteFailed extends \RuntimeException
{
    public static function validationFailed(?\Throwable $previous = null): self
    {
        return new self('Installer validation failed.', 0, $previous);
    }

    public static function writeFailed(?\Throwable $previous = null): self
    {
        return new self('Installer write failed.', 0, $previous);
    }
}
