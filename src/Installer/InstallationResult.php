<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerConflict;
use Te4g\UserBundle\Exception\InstallerRollbackIncomplete;
use Te4g\UserBundle\Exception\InstallerWriteFailed;

final class InstallationResult
{
    private bool $rolledBack = false;
    private bool $committed = false;
    private bool $rollbackIncomplete = false;

    private readonly InstallerFilesystem $filesystem;
    private readonly InstallationRaceHookInterface $raceHook;

    /**
     * @param list<InstalledFileSnapshot>                             $snapshots
     * @param list<array{path: string, identity: FilesystemIdentity}> $createdDirectories
     */
    public function __construct(
        private readonly ProjectLayout $layout,
        private readonly array $snapshots,
        private readonly array $createdDirectories,
        private readonly ?ProjectLock $lock = null,
        ?InstallerFilesystem $filesystem = null,
        ?InstallationRaceHookInterface $raceHook = null,
    ) {
        $this->filesystem = $filesystem ?? new InstallerFilesystem();
        $this->raceHook = $raceHook ?? new NullInstallationRaceHook();
    }

    public function __destruct()
    {
        $this->lock?->releaseQuietly();
    }

    /** @return list<string> */
    public function writtenPaths(): array
    {
        return array_map(
            static fn (InstalledFileSnapshot $snapshot): string => $snapshot->relativePath(),
            $this->snapshots,
        );
    }

    public function commit(): void
    {
        if ($this->committed || $this->rolledBack || $this->rollbackIncomplete) {
            return;
        }

        $this->lock?->release($this->filesystem);
        $this->committed = true;
    }

    public function rollback(): void
    {
        if ($this->rolledBack) {
            return;
        }

        if ($this->committed || $this->rollbackIncomplete) {
            throw InstallerRollbackIncomplete::requested('operation-failed');
        }

        $rollbackOutcome = null;
        foreach (array_reverse($this->snapshots) as $snapshot) {
            try {
                $this->rollbackFile($snapshot);
            } catch (InstallerConflict) {
                $rollbackOutcome ??= 'ownership-conflict';
            } catch (\Throwable) {
                $rollbackOutcome = 'operation-failed';
            }
        }

        foreach (array_reverse($this->createdDirectories) as $directory) {
            try {
                $path = $this->layout->path($directory['path']);
                $currentIdentity = $this->filesystem->identity($path);
                if (null === $currentIdentity) {
                    continue;
                }

                if (!$directory['identity']->equals($currentIdentity)) {
                    $rollbackOutcome ??= 'ownership-conflict';

                    continue;
                }

                if ([] === $this->filesystem->entries($path)) {
                    $this->filesystem->removeDirectory($path);
                }
            } catch (InstallerConflict) {
                $rollbackOutcome ??= 'ownership-conflict';
            } catch (\Throwable) {
                $rollbackOutcome = 'operation-failed';
            }
        }

        try {
            $this->lock?->release($this->filesystem);
        } catch (\Throwable) {
            $rollbackOutcome = 'operation-failed';
        }

        if (null !== $rollbackOutcome) {
            $this->rollbackIncomplete = true;

            throw InstallerRollbackIncomplete::requested($rollbackOutcome);
        }

        $this->rolledBack = true;
    }

    public function wasRolledBack(): bool
    {
        return $this->rolledBack;
    }

    public function rollbackWasIncomplete(): bool
    {
        return $this->rollbackIncomplete;
    }

    private function rollbackFile(InstalledFileSnapshot $snapshot): void
    {
        $this->assertInstallerStillOwns($snapshot);
        $this->raceHook->beforeRollback($this->layout, $snapshot->relativePath());
        $this->assertInstallerStillOwns($snapshot);

        $target = $this->layout->path($snapshot->relativePath());
        if (null === $snapshot->originalContents()) {
            $this->filesystem->remove($target);

            return;
        }

        $temporary = dirname($target).'/.te4g-user-restore-'.bin2hex(random_bytes(8));
        try {
            $this->filesystem->writeExclusive(
                $temporary,
                $snapshot->originalContents(),
                $snapshot->originalMode() ?? 0644,
            );
            $this->assertInstallerStillOwns($snapshot);
            $this->filesystem->rename($temporary, $target);
        } catch (\Throwable) {
            if (null !== $this->filesystem->identity($temporary)) {
                $this->filesystem->remove($temporary);
            }

            throw InstallerWriteFailed::writeFailed();
        }
    }

    private function assertInstallerStillOwns(InstalledFileSnapshot $snapshot): void
    {
        $parentRelativePath = $snapshot->parentRelativePath();
        $parent = null === $parentRelativePath
            ? $this->layout->projectDir()
            : $this->layout->path($parentRelativePath);
        if (!$snapshot->parentIdentity()->equals($this->filesystem->identity($parent))) {
            throw InstallerConflict::forPath($snapshot->relativePath(), 'rollback-parent-changed');
        }

        $target = $this->layout->path($snapshot->relativePath());
        $writtenIdentity = $snapshot->writtenIdentity();
        if (null === $writtenIdentity
            || !$writtenIdentity->equals($this->filesystem->identity($target))
            || !hash_equals($snapshot->writtenHash(), $this->filesystem->hash($target))
        ) {
            throw InstallerConflict::forPath($snapshot->relativePath(), 'rollback-target-changed');
        }
    }
}
