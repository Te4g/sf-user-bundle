<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

interface InstallationValidatorInterface
{
    public function validate(string $absolutePath, string $relativePath): void;
}
