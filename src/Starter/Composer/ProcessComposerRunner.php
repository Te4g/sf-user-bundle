<?php

declare(strict_types=1);

namespace Te4g\Starter\Composer;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Te4g\Starter\Process\ProcessResult;

final class ProcessComposerRunner implements ComposerRunnerInterface
{
    private const int TIMEOUT_EXIT_CODE = 124;

    public function __construct(private readonly float $timeout = 600.0)
    {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments, string $cwd): ProcessResult
    {
        $command = ['composer', ...$arguments];
        $process = new Process($command, $cwd);
        $process->setTimeout($this->timeout);

        try {
            $exitCode = $process->run();

            return new ProcessResult($command, $exitCode, $process->getOutput(), $process->getErrorOutput());
        } catch (ProcessTimedOutException $exception) {
            $stderr = $process->getErrorOutput();
            if ('' !== $stderr) {
                $stderr .= "\n";
            }
            $stderr .= $exception->getMessage();

            return new ProcessResult($command, self::TIMEOUT_EXIT_CODE, $process->getOutput(), $stderr);
        }
    }
}
