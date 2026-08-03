<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Te4g\UserBundle\Configuration\UserBundleOptions;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->set(UserBundleOptions::class)
            ->factory([UserBundleOptions::class, 'fromConfiguration'])
            ->args(['%te4g_user.config%'])
            ->public()
    ;

};
