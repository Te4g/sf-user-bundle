<?php

declare(strict_types=1);

namespace Te4g\Starter\Process;

interface ProcessRunnerInterface
{
    /** @param list<string> $command */
    public function run(array $command, string $cwd, float $timeout): ProcessResult;
}
