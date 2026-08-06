<?php

declare(strict_types=1);

namespace Te4g\Starter\Console;

use Symfony\Component\Console\Application;

final class StarterApplication
{
    public static function create(): Application
    {
        $application = new Application('te4g:starter');
        $application->addCommand(new InstallCommand());

        return $application;
    }
}
