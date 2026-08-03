<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerConflict;
use Te4g\UserBundle\Exception\InstallerWriteFailed;

final class ProjectLock
{
    /** @var resource|null */
    private $handle;

    private bool $released = false;

    /** @param resource $handle */
    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    public function __destruct()
    {
        $this->releaseQuietly();
    }

    public static function acquire(ProjectLayout $layout, InstallerFilesystem $filesystem): self
    {
        self::ensureDirectory($layout, $filesystem, 'var', 0755);
        self::ensureDirectory($layout, $filesystem, 'var/te4g-user-installer', 0700);
        $path = $layout->path('var/te4g-user-installer/install.lock');
        $handle = null;

        if (!file_exists($path) && !is_link($path)) {
            try {
                $handle = $filesystem->guarded(static fn () => fopen($path, 'x+b'));
            } catch (InstallerWriteFailed) {
                $handle = null;
            }

            if (false !== $handle && null !== $handle) {
                $filesystem->chmod($path, 0600);
            }
        }

        if (null === $handle || false === $handle) {
            $identity = $filesystem->identity($path);
            if (null === $identity || !$identity->isRegularFile() || is_link($path)) {
                throw InstallerConflict::unsafePath();
            }

            $handle = $filesystem->guarded(static fn () => fopen($path, 'r+b'));
            if (false === $handle) {
                throw InstallerConflict::installerLocked();
            }
        }

        try {
            $handleStat = $filesystem->guarded(static fn (): array|false => fstat($handle));
            $pathIdentity = $filesystem->identity($path);
            if (false === $handleStat
                || null === $pathIdentity
                || !FilesystemIdentity::fromStat($handleStat)->equals($pathIdentity)
            ) {
                throw InstallerConflict::unsafePath();
            }

            $locked = $filesystem->guarded(static fn (): bool => flock($handle, LOCK_EX | LOCK_NB));
            if (!$locked) {
                throw InstallerConflict::installerLocked();
            }

            $lockedHandleStat = $filesystem->guarded(static fn (): array|false => fstat($handle));
            $lockedPathIdentity = $filesystem->identity($layout->path('var/te4g-user-installer/install.lock'));
            if (false === $lockedHandleStat
                || null === $lockedPathIdentity
                || !FilesystemIdentity::fromStat($lockedHandleStat)->equals($lockedPathIdentity)
            ) {
                throw InstallerConflict::unsafePath();
            }

            return new self($handle);
        } catch (\Throwable $exception) {
            self::closeQuietly($handle);

            if ($exception instanceof InstallerConflict) {
                throw $exception;
            }

            throw InstallerWriteFailed::writeFailed();
        }
    }

    public function release(InstallerFilesystem $filesystem): void
    {
        if ($this->released || null === $this->handle) {
            return;
        }

        $handle = $this->handle;
        try {
            $unlocked = $filesystem->guarded(static fn (): bool => flock($handle, LOCK_UN));
            $closed = $filesystem->guarded(static fn (): bool => fclose($handle));
            if (!$unlocked || !$closed) {
                throw InstallerWriteFailed::writeFailed();
            }
        } catch (\Throwable) {
            $this->handle = null;
            $this->released = true;
            throw InstallerWriteFailed::writeFailed();
        }

        $this->handle = null;
        $this->released = true;
    }

    public function releaseQuietly(): void
    {
        if ($this->released || null === $this->handle) {
            return;
        }

        self::closeQuietly($this->handle);
        $this->handle = null;
        $this->released = true;
    }

    /** @param resource $handle */
    private static function closeQuietly($handle): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            flock($handle, LOCK_UN);
            fclose($handle);
        } finally {
            restore_error_handler();
        }
    }

    private static function ensureDirectory(
        ProjectLayout $layout,
        InstallerFilesystem $filesystem,
        string $relativePath,
        int $mode,
    ): void {
        $path = $layout->path($relativePath);
        $identity = $filesystem->identity($path);
        if (null !== $identity) {
            if (!$identity->isDirectory()) {
                throw InstallerConflict::unsafePath();
            }

            return;
        }

        try {
            $filesystem->createDirectory($path, $mode);
        } catch (InstallerWriteFailed) {
            $identity = $filesystem->identity($layout->path($relativePath));
            if (null === $identity || !$identity->isDirectory()) {
                throw InstallerWriteFailed::writeFailed();
            }
        }
    }
}
