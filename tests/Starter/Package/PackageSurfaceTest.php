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
    }
}
