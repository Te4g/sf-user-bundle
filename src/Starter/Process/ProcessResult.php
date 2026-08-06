<?php

declare(strict_types=1);

namespace Te4g\Starter\Process;

final readonly class ProcessResult
{
    /**
     * @param list<string> $command
     */
    public function __construct(
        private array $command,
        private int $exitCode,
        private string $stdout,
        private string $stderr,
    ) {
    }

    public static function success(): self
    {
        return new self([], 0, '', '');
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

    public function isSuccessful(): bool
    {
        return 0 === $this->exitCode;
    }
}
