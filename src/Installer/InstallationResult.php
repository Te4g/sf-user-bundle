<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerWriteFailed;

final class InstallationResult
{
    private bool $rolledBack = false;

    /**
     * @param list<array{path: string, contents: string|null, mode: int|null}> $snapshots
     * @param list<string>                                                     $createdDirectories
     */
    public function __construct(
        private readonly ProjectLayout $layout,
        private readonly array $snapshots,
        private readonly array $createdDirectories,
    ) {
    }

    /** @return list<string> */
    public function writtenPaths(): array
    {
        return array_map(
            static fn (array $snapshot): string => $snapshot['path'],
            $this->snapshots,
        );
    }

    public function rollback(): void
    {
        if ($this->rolledBack) {
            return;
        }

        try {
            foreach (array_reverse($this->snapshots) as $snapshot) {
                $target = $this->layout->path($snapshot['path']);
                if (null === $snapshot['contents']) {
                    if ((file_exists($target) || is_link($target)) && !unlink($target)) {
                        throw InstallerWriteFailed::writeFailed();
                    }

                    continue;
                }

                $temporary = dirname($target).'/.te4g-user-restore-'.bin2hex(random_bytes(8));
                if (false === file_put_contents($temporary, $snapshot['contents'], LOCK_EX)) {
                    throw InstallerWriteFailed::writeFailed();
                }

                if (null !== $snapshot['mode'] && !chmod($temporary, $snapshot['mode'])) {
                    @unlink($temporary);
                    throw InstallerWriteFailed::writeFailed();
                }

                if (!rename($temporary, $target)) {
                    @unlink($temporary);
                    throw InstallerWriteFailed::writeFailed();
                }
            }

            foreach (array_reverse($this->createdDirectories) as $directory) {
                $path = $this->layout->path($directory);
                if (is_dir($path) && [] === array_diff(scandir($path) ?: [], ['.', '..'])) {
                    rmdir($path);
                }
            }
        } catch (\Throwable $exception) {
            throw InstallerWriteFailed::writeFailed($exception);
        }

        $this->rolledBack = true;
    }

    public function wasRolledBack(): bool
    {
        return $this->rolledBack;
    }
}
