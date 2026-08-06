<?php

declare(strict_types=1);

namespace Te4g\Starter\Project;

final readonly class Conflict
{
    public function __construct(
        private ?string $path,
        private string $reason,
    ) {
    }

    public function message(): string
    {
        return null === $this->path ? $this->reason : $this->path.': '.$this->reason;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
