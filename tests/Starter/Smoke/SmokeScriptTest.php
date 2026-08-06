<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Smoke;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

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
        self::assertStringContainsString('TE4G_TAILWIND_BINARY', $script);
        self::assertStringNotContainsString('/private/tmp', $script);
        self::assertStringNotContainsString('macos-arm64', $script);
        self::assertStringContainsString('127.0.0.1:1', $script);
        self::assertStringContainsString('composer remove te4g/starter', $script);
        self::assertStringContainsString('composer config --unset repositories.te4g-starter', $script);
        self::assertStringContainsString('make:migration', $script);
        self::assertLessThan(
            strpos($script, 'make:migration'),
            strpos($script, 'te4g-starter install'),
        );
    }

    public function testSmokeScriptRequiresTheTailwindEnvironmentBeforeStartingComposer(): void
    {
        $temporaryDirectory = sys_get_temp_dir().'/te4g-starter-smoke-policy-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryDirectory, 0700, true));
        $composer = $temporaryDirectory.'/composer';
        $marker = $temporaryDirectory.'/composer-started';
        self::assertNotFalse(file_put_contents(
            $composer,
            <<<'SH'
#!/bin/sh
: > "$SMOKE_COMPOSER_MARKER"
exit 73
SH
        ));
        self::assertTrue(chmod($composer, 0700));

        try {
            $process = new Process(
                [__DIR__.'/../../../scripts/smoke-test.sh', 'skeleton'],
                dirname(__DIR__, 3),
                [
                    'PATH' => $temporaryDirectory.PATH_SEPARATOR.getenv('PATH'),
                    'SMOKE_COMPOSER_MARKER' => $marker,
                    'TE4G_TAILWIND_BINARY' => false,
                ],
            );
            $process->run();

            self::assertFalse($process->isSuccessful());
            self::assertStringContainsString('TE4G_TAILWIND_BINARY', $process->getOutput().$process->getErrorOutput());
            self::assertFileDoesNotExist($marker, 'Composer must not start before the Tailwind binary is validated.');
        } finally {
            (new Filesystem())->remove($temporaryDirectory);
        }
    }
}
