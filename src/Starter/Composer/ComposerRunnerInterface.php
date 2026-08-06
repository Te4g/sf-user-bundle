<?php

declare(strict_types=1);

namespace Te4g\Starter\Composer;

use Te4g\Starter\Process\ProcessResult;

interface ComposerRunnerInterface
{
    /** @param list<string> $arguments */
    public function run(array $arguments, string $cwd): ProcessResult;
}
