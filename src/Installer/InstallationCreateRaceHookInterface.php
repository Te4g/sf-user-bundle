<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

interface InstallationCreateRaceHookInterface
{
    public function beforeCreateLink(ProjectLayout $layout, PlannedFile $file): void;
}
