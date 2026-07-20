<?php

declare(strict_types=1);

use Acme\Bundle\TemplateBundle\Service\ExampleService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
    ;

    $services->set(ExampleService::class)
        ->arg('$message', param('template.message'))
    ;
};
