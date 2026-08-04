<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

interface MigrationGeneratorInterface
{
    public function generate(string $projectDir): ?string;
}
