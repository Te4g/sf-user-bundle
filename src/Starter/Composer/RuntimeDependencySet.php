<?php

declare(strict_types=1);

namespace Te4g\Starter\Composer;

final class RuntimeDependencySet
{
    /** @return list<string> */
    public static function runtime(): array
    {
        return [
            'doctrine/doctrine-bundle:^3.3',
            'doctrine/doctrine-migrations-bundle:^3.4 || ^4.0',
            'doctrine/orm:3.6.7',
            'ext-intl:*',
            'ext-mbstring:*',
            'symfony/asset:^8.1',
            'symfony/asset-mapper:^8.1',
            'symfony/clock:^8.1',
            'symfony/form:^8.1',
            'symfony/mailer:^8.1',
            'symfony/password-hasher:^8.1',
            'symfony/rate-limiter:^8.1',
            'symfony/security-bundle:^8.1',
            'symfony/security-csrf:^8.1',
            'symfony/stimulus-bundle:^3.3',
            'symfony/translation:^8.1',
            'symfony/twig-bundle:^8.1',
            'symfony/ux-icons:^3.3',
            'symfony/ux-turbo:^3.3',
            'symfony/ux-twig-component:^3.3',
            'symfony/validator:^8.1',
            'symfonycasts/reset-password-bundle:^1.25',
            'symfonycasts/tailwind-bundle:^1.0',
            'symfonycasts/verify-email-bundle:^1.18',
            'tales-from-a-dev/twig-tailwind-extra:^1.0',
            'twig/extra-bundle:^3.24',
            'twig/html-extra:^3.24',
        ];
    }

    /** @return list<string> */
    public static function development(): array
    {
        return [
            'symfony/maker-bundle:^1.64',
            'symfony/test-pack:^1.2',
        ];
    }
}
