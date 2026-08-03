<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerConflict;
use Te4g\UserBundle\Exception\InstallerWriteFailed;

final readonly class StagedWriter
{
    public function __construct(private InstallationValidatorInterface $validator)
    {
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

        $this->assertTargetsUnchanged($layout, $plan->files());

        $stagingRelative = 'var/te4g-user-installer/'.bin2hex(random_bytes(16));
        $stagingPath = $layout->path($stagingRelative);
        $createdStagingDirectories = [];
        $snapshots = [];
        $createdTargetDirectories = [];
        $result = null;

        try {
            $this->createDirectory($layout, $stagingRelative, 0700, $createdStagingDirectories);
            $stagedFiles = $this->stage($layout, $stagingRelative, $plan->files(), $createdStagingDirectories);

            foreach ($stagedFiles as $relativePath => $stagedPath) {
                $this->validator->validate($stagedPath, $relativePath);
            }

            foreach ($plan->files() as $file) {
                $this->assertTargetUnchanged($layout, $file);
                $target = $layout->path($file->relativePath());
                $originalContents = null;
                $originalMode = null;
                if (file_exists($target)) {
                    $originalContents = file_get_contents($target);
                    if (false === $originalContents) {
                        throw InstallerWriteFailed::writeFailed();
                    }

                    $permissions = fileperms($target);
                    if (false === $permissions) {
                        throw InstallerWriteFailed::writeFailed();
                    }

                    $originalMode = $permissions & 0777;
                }

                $parentRelative = dirname(str_replace('\\', '/', $file->relativePath()));
                if ('.' !== $parentRelative) {
                    $this->createDirectory($layout, $parentRelative, 0755, $createdTargetDirectories);
                }

                $this->assertTargetUnchanged($layout, $file);
                $target = $layout->path($file->relativePath());
                $stagedPath = $stagedFiles[$file->relativePath()];
                $snapshots[] = [
                    'path' => $file->relativePath(),
                    'contents' => $originalContents,
                    'mode' => $originalMode,
                ];
                if (!rename($stagedPath, $target)) {
                    throw InstallerWriteFailed::writeFailed();
                }

                $targetMode = $originalMode ?? 0644;
                if (!chmod($target, $targetMode)) {
                    throw InstallerWriteFailed::writeFailed();
                }

            }

            $result = new InstallationResult($layout, $snapshots, $createdTargetDirectories);
            foreach ($plan->files() as $file) {
                $this->validator->validate($layout->path($file->relativePath()), $file->relativePath());
            }

            return $result;
        } catch (InstallerConflict $exception) {
            $result ??= $this->partialResult($layout, $snapshots, $createdTargetDirectories);
            if (null !== $result) {
                $result->rollback();
            }

            throw $exception;
        } catch (InstallerWriteFailed $exception) {
            $result ??= $this->partialResult($layout, $snapshots, $createdTargetDirectories);
            if (null !== $result) {
                try {
                    $result->rollback();
                } catch (InstallerWriteFailed) {
                }

                throw InstallerWriteFailed::writeFailed($exception);
            }

            throw $exception;
        } catch (\Throwable $exception) {
            $result ??= $this->partialResult($layout, $snapshots, $createdTargetDirectories);
            if (null !== $result) {
                try {
                    $result->rollback();
                } catch (InstallerWriteFailed) {
                }
            }

            throw InstallerWriteFailed::writeFailed($exception);
        } finally {
            $this->removeStagingDirectory($stagingPath);
        }
    }

    /**
     * @param list<array{path: string, contents: string|null, mode: int|null}> $snapshots
     * @param list<string>                                                     $createdDirectories
     */
    private function partialResult(
        ProjectLayout $layout,
        array $snapshots,
        array $createdDirectories,
    ): ?InstallationResult {
        if ([] === $snapshots && [] === $createdDirectories) {
            return null;
        }

        return new InstallationResult($layout, $snapshots, $createdDirectories);
    }

    /** @param list<PlannedFile> $files */
    private function assertTargetsUnchanged(ProjectLayout $layout, array $files): void
    {
        foreach ($files as $file) {
            $this->assertTargetUnchanged($layout, $file);
        }
    }

    private function assertTargetUnchanged(ProjectLayout $layout, PlannedFile $file): void
    {
        $target = $layout->path($file->relativePath());
        if ($file->isCreate()) {
            if (file_exists($target) || is_link($target)) {
                throw InstallerConflict::forPath($file->relativePath(), 'create-target-exists');
            }

            return;
        }

        if (!is_file($target) || is_link($target)) {
            throw InstallerConflict::forPath($file->relativePath(), 'managed-target-missing');
        }

        $contents = file_get_contents($target);
        if (false === $contents || !hash_equals((string) $file->priorHash(), hash('sha256', $contents))) {
            throw InstallerConflict::forPath($file->relativePath(), 'managed-target-changed');
        }
    }

    /**
     * @param list<PlannedFile> $files
     * @param list<string>      $createdDirectories
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
            $parent = dirname($stagedRelative);
            $this->createDirectory($layout, $parent, 0700, $createdDirectories);
            $path = $layout->path($stagedRelative);
            if (false === file_put_contents($path, $file->contents(), LOCK_EX) || !chmod($path, 0600)) {
                throw InstallerWriteFailed::writeFailed();
            }

            $staged[$relativePath] = $path;
        }

        return $staged;
    }

    /** @param list<string> $createdDirectories */
    private function createDirectory(ProjectLayout $layout, string $relativePath, int $mode, array &$createdDirectories): void
    {
        $relativePath = $layout->normalizeRelativePath($relativePath);
        $current = '';
        foreach (explode('/', $relativePath) as $segment) {
            $current = '' === $current ? $segment : $current.'/'.$segment;
            $path = $layout->path($current);
            if (is_dir($path)) {
                continue;
            }

            if (file_exists($path) || is_link($path) || !mkdir($path, $mode)) {
                throw InstallerWriteFailed::writeFailed();
            }

            $createdDirectories[] = $current;
        }
    }

    private function removeStagingDirectory(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (!is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $this->removeStagingDirectory($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
