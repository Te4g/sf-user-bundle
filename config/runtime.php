<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserFactoryInterface;
use Te4g\UserBundle\Factory\ConfiguredAccountUserFactory;
use Te4g\UserBundle\Manager\AccountDeletionManager;
use Te4g\UserBundle\Manager\EmailVerificationManager;
use Te4g\UserBundle\Manager\MagicLinkManager;
use Te4g\UserBundle\Manager\PasswordManager;
use Te4g\UserBundle\Manager\PasswordResetManager;
use Te4g\UserBundle\Manager\ProfileManager;
use Te4g\UserBundle\Manager\RegistrationManager;
use Te4g\UserBundle\Security\AccountUserProvider;
use Te4g\UserBundle\Security\VerifiedAccountUserChecker;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set(ConfiguredAccountUserFactory::class)
            ->arg('$userClass', '%te4g_user.user_class%')
        ->set(AccountDeletionManager::class)
            ->autowire()
            ->arg('$guards', tagged_iterator('te4g_user.account_deletion_guard'))
        ->set(EmailVerificationManager::class)
            ->autowire()
        ->set(MagicLinkManager::class)
            ->autowire()
        ->set(PasswordManager::class)
            ->autowire()
        ->set(PasswordResetManager::class)
            ->autowire()
        ->set(ProfileManager::class)
            ->autowire()
        ->set(RegistrationManager::class)
            ->autowire()
        ->set(AccountUserProvider::class)
            ->autowire()
            ->arg('$userClass', '%te4g_user.user_class%')
        ->set(VerifiedAccountUserChecker::class)
            ->autowire()
        ->alias(AccountRepositoryInterface::class, 'te4g_user.configured_account_repository')
        ->alias(AccountUserFactoryInterface::class, ConfiguredAccountUserFactory::class)
    ;
};
