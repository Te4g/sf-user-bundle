<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

final readonly class NullInstallationRaceHook implements InstallationCreateRaceHookInterface, InstallationRaceHookInterface
{
    public function beforeTargetWrite(ProjectLayout $layout, PlannedFile $file): void
    {
    }

    public function beforeRollback(ProjectLayout $layout, string $relativePath): void
    {
    }

    public function beforeCreateLink(ProjectLayout $layout, PlannedFile $file): void
    {
    }
}
