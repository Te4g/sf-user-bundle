<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

final class ExternalFileSnapshot
{
    public function __construct(
        private readonly string $relativePath,
        private readonly ?ApplicationFileState $originalState,
        private readonly ?string $originalContents,
        private ?ApplicationFileState $writtenState,
    ) {
    }

    public function relativePath(): string
    {
        return $this->relativePath;
    }

    public function originalState(): ?ApplicationFileState
    {
        return $this->originalState;
    }

    public function originalContents(): ?string
    {
        return $this->originalContents;
    }

    public function writtenState(): ?ApplicationFileState
    {
        return $this->writtenState;
    }

    public function updateWrittenState(?ApplicationFileState $state): void
    {
        $this->writtenState = $state;
    }
}
