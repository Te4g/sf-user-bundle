<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

final readonly class InstalledFileSnapshot
{
    public function __construct(
        private string $relativePath,
        private ?string $originalContents,
        private ?int $originalMode,
        private ?string $parentRelativePath,
        private FilesystemIdentity $parentIdentity,
        private string $writtenHash,
        private ?FilesystemIdentity $writtenIdentity,
    ) {
    }

    public function relativePath(): string
    {
        return $this->relativePath;
    }

    public function originalContents(): ?string
    {
        return $this->originalContents;
    }

    public function originalMode(): ?int
    {
        return $this->originalMode;
    }

    public function parentRelativePath(): ?string
    {
        return $this->parentRelativePath;
    }

    public function parentIdentity(): FilesystemIdentity
    {
        return $this->parentIdentity;
    }

    public function writtenHash(): string
    {
        return $this->writtenHash;
    }

    public function writtenIdentity(): ?FilesystemIdentity
    {
        return $this->writtenIdentity;
    }
}
