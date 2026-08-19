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
        self::assertSame([
            'Te4g\\Starter\\Tests\\' => 'tests/Starter/',
            'Te4g\\Starter\\Tools\\' => 'tools/',
        ], $composer['autoload-dev']['psr-4'] ?? null);
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
        self::assertStringContainsString('STARTER_LICENSES.md', $script);
        self::assertStringContainsString('END OF TERMS AND CONDITIONS', $script);
        self::assertStringContainsString('Lucide Icons and Contributors', $script);
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

    public function testPreviewScriptBuildsAndServesAFreshGeneratedApplication(): void
    {
        $scriptPath = __DIR__.'/../../../scripts/preview.sh';
        $script = file_get_contents($scriptPath);

        self::assertIsString($script);
        self::assertStringContainsString('${PROJECT_DIR}/var/preview', $script);
        self::assertStringContainsString('symfony/skeleton:8.1.*', $script);
        self::assertStringContainsString('composer require webapp', $script);
        self::assertStringContainsString('repositories.te4g-starter', $script);
        self::assertStringContainsString('\"symlink\":true', $script);
        self::assertStringContainsString('composer require \'te4g/starter:@dev\'', $script);
        self::assertStringNotContainsString('te4g/starter:0.1.0', $script);
        self::assertStringContainsString('vendor/bin/te4g-starter install', $script);
        self::assertStringContainsString('sqlite:///%kernel.project_dir%/var/preview.db', $script);
        self::assertStringContainsString('composer remove te4g/starter', $script);
        self::assertStringContainsString('make:migration', $script);
        self::assertStringContainsString('doctrine:migrations:migrate', $script);
        self::assertStringContainsString('symfony server:start', $script);
        self::assertStringContainsString('${PREVIEW_PORT:-8001}', $script);
        self::assertStringContainsString('--port="$PREVIEW_PORT"', $script);
        self::assertStringContainsString('--no-tls', $script);
        self::assertStringContainsString('--open', $script);
        self::assertLessThan(
            strpos($script, 'make:migration'),
            strpos($script, 'vendor/bin/te4g-starter install'),
        );
        self::assertLessThan(
            strpos($script, 'symfony server:start'),
            strpos($script, 'doctrine:migrations:migrate'),
        );

        $syntaxCheck = new Process(['/bin/sh', '-n', $scriptPath], dirname(__DIR__, 3));
        $syntaxCheck->run();

        self::assertTrue($syntaxCheck->isSuccessful(), $syntaxCheck->getErrorOutput());
    }

    public function testPreviewScriptRejectsAnInvalidPortBeforeRebuilding(): void
    {
        $process = new Process(
            [__DIR__.'/../../../scripts/preview.sh'],
            dirname(__DIR__, 3),
            ['PREVIEW_PORT' => 'not-a-port'],
        );
        $process->run();

        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString(
            'PREVIEW_PORT must be an integer between 1 and 65535.',
            $process->getOutput().$process->getErrorOutput(),
        );
    }

    public function testMakefileExposesThePreviewTarget(): void
    {
        $makefile = file_get_contents(__DIR__.'/../../../Makefile');

        self::assertIsString($makefile);
        self::assertMatchesRegularExpression('/^preview:.*##/m', $makefile);
        self::assertStringContainsString('./scripts/preview.sh', $makefile);
    }
}
