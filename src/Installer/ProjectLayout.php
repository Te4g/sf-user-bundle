<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerConflict;

final readonly class ProjectLayout
{
    private function __construct(private string $projectDir)
    {
    }

    public static function at(string $projectDir): self
    {
        if (str_contains($projectDir, "\0")) {
            throw InstallerConflict::unsafePath();
        }

        $resolved = realpath($projectDir);
        if (false === $resolved || !is_dir($resolved)) {
            throw InstallerConflict::unsafePath();
        }

        return new self(rtrim($resolved, DIRECTORY_SEPARATOR));
    }

    public function projectDir(): string
    {
        return $this->projectDir;
    }

    public function path(string $relativePath): string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        $segments = explode('/', $relativePath);
        $current = $this->projectDir;

        foreach ($segments as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($current)) {
                throw InstallerConflict::unsafePath();
            }

            if (!file_exists($current)) {
                continue;
            }

            $resolved = realpath($current);
            if (false === $resolved || !$this->contains($resolved)) {
                throw InstallerConflict::unsafePath();
            }
        }

        if (!$this->contains($current)) {
            throw InstallerConflict::unsafePath();
        }

        return $current;
    }

    public function normalizeRelativePath(string $relativePath): string
    {
        if ('' === $relativePath || str_contains($relativePath, "\0")) {
            throw InstallerConflict::unsafePath();
        }

        $portablePath = str_replace('\\', '/', $relativePath);
        if (str_starts_with($portablePath, '/') || 1 === preg_match('/^[A-Za-z]:\//', $portablePath)) {
            throw InstallerConflict::unsafePath();
        }

        $normalized = [];
        foreach (explode('/', $portablePath) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            if ('..' === $segment) {
                throw InstallerConflict::unsafePath();
            }

            $normalized[] = $segment;
        }

        if ([] === $normalized) {
            throw InstallerConflict::unsafePath();
        }

        return implode('/', $normalized);
    }

    private function contains(string $absolutePath): bool
    {
        return $absolutePath === $this->projectDir
            || str_starts_with($absolutePath, $this->projectDir.DIRECTORY_SEPARATOR);
    }
}
