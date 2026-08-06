<?php

declare(strict_types=1);

namespace Te4g\Starter\Generation;

final readonly class PlannedResource
{
    private function __construct(
        private string $relativePath,
        private string $sourcePath,
        private bool $replacement,
    ) {
    }

    public static function create(string $relativePath, string $sourcePath): self
    {
        return new self($relativePath, $sourcePath, false);
    }

    public static function replace(string $relativePath, string $sourcePath): self
    {
        return new self($relativePath, $sourcePath, true);
    }

    public function relativePath(): string
    {
        return $this->relativePath;
    }

    public function sourcePath(): string
    {
        return $this->sourcePath;
    }

    public function isCreation(): bool
    {
        return !$this->replacement;
    }

    public function isReplacement(): bool
    {
        return $this->replacement;
    }
}
