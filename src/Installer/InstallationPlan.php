<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerConflict;

final readonly class InstallationPlan
{
    /**
     * @param list<PlannedFile>       $files
     * @param list<InstallerConflict> $conflicts
     */
    private function __construct(
        private ProjectLayout $layout,
        private array $files,
        private array $conflicts,
        private InstallationManifest $manifest,
    ) {
        $seen = [];
        foreach ($files as $file) {
            if (isset($seen[$file->relativePath()])) {
                throw new \InvalidArgumentException('A plan cannot contain duplicate paths.');
            }

            $seen[$file->relativePath()] = true;
        }
    }

    /**
     * @param list<PlannedFile>       $files
     * @param list<InstallerConflict> $conflicts
     */
    public static function forLayout(
        ProjectLayout $layout,
        array $files,
        array $conflicts = [],
        ?InstallationManifest $manifest = null,
    ): self {
        $hashes = [];
        foreach ($files as $file) {
            $relativePath = $layout->normalizeRelativePath($file->relativePath());
            if ($relativePath !== $file->relativePath()) {
                throw InstallerConflict::unsafePath();
            }

            $layout->path($relativePath);
            $hashes[$file->relativePath()] = hash('sha256', $file->contents());
        }

        return new self(
            $layout,
            $files,
            $conflicts,
            $manifest ?? InstallationManifest::create('0.1.0', new \DateTimeImmutable(), $hashes),
        );
    }

    public function layout(): ProjectLayout
    {
        return $this->layout;
    }

    /** @return list<PlannedFile> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return list<InstallerConflict> */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    public function hasConflicts(): bool
    {
        return [] !== $this->conflicts;
    }

    public function manifest(): InstallationManifest
    {
        return $this->manifest;
    }
}
