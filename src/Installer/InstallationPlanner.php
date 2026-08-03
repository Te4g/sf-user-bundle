<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerConflict;

final readonly class InstallationPlanner
{
    private \DateTimeImmutable $generatedAt;

    public function __construct(
        private ManifestStore $manifestStore,
        ?\DateTimeImmutable $generatedAt = null,
    ) {
        $this->generatedAt = $generatedAt ?? new \DateTimeImmutable();
    }

    /**
     * @param array<string, string> $renderedFiles
     */
    public function plan(ProjectLayout $layout, array $renderedFiles): InstallationPlan
    {
        ksort($renderedFiles, SORT_STRING);
        $currentManifest = $this->manifestStore->load($layout);
        $plannedFiles = [];
        $conflicts = [];
        $nextHashes = [];

        foreach ($renderedFiles as $relativePath => $contents) {
            $relativePath = $layout->normalizeRelativePath($relativePath);
            $path = $layout->path($relativePath);
            $newHash = hash('sha256', $contents);
            $nextHashes[$relativePath] = $newHash;
            $priorHash = $currentManifest?->hashFor($relativePath);

            if (!file_exists($path)) {
                $plannedFiles[] = PlannedFile::create($relativePath, $contents);

                continue;
            }

            if (!is_file($path) || is_link($path)) {
                $conflicts[] = InstallerConflict::forPath($relativePath, 'unsafe-existing-target');

                continue;
            }

            $currentContents = file_get_contents($path);
            if (false === $currentContents) {
                $conflicts[] = InstallerConflict::forPath($relativePath, 'unreadable-existing-file');

                continue;
            }

            $currentHash = hash('sha256', $currentContents);
            if (null === $priorHash) {
                $conflicts[] = InstallerConflict::forPath($relativePath, 'unmanaged-existing-file');

                continue;
            }

            if (!hash_equals($priorHash, $currentHash)) {
                $conflicts[] = InstallerConflict::forPath($relativePath, 'modified-managed-file');

                continue;
            }

            if (!hash_equals($newHash, $currentHash)) {
                $plannedFiles[] = PlannedFile::managedUpdate($relativePath, $priorHash, $contents);
            }
        }

        $manifestTime = $this->generatedAt;
        if (null !== $currentManifest
            && $currentManifest->bundleVersion() === $this->manifestStore->create([], $this->generatedAt)->bundleVersion()
            && $currentManifest->files() === $nextHashes
        ) {
            $manifestTime = $currentManifest->generatedAt();
        }

        return InstallationPlan::forLayout(
            $layout,
            $plannedFiles,
            $conflicts,
            $this->manifestStore->create($nextHashes, $manifestTime),
        );
    }
}
