<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerWriteFailed;

final class InstallerFilesystem
{
    public function identity(string $path): ?FilesystemIdentity
    {
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }

        $stat = $this->guarded(static fn (): array|false => lstat($path));
        if (false === $stat) {
            throw InstallerWriteFailed::writeFailed();
        }

        return FilesystemIdentity::fromStat($stat);
    }

    public function read(string $path): string
    {
        $contents = $this->guarded(static fn (): string|false => file_get_contents($path));
        if (false === $contents) {
            throw InstallerWriteFailed::writeFailed();
        }

        return $contents;
    }

    public function mode(string $path): int
    {
        $mode = $this->guarded(static fn (): int|false => fileperms($path));
        if (false === $mode) {
            throw InstallerWriteFailed::writeFailed();
        }

        return $mode & 0777;
    }

    public function hash(string $path): string
    {
        $hash = $this->guarded(static fn (): string|false => hash_file('sha256', $path));
        if (false === $hash) {
            throw InstallerWriteFailed::writeFailed();
        }

        return $hash;
    }

    public function createDirectory(string $path, int $mode): void
    {
        $created = $this->guarded(static fn (): bool => mkdir($path, $mode));
        if (!$created) {
            throw InstallerWriteFailed::writeFailed();
        }
    }

    public function writeExclusive(string $path, string $contents, int $mode): void
    {
        $handle = $this->guarded(static fn () => fopen($path, 'x+b'));
        if (false === $handle) {
            throw InstallerWriteFailed::writeFailed();
        }

        try {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = $this->guarded(static fn (): int|false => fwrite($handle, substr($contents, $offset)));
                if (false === $written || 0 === $written) {
                    throw InstallerWriteFailed::writeFailed();
                }

                $offset += $written;
            }

            $this->chmod($path, $mode);
        } finally {
            $this->guarded(static fn (): bool => fclose($handle));
        }
    }

    public function hardLink(string $source, string $target): void
    {
        $linked = $this->guarded(static fn (): bool => link($source, $target));
        if (!$linked) {
            throw InstallerWriteFailed::writeFailed();
        }
    }

    public function rename(string $source, string $target): void
    {
        $renamed = $this->guarded(static fn (): bool => rename($source, $target));
        if (!$renamed) {
            throw InstallerWriteFailed::writeFailed();
        }
    }

    public function chmod(string $path, int $mode): void
    {
        $changed = $this->guarded(static fn (): bool => chmod($path, $mode));
        if (!$changed) {
            throw InstallerWriteFailed::writeFailed();
        }
    }

    public function remove(string $path): void
    {
        $removed = $this->guarded(static fn (): bool => unlink($path));
        if (!$removed) {
            throw InstallerWriteFailed::writeFailed();
        }
    }

    /** @return list<string> */
    public function entries(string $path): array
    {
        $entries = $this->guarded(static fn (): array|false => scandir($path));
        if (false === $entries) {
            throw InstallerWriteFailed::writeFailed();
        }

        return array_values(array_diff($entries, ['.', '..']));
    }

    public function removeDirectory(string $path): void
    {
        $removed = $this->guarded(static fn (): bool => rmdir($path));
        if (!$removed) {
            throw InstallerWriteFailed::writeFailed();
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function guarded(callable $operation): mixed
    {
        set_error_handler(static function (): never {
            throw new \ErrorException('Installer filesystem operation failed.');
        });

        try {
            return $operation();
        } catch (\Throwable) {
            throw InstallerWriteFailed::writeFailed();
        } finally {
            restore_error_handler();
        }
    }
}
