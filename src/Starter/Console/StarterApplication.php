<?php

declare(strict_types=1);

namespace Te4g\Starter\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\HttpKernel\Kernel;
use Te4g\Starter\Composer\ProcessComposerRunner;
use Te4g\Starter\Composer\RuntimeDependencyInstaller;
use Te4g\Starter\Generation\ReplacementPolicy;
use Te4g\Starter\Generation\ResourceCatalog;
use Te4g\Starter\Generation\StagedFileWriter;
use Te4g\Starter\Installation\ApplicationValidator;
use Te4g\Starter\Installation\StarterInstaller;
use Te4g\Starter\Process\SymfonyProcessRunner;
use Te4g\Starter\Project\ProjectInspector;

final class StarterApplication
{
    public static function create(?string $workingDirectory = null): Application
    {
        $packageRoot = dirname(__DIR__, 3);
        $processRunner = new SymfonyProcessRunner();
        $installer = new StarterInstaller(
            new ProjectInspector(PHP_VERSION, Kernel::VERSION),
            new ResourceCatalog($packageRoot.'/resources/application'),
            new ReplacementPolicy([
                $packageRoot.'/resources/baselines/webapp',
                $packageRoot.'/resources/baselines/twig-only',
            ]),
            new RuntimeDependencyInstaller(new ProcessComposerRunner()),
            $processRunner,
            new StagedFileWriter(),
            new ApplicationValidator($processRunner),
        );
        $application = new Application('te4g:starter');
        $application->addCommand(new InstallCommand($installer, $workingDirectory ?? getcwd() ?: '.'));

        return $application;
    }
}
