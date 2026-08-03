<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

use Te4g\UserBundle\Contract\AccountMailerInterface;
use Te4g\UserBundle\Contract\AccountRepositoryInterface;
use Te4g\UserBundle\Contract\AccountUserFactoryInterface;
use Te4g\UserBundle\Controller\AccountRequestLimiter;
use Te4g\UserBundle\Controller\LoginController;
use Te4g\UserBundle\Controller\MagicLinkController;
use Te4g\UserBundle\Controller\PasswordResetController;
use Te4g\UserBundle\Controller\RegistrationController;
use Te4g\UserBundle\Controller\VerificationController;
use Te4g\UserBundle\Factory\ConfiguredAccountUserFactory;
use Te4g\UserBundle\Form\EmailType;
use Te4g\UserBundle\Form\RegistrationType;
use Te4g\UserBundle\Form\ResetPasswordType;
use Te4g\UserBundle\Mailer\AccountMailer;
use Te4g\UserBundle\Manager\AccountDeletionManager;
use Te4g\UserBundle\Manager\EmailVerificationManager;
use Te4g\UserBundle\Manager\MagicLinkManager;
use Te4g\UserBundle\Manager\PasswordManager;
use Te4g\UserBundle\Manager\PasswordResetManager;
use Te4g\UserBundle\Manager\ProfileManager;
use Te4g\UserBundle\Manager\RegistrationManager;
use Te4g\UserBundle\Security\AccountUserProvider;
use Te4g\UserBundle\Security\VerifiedAccountUserChecker;
use Te4g\UserBundle\Util\EmailNormalizer;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set(EmailNormalizer::class)
        ->set(AccountMailer::class)
            ->autowire()
        ->set(AccountRequestLimiter::class)
            ->autowire()
            ->arg('$factory', service('limiter.te4g_user_account_requests'))
        ->set(EmailType::class)
            ->autowire()
            ->tag('form.type')
        ->set(RegistrationType::class)
            ->autowire()
            ->tag('form.type')
        ->set(ResetPasswordType::class)
            ->autowire()
            ->tag('form.type')
        ->set(LoginController::class)
            ->autowire()
            ->autoconfigure()
            ->tag('controller.service_arguments')
        ->set(MagicLinkController::class)
            ->autowire()
            ->autoconfigure()
            ->tag('controller.service_arguments')
        ->set(PasswordResetController::class)
            ->autowire()
            ->autoconfigure()
            ->tag('controller.service_arguments')
        ->set(RegistrationController::class)
            ->autowire()
            ->autoconfigure()
            ->tag('controller.service_arguments')
        ->set(VerificationController::class)
            ->autowire()
            ->autoconfigure()
            ->tag('controller.service_arguments')
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
        ->alias(AccountMailerInterface::class, AccountMailer::class)
        ->alias(AccountUserFactoryInterface::class, ConfiguredAccountUserFactory::class)
    ;
};
