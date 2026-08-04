<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

final readonly class FlexBootstrap
{
    public const string CONTENTS = "te4g_user:\n    enabled: false\n";
    public const string RELATIVE_PATH = 'config/packages/te4g_user.yaml';

    public static function matches(
        ?InstallationManifest $manifest,
        string $relativePath,
        string $contents,
    ): bool {
        return null === $manifest
            && self::RELATIVE_PATH === $relativePath
            && self::CONTENTS === $contents;
    }
}
