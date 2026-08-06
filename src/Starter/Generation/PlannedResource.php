<?php

declare(strict_types=1);

namespace Te4g\Starter\Generation;

final readonly class PlannedResource
{
    private function __construct(
        private string $relativePath,
        private string $sourcePath,
        private bool $replacement,
        private ?string $expectedContents,
    ) {
    }

    public static function create(string $relativePath, string $sourcePath): self
    {
        return new self($relativePath, $sourcePath, false, null);
    }

    public static function replace(string $relativePath, string $sourcePath, string $expectedContents): self
    {
        return new self($relativePath, $sourcePath, true, $expectedContents);
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

    public function expectedContents(): ?string
    {
        return $this->expectedContents;
    }
}
