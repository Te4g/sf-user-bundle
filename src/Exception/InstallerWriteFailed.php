<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Exception;

final class InstallerWriteFailed extends \RuntimeException
{
    public static function validationFailed(): self
    {
        return new self('Installer validation failed.');
    }

    public static function writeFailed(): self
    {
        return new self('Installer write failed.');
    }

    public function outcome(): string
    {
        return 'Installer validation failed.' === $this->getMessage() ? 'validation-failed' : 'write-failed';
    }
}
