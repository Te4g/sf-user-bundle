<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Smoke;

use PHPUnit\Framework\TestCase;

final class SmokeScriptTest extends TestCase
{
    public function testComposerPackageKeepsStarterRuntimeMinimal(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__.'/../../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($composer);
        self::assertSame([
            'php' => '^8.4',
            'ext-dom' => '*',
            'symfony/console' => '^8.1',
            'symfony/filesystem' => '^8.1',
            'symfony/process' => '^8.1',
            'symfony/yaml' => '^8.1',
            'twig/twig' => '^3.24',
        ], $composer['require'] ?? null);
        self::assertSame([
            'Te4g\\Starter\\' => 'src/Starter/',
        ], $composer['autoload']['psr-4'] ?? null);
        self::assertSame('src/', $composer['autoload-dev']['psr-4']['Te4g\\UserBundle\\'] ?? null);
    }

    public function testSmokeScriptProvesDatabaseIndependenceAndPackageRemoval(): void
    {
        $script = file_get_contents(__DIR__.'/../../../scripts/smoke-test.sh');

        self::assertIsString($script);
        self::assertStringContainsString('127.0.0.1:1', $script);
        self::assertStringContainsString('composer remove te4g/starter', $script);
        self::assertStringContainsString('composer config --unset repositories.te4g-starter', $script);
        self::assertStringContainsString('make:migration', $script);
        self::assertLessThan(
            strpos($script, 'make:migration'),
            strpos($script, 'te4g-starter install'),
        );
    }
}
