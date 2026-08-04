<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

final readonly class InstallerProcessResult
{
    public function __construct(private int $exitCode)
    {
        if ($exitCode < 0) {
            throw new \InvalidArgumentException('A process exit code cannot be negative.');
        }
    }

    public function isSuccessful(): bool
    {
        return 0 === $this->exitCode;
    }
}
