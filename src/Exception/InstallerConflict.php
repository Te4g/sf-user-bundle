<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Exception;

final class InstallerConflict extends \RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $conflictCode,
        private readonly ?string $relativePath = null,
    ) {
        parent::__construct($message);
    }

    public static function unsafePath(): self
    {
        return new self('Unsafe installer path.', 'unsafe-path');
    }

    public static function invalidManifest(): self
    {
        return new self('Invalid installer manifest.', 'invalid-manifest', 'config/te4g_user.install.json');
    }

    public static function invalidMarker(): self
    {
        return new self('Invalid installer marker region.', 'invalid-marker');
    }

    public static function invalidProjectConfiguration(): self
    {
        return new self('Invalid installer project configuration.', 'invalid-project-configuration');
    }

    public static function installerLocked(): self
    {
        return new self('Installer conflict.', 'installer-locked');
    }

    public static function forPath(string $relativePath, string $code): self
    {
        return new self('Installer conflict.', $code, $relativePath);
    }

    public function code(): string
    {
        return $this->conflictCode;
    }

    public function relativePath(): ?string
    {
        return $this->relativePath;
    }
}
