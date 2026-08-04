<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerConflict;
use Te4g\UserBundle\Exception\InstallerExecutionFailed;
use Te4g\UserBundle\Exception\InstallerRollbackIncomplete;
use Te4g\UserBundle\Exception\InstallerWriteFailed;

final class InstallerTransaction
{
    /** @var array<string, ApplicationFileState> */
    private array $expectedFiles;

    /** @var array<string, FilesystemIdentity> */
    private array $expectedDirectories;

    /** @var array<string, ExternalFileSnapshot> */
    private array $snapshots = [];

    /** @var list<string> */
    private array $snapshotOrder = [];

    /** @var array<string, FilesystemIdentity> */
    private array $createdDirectories = [];

    /** @var list<string> */
    private array $lastChangedPaths = [];

    private ?InstallationResult $stagedResult = null;
    private bool $closed = false;

    private readonly InstallerFilesystem $filesystem;
    private readonly FilesystemIdentity $projectRootIdentity;

    public function __construct(
        private readonly ProjectLayout $layout,
        ?InstallerFilesystem $filesystem = null,
    ) {
        $this->filesystem = $filesystem ?? new InstallerFilesystem();
        $projectRootIdentity = $this->filesystem->identity($layout->projectDir());
        if (null === $projectRootIdentity || !$projectRootIdentity->isDirectory()) {
            throw InstallerExecutionFailed::failed();
        }
        $this->projectRootIdentity = $projectRootIdentity;
        [$this->expectedFiles, $this->expectedDirectories] = $this->inventory();
    }

    /** @param list<string> $plannedPaths */
    public function attach(InstallationResult $result, array $plannedPaths): void
    {
        if (null !== $this->stagedResult || $this->closed) {
            throw InstallerExecutionFailed::failed();
        }

        $this->stagedResult = $result;
        [$afterFiles, $afterDirectories] = $this->inventory();
        $changed = $this->changedPaths($this->expectedFiles, $afterFiles);
        sort($plannedPaths, SORT_STRING);
        if ($changed !== $plannedPaths) {
            throw InstallerConflict::forPathless('unexpected-staged-change');
        }

        $this->expectedFiles = $afterFiles;
        $this->expectedDirectories = $afterDirectories;
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     * @param list<string>  $allowedExactPaths
     * @param list<string>  $allowedPrefixes
     *
     * @return T
     */
    public function runStep(
        callable $operation,
        array $allowedExactPaths = [],
        array $allowedPrefixes = [],
        int $maximumChanges = 0,
        bool $createsOnly = false,
    ): mixed {
        $this->assertOpenAndUnchanged();
        $originalContents = $this->captureAllowedContents($allowedExactPaths, $allowedPrefixes, $createsOnly);
        $result = null;
        $failed = false;
        try {
            $result = $operation();
        } catch (\Throwable) {
            $failed = true;
        }

        [$afterFiles, $afterDirectories] = $this->inventory();
        $changed = $this->changedPaths($this->expectedFiles, $afterFiles);
        $allowedChanges = [];
        $unexpectedChanges = [];
        foreach ($changed as $relativePath) {
            $before = $this->expectedFiles[$relativePath] ?? null;
            $after = $afterFiles[$relativePath] ?? null;
            $allowed = $this->isAllowed($relativePath, $allowedExactPaths, $allowedPrefixes)
                && (!$createsOnly || null === $before && null !== $after);
            if ($allowed) {
                $allowedChanges[] = $relativePath;
            } else {
                $unexpectedChanges[] = $relativePath;
            }
        }
        $unexpectedDirectories = $this->unexpectedDirectoryChanges(
            $this->expectedDirectories,
            $afterDirectories,
            $allowedChanges,
        );

        foreach ($allowedChanges as $relativePath) {
            [$parentRelativePath, $parentIdentity] = $this->parentSnapshot(
                $relativePath,
                $this->expectedDirectories,
                $afterDirectories,
            );
            $this->recordChange(
                $relativePath,
                $this->expectedFiles[$relativePath] ?? null,
                $originalContents[$relativePath] ?? null,
                $afterFiles[$relativePath] ?? null,
                $parentRelativePath,
                $parentIdentity,
            );
        }

        $this->recordCreatedDirectories($this->expectedDirectories, $afterDirectories, $allowedChanges);
        $this->lastChangedPaths = $allowedChanges;

        if ([] === $unexpectedChanges && [] === $unexpectedDirectories && count($allowedChanges) <= $maximumChanges) {
            $this->expectedFiles = $afterFiles;
            $this->expectedDirectories = $afterDirectories;
        }

        if ($failed || [] !== $unexpectedChanges || [] !== $unexpectedDirectories || count($allowedChanges) > $maximumChanges) {
            throw InstallerExecutionFailed::failed();
        }

        return $result;
    }

    public function write(string $relativePath, string $contents): void
    {
        $relativePath = $this->layout->normalizeRelativePath($relativePath);
        $this->runStep(
            fn () => $this->atomicWrite($relativePath, $contents),
            [$relativePath],
            [],
            1,
        );
    }

    /** @return list<string> */
    public function lastChangedPaths(): array
    {
        return $this->lastChangedPaths;
    }

    /** @return list<string> */
    public function externallyWrittenPaths(): array
    {
        $paths = array_keys($this->snapshots);
        sort($paths, SORT_STRING);

        return $paths;
    }

    public function commit(): void
    {
        $this->assertOpenAndUnchanged();
        $this->stagedResult?->commit();
        $this->closed = true;
    }

    public function rollback(): void
    {
        if ($this->closed) {
            throw InstallerRollbackIncomplete::requested('operation-failed');
        }

        $rollbackOutcome = null;
        foreach (array_reverse($this->snapshotOrder) as $relativePath) {
            try {
                $this->rollbackExternalFile($this->snapshots[$relativePath]);
            } catch (InstallerConflict) {
                $rollbackOutcome ??= 'ownership-conflict';
            } catch (\Throwable) {
                $rollbackOutcome = 'operation-failed';
            }
        }

        $directories = array_keys($this->createdDirectories);
        usort($directories, static fn (string $left, string $right): int => substr_count($right, '/') <=> substr_count($left, '/'));
        foreach ($directories as $relativePath) {
            try {
                $path = $this->layout->path($relativePath);
                $identity = $this->filesystem->identity($path);
                if (null === $identity) {
                    continue;
                }
                if (!$this->createdDirectories[$relativePath]->equals($identity)) {
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
            $this->stagedResult?->rollback();
        } catch (InstallerRollbackIncomplete $exception) {
            $rollbackOutcome = $exception->rollbackOutcome();
        } catch (\Throwable) {
            $rollbackOutcome = 'operation-failed';
        }

        $this->closed = true;
        if (null !== $rollbackOutcome) {
            throw InstallerRollbackIncomplete::requested($rollbackOutcome);
        }
    }

    private function assertOpenAndUnchanged(): void
    {
        if ($this->closed || null === $this->stagedResult) {
            throw InstallerExecutionFailed::failed();
        }

        [$files, $directories] = $this->inventory();
        if ([] !== $this->changedPaths($this->expectedFiles, $files)
            || [] !== $this->changedDirectories($this->expectedDirectories, $directories)
        ) {
            throw InstallerConflict::forPathless('concurrent-application-change');
        }
    }

    /**
     * @param list<string> $exact
     * @param list<string> $prefixes
     *
     * @return array<string, string>
     */
    private function captureAllowedContents(array $exact, array $prefixes, bool $createsOnly): array
    {
        if ($createsOnly) {
            return [];
        }

        $contents = [];
        foreach ($this->expectedFiles as $relativePath => $state) {
            if ('file' !== $state->type() || !$this->isAllowed($relativePath, $exact, $prefixes)) {
                continue;
            }

            $path = $this->layout->path($relativePath);
            $value = $this->filesystem->read($path);
            if (strlen($value) > 16 * 1024 * 1024) {
                throw InstallerExecutionFailed::failed();
            }
            $contents[$relativePath] = $value;
        }

        return $contents;
    }

    /**
     * @param list<string> $exact
     * @param list<string> $prefixes
     */
    private function isAllowed(string $relativePath, array $exact, array $prefixes): bool
    {
        if (in_array($relativePath, $exact, true)) {
            return true;
        }
        foreach ($prefixes as $prefix) {
            if (str_starts_with($relativePath, rtrim($prefix, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private function recordChange(
        string $relativePath,
        ?ApplicationFileState $before,
        ?string $originalContents,
        ?ApplicationFileState $after,
        ?string $parentRelativePath,
        FilesystemIdentity $parentIdentity,
    ): void {
        if (isset($this->snapshots[$relativePath])) {
            $this->assertExternalParentOwned($this->snapshots[$relativePath]);
            $this->snapshots[$relativePath]->updateWrittenState($after);

            return;
        }

        if (null !== $before && 'file' === $before->type() && null === $originalContents) {
            throw InstallerExecutionFailed::failed();
        }

        $this->snapshots[$relativePath] = new ExternalFileSnapshot(
            $relativePath,
            $before,
            $originalContents,
            $parentRelativePath,
            $parentIdentity,
            $after,
        );
        $this->snapshotOrder[] = $relativePath;
    }

    /**
     * @param array<string, FilesystemIdentity> $before
     * @param array<string, FilesystemIdentity> $after
     * @param list<string>                      $allowedChanges
     */
    private function recordCreatedDirectories(array $before, array $after, array $allowedChanges): void
    {
        foreach ($allowedChanges as $relativePath) {
            $parent = dirname($relativePath);
            while ('.' !== $parent && '' !== $parent) {
                if (!isset($before[$parent]) && !isset($this->createdDirectories[$parent]) && isset($after[$parent])) {
                    $this->createdDirectories[$parent] = $after[$parent];
                }
                $parent = dirname($parent);
            }
        }
    }

    private function atomicWrite(string $relativePath, string $contents): void
    {
        $target = $this->layout->path($relativePath);
        $current = $this->filesystem->identity($target);
        if (null !== $current && (!$current->isRegularFile() || is_link($target))) {
            throw InstallerConflict::forPath($relativePath, 'external-target-unsafe');
        }

        $temporary = $this->layout->path('var/te4g-user-installer/external-'.bin2hex(random_bytes(16)));
        try {
            $this->filesystem->writeExclusive($temporary, $contents, 0600);
            if (null === $current) {
                $this->filesystem->hardLink($temporary, $target);
                $this->filesystem->chmod($target, 0644);
                $this->filesystem->remove($temporary);
            } else {
                $mode = $this->filesystem->mode($target);
                if (!$current->equals($this->filesystem->identity($target))) {
                    throw InstallerConflict::forPath($relativePath, 'external-target-changed');
                }
                $this->filesystem->chmod($temporary, $mode);
                $this->filesystem->rename($temporary, $target);
            }
        } catch (\Throwable) {
            try {
                if (null !== $this->filesystem->identity($temporary)) {
                    $this->filesystem->remove($temporary);
                }
            } catch (\Throwable) {
            }

            throw InstallerWriteFailed::writeFailed();
        }
    }

    private function rollbackExternalFile(ExternalFileSnapshot $snapshot): void
    {
        $relativePath = $snapshot->relativePath();
        $current = $this->assertExternalOwnership($snapshot);

        $target = $this->layout->path($relativePath);
        $original = $snapshot->originalState();
        if (null === $original) {
            if (null !== $current) {
                $this->assertExternalOwnership($snapshot);
                $this->filesystem->remove($target);
            }

            return;
        }
        if ('file' !== $original->type() || null === $snapshot->originalContents()) {
            throw InstallerWriteFailed::writeFailed();
        }

        $temporary = $this->layout->path('var/te4g-user-installer/restore-'.bin2hex(random_bytes(16)));
        try {
            $this->filesystem->writeExclusive($temporary, $snapshot->originalContents(), $original->mode());
            $this->assertExternalOwnership($snapshot);
            $this->filesystem->rename($temporary, $target);
        } catch (\Throwable) {
            try {
                if (null !== $this->filesystem->identity($temporary)) {
                    $this->filesystem->remove($temporary);
                }
            } catch (\Throwable) {
            }

            throw InstallerWriteFailed::writeFailed();
        }
    }

    private function captureState(string $relativePath): ?ApplicationFileState
    {
        $path = $this->layout->path($relativePath);
        $identity = $this->filesystem->identity($path);
        if (null === $identity) {
            return null;
        }
        $stat = lstat($path);
        if (!is_array($stat)) {
            throw InstallerWriteFailed::writeFailed();
        }

        if (is_link($path)) {
            $link = readlink($path);
            if (!is_string($link)) {
                throw InstallerWriteFailed::writeFailed();
            }

            return new ApplicationFileState($relativePath, 'link', hash('sha256', $link), (int) $stat['mode'] & 0777, $identity);
        }
        if (!is_file($path)) {
            throw InstallerWriteFailed::writeFailed();
        }

        return new ApplicationFileState(
            $relativePath,
            'file',
            $this->filesystem->hash($path),
            (int) $stat['mode'] & 0777,
            $identity,
        );
    }

    /**
     * @param array<string, FilesystemIdentity> $beforeDirectories
     * @param array<string, FilesystemIdentity> $afterDirectories
     *
     * @return array{?string, FilesystemIdentity}
     */
    private function parentSnapshot(string $relativePath, array $beforeDirectories, array $afterDirectories): array
    {
        $parentRelativePath = dirname($relativePath);
        if ('.' === $parentRelativePath || '' === $parentRelativePath) {
            return [null, $this->projectRootIdentity];
        }
        $identity = $beforeDirectories[$parentRelativePath] ?? $afterDirectories[$parentRelativePath] ?? null;
        if (!$identity instanceof FilesystemIdentity || !$identity->isDirectory()) {
            throw InstallerExecutionFailed::failed();
        }

        return [$parentRelativePath, $identity];
    }

    private function assertExternalParentOwned(ExternalFileSnapshot $snapshot): void
    {
        $parent = null === $snapshot->parentRelativePath()
            ? $this->layout->projectDir()
            : $this->layout->path($snapshot->parentRelativePath());
        if (!$snapshot->parentIdentity()->equals($this->filesystem->identity($parent))) {
            throw InstallerConflict::forPath($snapshot->relativePath(), 'external-rollback-parent-changed');
        }
    }

    private function assertExternalOwnership(ExternalFileSnapshot $snapshot): ?ApplicationFileState
    {
        $this->assertExternalParentOwned($snapshot);
        $current = $this->captureState($snapshot->relativePath());
        $written = $snapshot->writtenState();
        if ((null === $written && null !== $current)
            || (null !== $written && !$written->equals($current))
        ) {
            throw InstallerConflict::forPath($snapshot->relativePath(), 'external-rollback-target-changed');
        }

        return $current;
    }

    /**
     * @return array{array<string, ApplicationFileState>, array<string, FilesystemIdentity>}
     */
    private function inventory(): array
    {
        $files = [];
        $directories = [];
        foreach (['assets', 'bin', 'config', 'migrations', 'public', 'src', 'templates', 'translations', 'var/tailwind'] as $root) {
            $path = $this->layout->path($root);
            if (!file_exists($path)) {
                continue;
            }
            if (!is_dir($path) || is_link($path)) {
                throw InstallerExecutionFailed::failed();
            }
            $this->scanDirectory($path, $root, $files, $directories);
        }

        foreach (['composer.json', 'composer.lock', 'importmap.php', 'package.json', 'tailwind.config.js'] as $rootFile) {
            try {
                $state = $this->captureState($rootFile);
            } catch (InstallerConflict) {
                throw InstallerExecutionFailed::failed();
            }
            if (null !== $state) {
                $files[$rootFile] = $state;
            }
        }

        ksort($files, SORT_STRING);
        ksort($directories, SORT_STRING);

        return [$files, $directories];
    }

    /**
     * @param array<string, ApplicationFileState> $files
     * @param array<string, FilesystemIdentity>   $directories
     */
    private function scanDirectory(string $directory, string $relativeDirectory, array &$files, array &$directories): void
    {
        $identity = $this->filesystem->identity($directory);
        if (null === $identity || !$identity->isDirectory()) {
            throw InstallerExecutionFailed::failed();
        }
        $directories[$relativeDirectory] = $identity;

        foreach ($this->filesystem->entries($directory) as $entry) {
            $relativePath = $relativeDirectory.'/'.$entry;
            $absolutePath = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_link($absolutePath) || is_file($absolutePath)) {
                $state = $this->captureState($relativePath);
                if (null === $state) {
                    throw InstallerExecutionFailed::failed();
                }
                $files[$relativePath] = $state;
            } elseif (is_dir($absolutePath)) {
                $this->scanDirectory($absolutePath, $relativePath, $files, $directories);
            } else {
                throw InstallerExecutionFailed::failed();
            }

            if (count($files) + count($directories) > 20000) {
                throw InstallerExecutionFailed::failed();
            }
        }
    }

    /**
     * @param array<string, ApplicationFileState> $before
     * @param array<string, ApplicationFileState> $after
     *
     * @return list<string>
     */
    private function changedPaths(array $before, array $after): array
    {
        $paths = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        $changed = [];
        foreach ($paths as $path) {
            $left = $before[$path] ?? null;
            $right = $after[$path] ?? null;
            if ((null === $left && null !== $right)
                || (null !== $left && !$left->equals($right))
            ) {
                $changed[] = $path;
            }
        }
        sort($changed, SORT_STRING);

        return $changed;
    }

    /**
     * @param array<string, FilesystemIdentity> $before
     * @param array<string, FilesystemIdentity> $after
     *
     * @return list<string>
     */
    private function changedDirectories(array $before, array $after): array
    {
        $paths = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        $changed = [];
        foreach ($paths as $path) {
            $left = $before[$path] ?? null;
            $right = $after[$path] ?? null;
            if ((null === $left && null !== $right)
                || (null !== $left && !$left->equals($right))
            ) {
                $changed[] = $path;
            }
        }
        sort($changed, SORT_STRING);

        return $changed;
    }

    /**
     * @param array<string, FilesystemIdentity> $before
     * @param array<string, FilesystemIdentity> $after
     * @param list<string>                      $allowedFileChanges
     *
     * @return list<string>
     */
    private function unexpectedDirectoryChanges(array $before, array $after, array $allowedFileChanges): array
    {
        $unexpected = [];
        foreach ($this->changedDirectories($before, $after) as $relativePath) {
            if (isset($before[$relativePath]) || !isset($after[$relativePath])) {
                $unexpected[] = $relativePath;

                continue;
            }

            $ownsNewDirectory = false;
            foreach ($allowedFileChanges as $allowedFile) {
                if (str_starts_with($allowedFile, $relativePath.'/')) {
                    $ownsNewDirectory = true;

                    break;
                }
            }
            if (!$ownsNewDirectory) {
                $unexpected[] = $relativePath;
            }
        }

        return $unexpected;
    }
}
