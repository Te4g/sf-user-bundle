<?php

declare(strict_types=1);

namespace Te4g\Starter\Project;

use Te4g\Starter\Exception\InvalidProject;

final readonly class ProjectLayout
{
    private function __construct(private string $root)
    {
    }

    public static function discover(string $workingDirectory): self
    {
        if (str_contains($workingDirectory, "\0")) {
            throw InvalidProject::unsafePath($workingDirectory);
        }

        $current = realpath($workingDirectory);
        if (false === $current || !is_dir($current)) {
            throw InvalidProject::projectRootNotFound();
        }

        while (true) {
            if (is_file($current.'/composer.json') && is_file($current.'/bin/console')) {
                return new self(rtrim($current, DIRECTORY_SEPARATOR));
            }

            $parent = dirname($current);
            if ($parent === $current) {
                throw InvalidProject::projectRootNotFound();
            }

            $current = $parent;
        }
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relativePath): string
    {
        $normalized = $this->normalizeRelativePath($relativePath);
        $candidate = $this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);

        if (!$this->isInsideRoot($candidate)) {
            throw InvalidProject::unsafePath($relativePath);
        }

        return $candidate;
    }

    private function normalizeRelativePath(string $relativePath): string
    {
        if ('' === $relativePath || str_contains($relativePath, "\0")) {
            throw InvalidProject::unsafePath($relativePath);
        }

        $portablePath = str_replace('\\', '/', $relativePath);
        if (str_starts_with($portablePath, '/') || 1 === preg_match('/^[A-Za-z]:\//', $portablePath)) {
            throw InvalidProject::unsafePath($relativePath);
        }

        $segments = [];
        foreach (explode('/', $portablePath) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                throw InvalidProject::unsafePath($relativePath);
            }

            $segments[] = $segment;
        }

        if ([] === $segments) {
            throw InvalidProject::unsafePath($relativePath);
        }

        return implode('/', $segments);
    }

    private function isInsideRoot(string $candidate): bool
    {
        if (!$this->contains($candidate)) {
            return false;
        }

        $current = $this->root;
        $relativeCandidate = substr($candidate, strlen($this->root) + 1);
        foreach (explode(DIRECTORY_SEPARATOR, $relativeCandidate) as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (!file_exists($current) && !is_link($current)) {
                continue;
            }

            $resolved = realpath($current);
            if (false === $resolved || !$this->contains($resolved)) {
                return false;
            }
        }

        return true;
    }

    private function contains(string $path): bool
    {
        return $path === $this->root || str_starts_with($path, $this->root.DIRECTORY_SEPARATOR);
    }
}
