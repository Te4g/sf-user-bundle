<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Symfony\Component\Process\Process;
use Te4g\UserBundle\Exception\InstallerProcessFailed;

final readonly class SymfonyInstallerProcessRunner implements InstallerProcessRunnerInterface
{
    public function run(array $command, string $cwd, float $timeout): InstallerProcessResult
    {
        try {
            $process = new Process($command, $cwd);
            $process->setTimeout($timeout);
            $exitCode = $process->run();

            return new InstallerProcessResult($exitCode);
        } catch (\Throwable) {
            throw InstallerProcessFailed::failed();
        }
    }
}
