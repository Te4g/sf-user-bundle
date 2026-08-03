<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

interface InstallationRaceHookInterface
{
    public function beforeTargetWrite(ProjectLayout $layout, PlannedFile $file): void;

    public function beforeRollback(ProjectLayout $layout, string $relativePath): void;
}
