<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

final class ResetPasswordRecipeBootstrap
{
    public const string RELATIVE_PATH = 'config/packages/reset_password.yaml';
    public const string CONTENTS = <<<'YAML'
        symfonycasts_reset_password:
            # Replace symfonycasts.reset_password.fake_request_repository with the full
            # namespace of the password reset request repository after it has been created.
            # i.e. App\Repository\ResetPasswordRequestRepository
            request_password_repository: symfonycasts.reset_password.fake_request_repository
        YAML."\n";

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
