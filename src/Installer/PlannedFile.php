<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

final readonly class PlannedFile
{
    private const CREATE = 'create';
    private const MANAGED_UPDATE = 'managed-update';

    private function __construct(
        private string $relativePath,
        private ?string $priorHash,
        private string $contents,
        private string $mode,
    ) {
        if ('' === $relativePath || str_contains($relativePath, "\0")) {
            throw new \InvalidArgumentException('A planned file requires a relative path.');
        }

        if (self::MANAGED_UPDATE === $mode && !self::isHash($priorHash)) {
            throw new \InvalidArgumentException('A managed update requires a SHA-256 prior hash.');
        }
    }

    public static function create(string $relativePath, string $contents): self
    {
        return new self($relativePath, null, $contents, self::CREATE);
    }

    public static function managedUpdate(string $relativePath, string $priorHash, string $contents): self
    {
        return new self($relativePath, $priorHash, $contents, self::MANAGED_UPDATE);
    }

    public function relativePath(): string
    {
        return $this->relativePath;
    }

    public function priorHash(): ?string
    {
        return $this->priorHash;
    }

    public function contents(): string
    {
        return $this->contents;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function isCreate(): bool
    {
        return self::CREATE === $this->mode;
    }

    private static function isHash(?string $hash): bool
    {
        return null !== $hash && 1 === preg_match('/^[a-f0-9]{64}$/', $hash);
    }
}
