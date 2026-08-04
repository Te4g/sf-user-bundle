<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class FlexRecipeTest extends TestCase
{
    public function testRecipeRegistersDisabledBundleBootstrap(): void
    {
        $projectDirectory = dirname(__DIR__, 2);
        $manifestPath = $projectDirectory.'/flex/manifest.json';
        $configPath = $projectDirectory.'/flex/config/packages/te4g_user.yaml';
        self::assertFileExists($manifestPath);
        self::assertFileExists($configPath);

        $manifest = json_decode(
            $this->read($manifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame([
            'bundles' => [
                'Te4g\\UserBundle\\Te4gUserBundle' => ['all'],
            ],
            'copy-from-recipe' => [
                'config/' => '%CONFIG_DIR%/',
            ],
            'aliases' => ['te4g-user'],
        ], $manifest);

        self::assertSame(
            ['te4g_user' => ['enabled' => false]],
            Yaml::parseFile($configPath),
        );
    }

    public function testRepositoryLocalRecipesContribHandoffIsTheCheckerCompatibleProjectionOfTheSource(): void
    {
        $projectDirectory = dirname(__DIR__, 2);
        $handoffDirectory = $projectDirectory.'/recipes-contrib/te4g/user-bundle/0.1';
        $handoffManifestPath = $handoffDirectory.'/manifest.json';
        $handoffConfigPath = $handoffDirectory.'/config/packages/te4g_user.yaml';
        self::assertFileExists($handoffManifestPath);
        self::assertFileExists($handoffConfigPath);

        $sourceManifest = json_decode(
            $this->read($projectDirectory.'/flex/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $handoffManifest = json_decode(
            $this->read($handoffManifestPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertArrayHasKey('aliases', $sourceManifest);
        self::assertArrayNotHasKey('aliases', $handoffManifest);
        unset($sourceManifest['aliases']);
        self::assertSame($sourceManifest, $handoffManifest);
        self::assertSame(
            $this->read($projectDirectory.'/flex/config/packages/te4g_user.yaml'),
            $this->read($handoffConfigPath),
        );
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
