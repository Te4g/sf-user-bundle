<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

interface InstallerProcessRunnerInterface
{
    /** @param list<string> $command */
    public function run(array $command, string $cwd, float $timeout): InstallerProcessResult;
}
