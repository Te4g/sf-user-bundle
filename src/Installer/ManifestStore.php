<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerConflict;

final readonly class ManifestStore
{
    public const RELATIVE_PATH = 'config/te4g_user.install.json';

    public function __construct(private string $bundleVersion = '0.1.0')
    {
    }

    public function load(ProjectLayout $layout): ?InstallationManifest
    {
        $path = $layout->path(self::RELATIVE_PATH);
        if (!file_exists($path)) {
            return null;
        }

        if (!is_file($path) || is_link($path)) {
            throw InstallerConflict::invalidManifest();
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            throw InstallerConflict::invalidManifest();
        }

        return InstallationManifest::fromJson($contents);
    }

    /** @param array<string, string> $files */
    public function create(array $files, \DateTimeImmutable $generatedAt): InstallationManifest
    {
        return InstallationManifest::create($this->bundleVersion, $generatedAt, $files);
    }
}
