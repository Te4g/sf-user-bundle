<?php

declare(strict_types=1);

namespace Te4g\Starter\Exception;

use Te4g\Starter\Process\ProcessResult;

final class SubprocessFailed extends \RuntimeException
{
    /** @param list<string> $command */
    private function __construct(
        private readonly string $phase,
        private readonly array $command,
        private readonly int $exitCode,
        private readonly string $stdout,
        private readonly string $stderr,
    ) {
        parent::__construct(sprintf(
            '%s failed with exit code %d: %s',
            $phase,
            $exitCode,
            '' === $stderr ? $stdout : $stderr,
        ));
    }

    public static function forResult(string $phase, ProcessResult $result): self
    {
        return new self($phase, $result->command(), $result->exitCode(), $result->stdout(), $result->stderr());
    }

    public function phase(): string
    {
        return $this->phase;
    }

    /** @return list<string> */
    public function command(): array
    {
        return $this->command;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function stdout(): string
    {
        return $this->stdout;
    }

    public function stderr(): string
    {
        return $this->stderr;
    }
}
