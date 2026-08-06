<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Fixtures;

use Te4g\Starter\Composer\ComposerRunnerInterface;
use Te4g\Starter\Process\ProcessResult;

final class RecordingComposerRunner implements ComposerRunnerInterface
{
    /** @var list<array{list<string>, string}> */
    public array $calls = [];

    public function __construct(private readonly ProcessResult $result)
    {
    }

    public function run(array $arguments, string $cwd): ProcessResult
    {
        $this->calls[] = [$arguments, $cwd];

        return $this->result;
    }
}
