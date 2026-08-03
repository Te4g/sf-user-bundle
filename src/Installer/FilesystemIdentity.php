<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

final readonly class FilesystemIdentity
{
    public function __construct(
        private int $device,
        private int $inode,
        private int $mode,
    ) {
    }

    /** @param array<int|string, int> $stat */
    public static function fromStat(array $stat): self
    {
        return new self($stat['dev'], $stat['ino'], $stat['mode']);
    }

    public function equals(?self $other): bool
    {
        return null !== $other
            && $this->device === $other->device
            && $this->inode === $other->inode
            && $this->modeType() === $other->modeType();
    }

    public function isDirectory(): bool
    {
        return 0040000 === $this->modeType();
    }

    public function isRegularFile(): bool
    {
        return 0100000 === $this->modeType();
    }

    private function modeType(): int
    {
        return $this->mode & 0170000;
    }
}
