<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserFactoryInterface;
use Te4g\UserBundle\Factory\ConfiguredAccountUserFactory;
use Te4g\UserBundle\Manager\EmailVerificationManager;
use Te4g\UserBundle\Manager\RegistrationManager;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set(ConfiguredAccountUserFactory::class)
            ->arg('$userClass', '%te4g_user.user_class%')
        ->set(EmailVerificationManager::class)
            ->autowire()
        ->set(RegistrationManager::class)
            ->autowire()
        ->alias(AccountRepositoryInterface::class, 'te4g_user.configured_account_repository')
        ->alias(AccountUserFactoryInterface::class, ConfiguredAccountUserFactory::class)
    ;
};
