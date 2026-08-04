<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Te4g\UserBundle\Command\InstallCommand;
use Te4g\UserBundle\Installer\CssFragmentMerger;
use Te4g\UserBundle\Installer\DoctrineMigrationGenerator;
use Te4g\UserBundle\Installer\InstallationPlanner;
use Te4g\UserBundle\Installer\InstallationRaceHookInterface;
use Te4g\UserBundle\Installer\InstallationValidator;
use Te4g\UserBundle\Installer\InstallationValidatorInterface;
use Te4g\UserBundle\Installer\InstallerProcessRunnerInterface;
use Te4g\UserBundle\Installer\ManifestStore;
use Te4g\UserBundle\Installer\MarkerEditor;
use Te4g\UserBundle\Installer\MigrationGeneratorInterface;
use Te4g\UserBundle\Installer\NullInstallationRaceHook;
use Te4g\UserBundle\Installer\OfflineIconValidator;
use Te4g\UserBundle\Installer\PreflightChecker;
use Te4g\UserBundle\Installer\SkeletonRenderer;
use Te4g\UserBundle\Installer\StagedWriter;
use Te4g\UserBundle\Installer\SymfonyInstallerProcessRunner;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $services->set(ManifestStore::class);
    $services->set(MarkerEditor::class);
    $services->set(CssFragmentMerger::class);
    $services->set(SkeletonRenderer::class);
    $services->set(InstallationPlanner::class);
    $services->set(NullInstallationRaceHook::class);
    $services->alias(InstallationRaceHookInterface::class, NullInstallationRaceHook::class);
    $services->set(InstallationValidator::class);
    $services->alias(InstallationValidatorInterface::class, InstallationValidator::class);
    $services->set(StagedWriter::class);
    $services->set(PreflightChecker::class);
    $services->set(OfflineIconValidator::class);
    $services->set(SymfonyInstallerProcessRunner::class);
    $services->alias(InstallerProcessRunnerInterface::class, SymfonyInstallerProcessRunner::class);
    $services->set(DoctrineMigrationGenerator::class);
    $services->alias(MigrationGeneratorInterface::class, DoctrineMigrationGenerator::class);
    $services->set(InstallCommand::class)
        ->arg('$projectDir', '%kernel.project_dir%');
};
