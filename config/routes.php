<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Te4g\UserBundle\Controller\AccountController;
use Te4g\UserBundle\Controller\DeleteAccountController;
use Te4g\UserBundle\Controller\LoginController;
use Te4g\UserBundle\Controller\MagicLinkController;
use Te4g\UserBundle\Controller\PasswordController;
use Te4g\UserBundle\Controller\PasswordResetController;
use Te4g\UserBundle\Controller\ProfileController;
use Te4g\UserBundle\Controller\RegistrationController;
use Te4g\UserBundle\Controller\VerificationController;

return static function (RoutingConfigurator $routes): void {
    $routes->add('te4g_user_register', '/register')
        ->controller([RegistrationController::class, 'register'])
        ->methods(['GET', 'POST']);

    $routes->add('te4g_user_verify_email', '/verify-email')
        ->controller([VerificationController::class, 'verify'])
        ->methods(['GET']);

    $routes->add('te4g_user_verify_resend', '/verify-email/resend')
        ->controller([VerificationController::class, 'resend'])
        ->methods(['POST']);

    $routes->add('te4g_user_login', '/login')
        ->controller([LoginController::class, 'login'])
        ->methods(['GET', 'POST']);

    $routes->add('te4g_user_login_link_request', '/login-link')
        ->controller([MagicLinkController::class, 'request'])
        ->methods(['POST']);

    $routes->add('te4g_user_logout', '/logout')
        ->controller([LoginController::class, 'logout'])
        ->methods(['POST']);

    $routes->add('te4g_user_login_link_check', '/login-link/check')
        ->controller([MagicLinkController::class, 'check'])
        ->methods(['GET', 'POST']);

    $routes->add('te4g_user_password_request', '/forgot-password')
        ->controller([PasswordResetController::class, 'request'])
        ->methods(['GET', 'POST']);

    $routes->add('te4g_user_password_reset', '/reset-password/{token}')
        ->controller([PasswordResetController::class, 'reset'])
        ->methods(['GET', 'POST']);

    $routes->add('te4g_user_check_email', '/check-email')
        ->controller([LoginController::class, 'checkEmail'])
        ->methods(['GET']);

    $routes->add('te4g_user_account', '/account')
        ->controller([AccountController::class, 'index'])
        ->methods(['GET']);

    $routes->add('te4g_user_profile', '/account/profile')
        ->controller([ProfileController::class, 'profile'])
        ->methods(['GET', 'POST']);

    $routes->add('te4g_user_email_change_confirm', '/account/email-change')
        ->controller([ProfileController::class, 'confirmEmailChange'])
        ->methods(['GET']);

    $routes->add('te4g_user_password_change', '/account/password')
        ->controller([PasswordController::class, 'change'])
        ->methods(['GET', 'POST']);

    $routes->add('te4g_user_account_delete', '/account/delete')
        ->controller([DeleteAccountController::class, 'delete'])
        ->methods(['POST']);
};
