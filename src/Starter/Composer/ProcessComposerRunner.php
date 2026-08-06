<?php

declare(strict_types=1);

namespace Te4g\Starter\Composer;

use Symfony\Component\Process\Process;
use Te4g\Starter\Process\ProcessResult;

final class ProcessComposerRunner implements ComposerRunnerInterface
{
    /** @param list<string> $arguments */
    public function run(array $arguments, string $cwd): ProcessResult
    {
        $command = ['composer', ...$arguments];
        $process = new Process($command, $cwd);
        $process->setTimeout(600);
        $exitCode = $process->run();

        return new ProcessResult($command, $exitCode, $process->getOutput(), $process->getErrorOutput());
    }
}
