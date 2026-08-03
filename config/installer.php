<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Te4g\UserBundle\Installer\InstallationPlanner;
use Te4g\UserBundle\Installer\InstallationValidator;
use Te4g\UserBundle\Installer\InstallationValidatorInterface;
use Te4g\UserBundle\Installer\ManifestStore;
use Te4g\UserBundle\Installer\MarkerEditor;
use Te4g\UserBundle\Installer\StagedWriter;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $services->set(ManifestStore::class);
    $services->set(MarkerEditor::class);
    $services->set(InstallationPlanner::class);
    $services->set(InstallationValidator::class);
    $services->alias(InstallationValidatorInterface::class, InstallationValidator::class);
    $services->set(StagedWriter::class);
};
