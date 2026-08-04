<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

final readonly class ApplicationFileState
{
    public function __construct(
        private string $relativePath,
        private string $type,
        private string $hash,
        private int $mode,
        private FilesystemIdentity $identity,
    ) {
    }

    public function relativePath(): string
    {
        return $this->relativePath;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function mode(): int
    {
        return $this->mode;
    }

    public function identity(): FilesystemIdentity
    {
        return $this->identity;
    }

    public function equals(?self $other): bool
    {
        return null !== $other
            && $this->relativePath === $other->relativePath
            && $this->type === $other->type
            && $this->hash === $other->hash
            && $this->mode === $other->mode
            && $this->identity->equals($other->identity);
    }
}
