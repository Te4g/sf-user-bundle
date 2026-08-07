<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Package;

use PHPUnit\Framework\TestCase;

final class PackageSurfaceTest extends TestCase
{
    public function testPackageHasNoRuntimeBundleSurface(): void
    {
        $contents = file_get_contents(__DIR__.'/../../../composer.json');
        self::assertIsString($contents);
        $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('te4g/starter', $composer['name']);
        self::assertSame(['Te4g\\Starter\\' => 'src/Starter/'], $composer['autoload']['psr-4']);
        self::assertDirectoryDoesNotExist(__DIR__.'/../../../flex');
        self::assertFileDoesNotExist(__DIR__.'/../../../src/Te4gUserBundle.php');
        self::assertFileExists(__DIR__.'/../../../THIRD_PARTY_NOTICES.md');
    }

    public function testSecurityDocumentationMatchesTheCredentialBearingResetRoute(): void
    {
        $controller = file_get_contents(
            __DIR__.'/../../../resources/application/src/Controller/PasswordResetController.php',
        );
        self::assertIsString($controller);
        $matches = [];
        if (1 !== preg_match(
            "/#\[Route\('([^']+)', name: 'app_forgot_password_reset'/",
            $controller,
            $matches,
        )) {
            self::fail('The generated password-reset route could not be read.');
        }
        $resetPath = $matches[1];

        $securityGuide = file_get_contents(__DIR__.'/../../../docs/security.md');
        self::assertIsString($securityGuide);
        self::assertStringContainsString('`'.$resetPath.'`', $securityGuide);

        $currentDocumentation = [$securityGuide];
        $readme = file_get_contents(__DIR__.'/../../../README.md');
        self::assertIsString($readme);
        $currentDocumentation[] = $readme;
        foreach (glob(__DIR__.'/../../../docs/*.md') ?: [] as $path) {
            $document = file_get_contents($path);
            self::assertIsString($document, $path);
            $currentDocumentation[] = $document;
        }

        self::assertStringNotContainsString('/reset-password/{token}', implode("\n", $currentDocumentation));
    }

    public function testDocumentationExplainsTheGeneratedLocaleContract(): void
    {
        $readme = file_get_contents(__DIR__.'/../../../README.md');
        self::assertIsString($readme);
        self::assertStringContainsString('ext-intl', $readme);
        self::assertStringContainsString('messages.en.xlf', $readme);
        self::assertStringContainsString('messages.fr.xlf', $readme);
        self::assertStringContainsString('User.locale', $readme);

        $customization = file_get_contents(__DIR__.'/../../../docs/customization.md');
        self::assertIsString($customization);
        foreach ([
            'User.locale',
            'app_locale',
            'Accept-Language',
            'app.supported_locales',
            '%app.supported_locales%',
            'messages.fr.xlf',
            'validators.fr.xlf',
        ] as $expected) {
            self::assertStringContainsString($expected, $customization);
        }
    }

    public function testSecurityDocumentationExplainsTheLocaleCookieBoundary(): void
    {
        $securityGuide = file_get_contents(__DIR__.'/../../../docs/security.md');
        self::assertIsString($securityGuide);
        self::assertStringContainsString('app_locale', $securityGuide);
        self::assertStringContainsString('allowlisted', $securityGuide);
        self::assertStringContainsString('HTTP-only', $securityGuide);
        self::assertStringContainsString('SameSite=Lax', $securityGuide);
        self::assertStringContainsString('User.locale', $securityGuide);
    }
}
