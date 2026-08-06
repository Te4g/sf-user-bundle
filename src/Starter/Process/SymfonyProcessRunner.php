<?php

declare(strict_types=1);

namespace Te4g\Starter\Process;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunnerInterface
{
    public function run(array $command, string $cwd, float $timeout): ProcessResult
    {
        $process = new Process($command, $cwd);
        $process->setTimeout($timeout);

        try {
            $exitCode = $process->run();

            return new ProcessResult($command, $exitCode, $process->getOutput(), $process->getErrorOutput());
        } catch (ProcessTimedOutException $exception) {
            $stderr = $process->getErrorOutput();
            if ('' !== $stderr) {
                $stderr .= "\n";
            }
            $stderr .= $exception->getMessage();

            return new ProcessResult($command, 124, $process->getOutput(), $stderr);
        }
    }
}
