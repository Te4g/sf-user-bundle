<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

interface InstallationPostWriteRaceHookInterface
{
    public function afterTargetMutation(ProjectLayout $layout, PlannedFile $file): void;
}
