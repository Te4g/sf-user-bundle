<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Fixtures;

use Te4g\Starter\Process\ProcessResult;
use Te4g\Starter\Process\ProcessRunnerInterface;

final class RecordingProcessRunner implements ProcessRunnerInterface
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<array{command: list<string>, cwd: string, timeout: float}> */
    public array $calls = [];

    /** @var list<ProcessResult> */
    private array $results;

    public function __construct(ProcessResult ...$results)
    {
        $this->results = array_values($results);
    }

    public function run(array $command, string $cwd, float $timeout): ProcessResult
    {
        $this->commands[] = $command;
        $this->calls[] = ['command' => $command, 'cwd' => $cwd, 'timeout' => $timeout];
        $result = array_shift($this->results);

        return $result ?? new ProcessResult($command, 0, '', '');
    }
}
