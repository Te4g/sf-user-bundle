<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    foreach ([
        'te4g_user_register' => '/register',
        'te4g_user_login' => '/login',
        'te4g_user_login_link_check' => '/login-link/check',
        'te4g_user_check_email' => '/check-email',
        'te4g_user_password_request' => '/forgot-password',
        'te4g_user_password_reset' => '/reset-password',
        'te4g_user_account' => '/account',
        'te4g_user_profile' => '/account/profile',
        'te4g_user_password_change' => '/account/password',
        'te4g_user_account_delete' => '/account/delete',
    ] as $name => $path) {
        $routes->add($name, $path)->controller('generated_shadcn.noop');
    }
};
