<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerConflict;
use Te4g\UserBundle\Exception\InstallerRollbackIncomplete;
use Te4g\UserBundle\Exception\InstallerWriteFailed;

final readonly class StagedWriter
{
    private InstallerFilesystem $filesystem;
    private InstallationRaceHookInterface $raceHook;

    public function __construct(
        private InstallationValidatorInterface $validator,
        ?InstallationRaceHookInterface $raceHook = null,
        ?InstallerFilesystem $filesystem = null,
    ) {
        $this->raceHook = $raceHook ?? new NullInstallationRaceHook();
        $this->filesystem = $filesystem ?? new InstallerFilesystem();
    }

    public function apply(InstallationPlan $plan): InstallationResult
    {
        if ($plan->hasConflicts()) {
            throw $plan->conflicts()[0];
        }

        $layout = $plan->layout();
        if ([] === $plan->files()) {
            return new InstallationResult($layout, [], []);
        }

        $lock = ProjectLock::acquire($layout, $this->filesystem);
        $stagingRelative = 'var/te4g-user-installer/'.bin2hex(random_bytes(16));
        $stagingCreated = false;
        $snapshots = [];
        $createdTargetDirectories = [];

        try {
            $this->assertTargetsUnchanged($layout, $plan->files());
            $stagingDirectories = [];
            $this->createDirectory($layout, $stagingRelative, 0700, $stagingDirectories);
            $stagingCreated = true;
            $stagedFiles = $this->stage($layout, $stagingRelative, $plan->files(), $stagingDirectories);

            foreach ($stagedFiles as $relativePath => $stagedPath) {
                $this->validator->validate($stagedPath, $relativePath);
            }

            foreach ($plan->files() as $file) {
                $parentRelative = dirname($file->relativePath());
                $parentRelative = '.' === $parentRelative ? null : $parentRelative;
                if (null !== $parentRelative) {
                    $this->createDirectory($layout, $parentRelative, 0755, $createdTargetDirectories);
                }

                $parentPath = null === $parentRelative ? $layout->projectDir() : $layout->path($parentRelative);
                $parentIdentity = $this->requiredDirectoryIdentity($parentPath);
                [$originalContents, $originalMode, $originalIdentity] = $this->captureTargetState($layout, $file);

                $this->raceHook->beforeTargetWrite($layout, $file);
                $this->assertParentUnchanged($layout, $parentRelative, $parentIdentity, $file->relativePath());
                $this->assertTargetStateUnchanged($layout, $file, $originalIdentity);

                $stagedPath = $stagedFiles[$file->relativePath()];
                $expectedWrittenIdentity = $this->requiredRegularFileIdentity($stagedPath);
                if ($file->isCreate()) {
                    if ($this->raceHook instanceof InstallationCreateRaceHookInterface) {
                        $this->raceHook->beforeCreateLink($layout, $file);
                    }

                    $this->assertParentUnchanged($layout, $parentRelative, $parentIdentity, $file->relativePath());
                    $target = $layout->path($file->relativePath());

                    try {
                        $this->filesystem->hardLink($stagedPath, $target);
                    } catch (InstallerWriteFailed) {
                        if (null !== $this->filesystem->identity($target)) {
                            throw InstallerConflict::forPath($file->relativePath(), 'create-target-changed');
                        }

                        throw InstallerWriteFailed::writeFailed();
                    }
                } else {
                    $target = $layout->path($file->relativePath());
                    $this->filesystem->rename($stagedPath, $target);
                }

                if ($this->raceHook instanceof InstallationPostWriteRaceHookInterface) {
                    $this->raceHook->afterTargetMutation($layout, $file);
                }

                $this->assertParentUnchanged($layout, $parentRelative, $parentIdentity, $file->relativePath());
                $writtenHash = hash('sha256', $file->contents());
                if (!$expectedWrittenIdentity->equals($this->filesystem->identity($target))
                    || !hash_equals($writtenHash, $this->filesystem->hash($target))
                ) {
                    throw InstallerConflict::forPath($file->relativePath(), 'write-target-changed');
                }

                $snapshot = new InstalledFileSnapshot(
                    $file->relativePath(),
                    $originalContents,
                    $originalMode,
                    $parentRelative,
                    $parentIdentity,
                    $writtenHash,
                    $expectedWrittenIdentity,
                );
                $snapshots[] = $snapshot;

                $this->filesystem->chmod($target, $originalMode ?? 0644);
                $this->assertInstallerWriteStable($layout, $snapshot);
                if ($file->isCreate()) {
                    $this->filesystem->remove($stagedPath);
                }
            }

            $result = new InstallationResult(
                $layout,
                $snapshots,
                $createdTargetDirectories,
                $lock,
                $this->filesystem,
                $this->raceHook,
            );
            foreach ($plan->files() as $file) {
                $this->validator->validate($layout->path($file->relativePath()), $file->relativePath());
            }

            $this->removeStagingDirectory($layout, $stagingRelative);
            $stagingCreated = false;

            foreach ($snapshots as $snapshot) {
                $this->assertInstallerWriteStable($layout, $snapshot);
            }

            return $result;
        } catch (\Throwable $primaryFailure) {
            $primaryOutcome = $this->primaryOutcome($primaryFailure);
            $rollbackOutcome = null;
            $partialResult = new InstallationResult(
                $layout,
                $snapshots,
                $createdTargetDirectories,
                $lock,
                $this->filesystem,
                $this->raceHook,
            );

            try {
                $partialResult->rollback();
            } catch (InstallerRollbackIncomplete $rollbackFailure) {
                $rollbackOutcome = $rollbackFailure->rollbackOutcome();
            }

            if ($stagingCreated) {
                try {
                    $this->removeStagingDirectory($layout, $stagingRelative);
                } catch (\Throwable) {
                    $rollbackOutcome = 'operation-failed';
                }
            }

            if (null !== $rollbackOutcome) {
                throw InstallerRollbackIncomplete::after($primaryOutcome, $rollbackOutcome);
            }

            throw $this->safePrimaryFailure($primaryFailure);
        }
    }

    /** @param list<PlannedFile> $files */
    private function assertTargetsUnchanged(ProjectLayout $layout, array $files): void
    {
        foreach ($files as $file) {
            $this->captureTargetState($layout, $file);
        }
    }

    /**
     * @return array{string|null, int|null, FilesystemIdentity|null}
     */
    private function captureTargetState(ProjectLayout $layout, PlannedFile $file): array
    {
        $target = $layout->path($file->relativePath());
        $identity = $this->filesystem->identity($target);
        if ($file->isCreate()) {
            if (null !== $identity) {
                throw InstallerConflict::forPath($file->relativePath(), 'create-target-exists');
            }

            return [null, null, null];
        }

        if (null === $identity || !$identity->isRegularFile() || is_link($target)) {
            throw InstallerConflict::forPath($file->relativePath(), 'managed-target-missing');
        }

        $contents = $this->filesystem->read($target);
        if (!hash_equals((string) $file->priorHash(), hash('sha256', $contents))) {
            throw InstallerConflict::forPath($file->relativePath(), 'managed-target-changed');
        }

        return [$contents, $this->filesystem->mode($target), $identity];
    }

    private function assertTargetStateUnchanged(
        ProjectLayout $layout,
        PlannedFile $file,
        ?FilesystemIdentity $originalIdentity,
    ): void {
        $target = $layout->path($file->relativePath());
        $currentIdentity = $this->filesystem->identity($target);
        if ($file->isCreate()) {
            if (null !== $currentIdentity) {
                throw InstallerConflict::forPath($file->relativePath(), 'create-target-changed');
            }

            return;
        }

        if (null === $originalIdentity
            || !$originalIdentity->equals($currentIdentity)
            || !hash_equals((string) $file->priorHash(), $this->filesystem->hash($target))
        ) {
            throw InstallerConflict::forPath($file->relativePath(), 'managed-target-changed');
        }
    }

    private function assertParentUnchanged(
        ProjectLayout $layout,
        ?string $parentRelative,
        FilesystemIdentity $expectedIdentity,
        string $targetRelative,
    ): void {
        $parent = null === $parentRelative ? $layout->projectDir() : $layout->path($parentRelative);
        if (!$expectedIdentity->equals($this->filesystem->identity($parent))) {
            throw InstallerConflict::forPath($targetRelative, 'target-parent-changed');
        }
    }

    private function assertInstallerWriteStable(ProjectLayout $layout, InstalledFileSnapshot $snapshot): void
    {
        $parentRelative = $snapshot->parentRelativePath();
        $parent = null === $parentRelative
            ? $layout->projectDir()
            : $layout->path($parentRelative);
        $writtenIdentity = $snapshot->writtenIdentity();
        $target = $layout->path($snapshot->relativePath());
        if (!$snapshot->parentIdentity()->equals($this->filesystem->identity($parent))
            || null === $writtenIdentity
            || !$writtenIdentity->equals($this->filesystem->identity($target))
            || !hash_equals($snapshot->writtenHash(), $this->filesystem->hash($target))
        ) {
            throw InstallerWriteFailed::writeFailed();
        }
    }

    /**
     * @param list<PlannedFile>                                       $files
     * @param list<array{path: string, identity: FilesystemIdentity}> $createdDirectories
     *
     * @return array<string, string>
     */
    private function stage(
        ProjectLayout $layout,
        string $stagingRelative,
        array $files,
        array &$createdDirectories,
    ): array {
        $staged = [];
        foreach ($files as $file) {
            $relativePath = $layout->normalizeRelativePath($file->relativePath());
            $stagedRelative = $stagingRelative.'/'.$relativePath;
            $this->createDirectory($layout, dirname($stagedRelative), 0700, $createdDirectories);
            $path = $layout->path($stagedRelative);
            $this->filesystem->writeExclusive($path, $file->contents(), 0600);
            $staged[$relativePath] = $path;
        }

        return $staged;
    }

    /** @param list<array{path: string, identity: FilesystemIdentity}> $createdDirectories */
    private function createDirectory(ProjectLayout $layout, string $relativePath, int $mode, array &$createdDirectories): void
    {
        $relativePath = $layout->normalizeRelativePath($relativePath);
        $current = '';
        foreach (explode('/', $relativePath) as $segment) {
            $current = '' === $current ? $segment : $current.'/'.$segment;
            $path = $layout->path($current);
            $identity = $this->filesystem->identity($path);
            if (null !== $identity) {
                if (!$identity->isDirectory()) {
                    throw InstallerWriteFailed::writeFailed();
                }

                continue;
            }

            try {
                $this->filesystem->createDirectory($path, $mode);
            } catch (InstallerWriteFailed) {
                $identity = $this->filesystem->identity($layout->path($current));
                if (null !== $identity && $identity->isDirectory()) {
                    continue;
                }

                throw InstallerWriteFailed::writeFailed();
            }

            $identity = $this->requiredDirectoryIdentity($layout->path($current));
            $createdDirectories[] = ['path' => $current, 'identity' => $identity];
        }
    }

    private function requiredDirectoryIdentity(string $path): FilesystemIdentity
    {
        $identity = $this->filesystem->identity($path);
        if (null === $identity || !$identity->isDirectory()) {
            throw InstallerWriteFailed::writeFailed();
        }

        return $identity;
    }

    private function requiredRegularFileIdentity(string $path): FilesystemIdentity
    {
        $identity = $this->filesystem->identity($path);
        if (null === $identity || !$identity->isRegularFile()) {
            throw InstallerWriteFailed::writeFailed();
        }

        return $identity;
    }

    private function removeStagingDirectory(ProjectLayout $layout, string $relativePath): void
    {
        $path = $layout->path($relativePath);
        $identity = $this->filesystem->identity($path);
        if (null === $identity) {
            return;
        }

        if (!$identity->isDirectory()) {
            throw InstallerWriteFailed::writeFailed();
        }

        foreach ($this->filesystem->entries($path) as $entry) {
            $childRelative = $relativePath.'/'.$entry;
            $child = $layout->path($childRelative);
            $childIdentity = $this->filesystem->identity($child);
            if (null !== $childIdentity && $childIdentity->isDirectory()) {
                $this->removeStagingDirectory($layout, $childRelative);
            } elseif (null !== $childIdentity) {
                $this->filesystem->remove($child);
            }
        }

        $finalPath = $layout->path($relativePath);
        if (!$identity->equals($this->filesystem->identity($finalPath))) {
            throw InstallerWriteFailed::writeFailed();
        }

        $this->filesystem->removeDirectory($finalPath);
    }

    private function primaryOutcome(\Throwable $failure): string
    {
        if ($failure instanceof InstallerWriteFailed) {
            return $failure->outcome();
        }

        return $failure instanceof InstallerConflict ? 'conflict' : 'write-failed';
    }

    private function safePrimaryFailure(\Throwable $failure): InstallerConflict|InstallerWriteFailed
    {
        if ($failure instanceof InstallerConflict) {
            return $failure;
        }

        if ($failure instanceof InstallerWriteFailed && 'validation-failed' === $failure->outcome()) {
            return InstallerWriteFailed::validationFailed();
        }

        return InstallerWriteFailed::writeFailed();
    }
}
