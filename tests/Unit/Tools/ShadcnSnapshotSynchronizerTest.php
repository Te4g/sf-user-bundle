<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Te4g\UserBundle\Tools\ShadcnSnapshotSynchronizer;

final class ShadcnSnapshotSynchronizerTest extends TestCase
{
    private string $fixtureDirectory;

    protected function setUp(): void
    {
        $this->fixtureDirectory = sys_get_temp_dir().'/te4g-shadcn-sync-'.bin2hex(random_bytes(8));
        mkdir($this->fixtureDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->fixtureDirectory);
    }

    public function testSynchronizesOnlyRecipeFilesAndFixedIconsDeterministically(): void
    {
        $synchronizer = $this->synchronizer();
        $source = $this->createSourceFixture();
        $destination = $this->fixtureDirectory.'/snapshot';

        $first = $synchronizer->synchronize($source, $destination, ['card']);
        $second = $synchronizer->synchronize($source, $destination, ['card']);

        self::assertSame($first, $second);
        self::assertSame('3.3.0', $first['upstream']['version']);
        self::assertSame('f01228fe7d0e3bd26b1c27eb34b0cc1012a9b208', $first['upstream']['reference']);
        self::assertSame(['card'], $first['recipes']);
        self::assertSame([
            'assets/icons/lucide/circle-check.svg',
            'assets/icons/lucide/eye-off.svg',
            'assets/icons/lucide/eye.svg',
            'assets/icons/lucide/key-round.svg',
            'assets/icons/lucide/mail.svg',
            'assets/icons/lucide/trash-2.svg',
            'assets/icons/lucide/triangle-alert.svg',
            'templates/components/Card.html.twig',
        ], array_column($first['files'], 'path'));
        self::assertFileDoesNotExist($destination.'/templates/base.html.twig');

        foreach ($first['files'] as $file) {
            self::assertSame(hash_file('sha256', $destination.'/'.$file['path']), $file['sha256']);
        }
    }

    public function testRejectsRecipeOutsideFixedAllowlistBeforeReadingIt(): void
    {
        $source = $this->createSourceFixture();
        mkdir($source.'/vendor/symfony/ux-toolkit/kits/shadcn/../../../outside', 0777, true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported Shadcn recipe');

        $this->synchronizer()->synchronize($source, $this->fixtureDirectory.'/snapshot', ['../../../outside']);
    }

    public function testRejectsParentTraversalDeclaredByARecipe(): void
    {
        $source = $this->createSourceFixture();
        file_put_contents($source.'/vendor/symfony/ux-toolkit/kits/shadcn/card/manifest.json', <<<'JSON'
            {"copy-files":{"templates/":"../../../escaped/"}}
            JSON);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsafe snapshot path');

        $this->synchronizer()->synchronize($source, $this->fixtureDirectory.'/snapshot', ['card']);
    }

    public function testRejectsSourceSymlinkEscape(): void
    {
        $source = $this->createSourceFixture();
        $outside = $this->fixtureDirectory.'/outside.twig';
        file_put_contents($outside, 'secret');
        unlink($source.'/templates/components/Card.html.twig');
        symlink($outside, $source.'/templates/components/Card.html.twig');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('escapes the temporary project root');

        $this->synchronizer()->synchronize($source, $this->fixtureDirectory.'/snapshot', ['card']);
    }

    public function testRejectsDestinationSymlinkEscape(): void
    {
        $source = $this->createSourceFixture();
        $destination = $this->fixtureDirectory.'/snapshot';
        $outside = $this->fixtureDirectory.'/outside';
        mkdir($destination.'/templates', 0777, true);
        mkdir($outside, 0777, true);
        symlink($outside, $destination.'/templates/components');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('escapes the snapshot destination');

        $this->synchronizer()->synchronize($source, $destination, ['card']);
    }

    public function testRejectsUnknownDependencyBeforeMutatingDestination(): void
    {
        $source = $this->createSourceFixture();
        $destination = $this->fixtureDirectory.'/snapshot';
        mkdir($destination, 0777, true);
        file_put_contents($destination.'/sentinel.txt', 'unchanged');
        mkdir($source.'/vendor/symfony/ux-toolkit/kits/shadcn/unapproved/templates/components', 0777, true);
        file_put_contents($source.'/vendor/symfony/ux-toolkit/kits/shadcn/unapproved/manifest.json', <<<'JSON'
            {"copy-files":{"templates/":"templates/"}}
            JSON);
        file_put_contents($source.'/vendor/symfony/ux-toolkit/kits/shadcn/unapproved/templates/components/Unapproved.html.twig', 'unapproved');
        file_put_contents($source.'/templates/components/Unapproved.html.twig', 'unapproved');
        file_put_contents($source.'/vendor/symfony/ux-toolkit/kits/shadcn/card/manifest.json', <<<'JSON'
            {"copy-files":{"templates/":"templates/"},"dependencies":{"recipe":["unapproved"]}}
            JSON);

        $failure = null;
        try {
            $this->synchronizer()->synchronize($source, $destination, ['card']);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('fixed dependency closure', $failure->getMessage());
        self::assertSame(['sentinel.txt'], array_values(array_diff(scandir($destination) ?: [], ['.', '..'])));
        self::assertSame('unchanged', file_get_contents($destination.'/sentinel.txt'));
    }

    public function testInstalledSourceMismatchLeavesDestinationByteForByteUnchanged(): void
    {
        $source = $this->createSourceFixture();
        $destination = $this->fixtureDirectory.'/snapshot';
        mkdir($destination.'/templates/components', 0777, true);
        file_put_contents($destination.'/templates/components/Card.html.twig', 'existing destination');
        file_put_contents($source.'/templates/components/Card.html.twig', 'generated output drifted');

        $failure = null;
        try {
            $this->synchronizer()->synchronize($source, $destination, ['card']);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('differs from the pinned upstream recipe', $failure->getMessage());
        self::assertSame('existing destination', file_get_contents($destination.'/templates/components/Card.html.twig'));
        self::assertFileDoesNotExist($destination.'/assets/icons/lucide/circle-check.svg');
    }

    public function testManifestSymlinkIsRejectedWithoutFollowingItOrMutatingSnapshot(): void
    {
        $source = $this->createSourceFixture();
        $resources = $this->fixtureDirectory.'/resources';
        $destination = $resources.'/skeleton';
        $outside = $this->fixtureDirectory.'/outside-manifest.json';
        mkdir($destination, 0777, true);
        mkdir($resources.'/shadcn', 0777, true);
        file_put_contents($destination.'/sentinel.txt', 'unchanged');
        file_put_contents($outside, 'outside unchanged');
        symlink($outside, $resources.'/shadcn/manifest.json');

        $failure = null;
        try {
            $this->synchronizer()->synchronize($source, $destination, ['card']);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('manifest', strtolower($failure->getMessage()));
        self::assertSame('outside unchanged', file_get_contents($outside));
        self::assertSame(['sentinel.txt'], array_values(array_diff(scandir($destination) ?: [], ['.', '..'])));
    }

    public function testCleanPinnedFixtureReproducesAllFourAdaptationsAndCommittedManifestMetadata(): void
    {
        $source = $this->createPinnedToolkitFixture();
        $destination = $this->fixtureDirectory.'/resources/skeleton';

        $manifest = $this->synchronizer()->synchronize($source, $destination);
        $modified = array_values(array_filter(
            $manifest['files'],
            static fn (array $file): bool => isset($file['local_modification']),
        ));

        self::assertSame([
            'assets/controllers/alert_dialog_controller.js',
            'templates/components/AlertDialog.html.twig',
            'templates/components/AlertDialog/Content.html.twig',
            'templates/components/Spinner.html.twig',
        ], array_column($modified, 'path'));

        $repository = dirname(__DIR__, 3);
        foreach ($modified as $file) {
            self::assertSame(
                hash_file('sha256', $repository.'/resources/skeleton/'.$file['path']),
                $file['sha256'],
                $file['path'],
            );
            self::assertSame(
                file_get_contents($repository.'/resources/skeleton/'.$file['path']),
                file_get_contents($destination.'/'.$file['path']),
                $file['path'],
            );
        }
    }

    private function synchronizer(): ShadcnSnapshotSynchronizer
    {
        return new ShadcnSnapshotSynchronizer();
    }

    private function createSourceFixture(): string
    {
        $source = $this->fixtureDirectory.'/source';
        mkdir($source.'/vendor/symfony/ux-toolkit/kits/shadcn/card/templates/components', 0777, true);
        mkdir($source.'/vendor/composer', 0777, true);
        mkdir($source.'/templates/components', 0777, true);
        mkdir($source.'/assets/icons/lucide', 0777, true);
        file_put_contents($source.'/vendor/symfony/ux-toolkit/kits/shadcn/card/manifest.json', <<<'JSON'
            {"copy-files":{"templates/":"templates/"}}
            JSON);
        file_put_contents($source.'/vendor/symfony/ux-toolkit/kits/shadcn/card/templates/components/Card.html.twig', '<article>card</article>');
        file_put_contents($source.'/templates/components/Card.html.twig', '<article>card</article>');
        file_put_contents($source.'/templates/base.html.twig', 'must not be copied');

        foreach (['circle-check', 'eye', 'eye-off', 'key-round', 'mail', 'trash-2', 'triangle-alert'] as $icon) {
            file_put_contents($source.'/assets/icons/lucide/'.$icon.'.svg', '<svg data-icon="'.$icon.'"></svg>');
        }

        file_put_contents($source.'/vendor/composer/installed.json', json_encode([
            'packages' => [[
                'name' => 'symfony/ux-toolkit',
                'version' => 'v3.3.0',
                'source' => ['reference' => 'f01228fe7d0e3bd26b1c27eb34b0cc1012a9b208'],
                'license' => ['MIT'],
            ]],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $source;
    }

    private function createPinnedToolkitFixture(): string
    {
        $repository = dirname(__DIR__, 3);
        $source = $this->fixtureDirectory.'/pinned-source';
        mkdir($source.'/vendor/symfony/ux-toolkit/kits/shadcn', 0777, true);
        mkdir($source.'/vendor/composer', 0777, true);
        mkdir($source.'/assets/icons/lucide', 0777, true);

        $recipeDependencies = [
            'alert-dialog' => ['button'],
            'field' => ['label', 'separator'],
        ];
        foreach (['card', 'button', 'input', 'field', 'alert', 'separator', 'alert-dialog', 'spinner', 'label'] as $recipe) {
            $recipeRoot = $source.'/vendor/symfony/ux-toolkit/kits/shadcn/'.$recipe;
            mkdir($recipeRoot, 0777, true);
            file_put_contents($recipeRoot.'/manifest.json', json_encode([
                'copy-files' => 'alert-dialog' === $recipe
                    ? ['assets/' => 'assets/', 'templates/' => 'templates/']
                    : ['templates/' => 'templates/'],
                'dependencies' => ['recipe' => $recipeDependencies[$recipe] ?? []],
            ], JSON_THROW_ON_ERROR));
        }

        $committedManifest = json_decode(
            (string) file_get_contents($repository.'/resources/shadcn/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($committedManifest['files'] as $file) {
            if (!str_starts_with($file['source'], 'symfony/ux-toolkit:shadcn/')) {
                continue;
            }

            $recipe = substr($file['source'], strlen('symfony/ux-toolkit:shadcn/'));
            $contents = file_get_contents($repository.'/resources/skeleton/'.$file['path']);
            self::assertIsString($contents);
            $beforePatch = $repository.'/tests/Fixtures/Shadcn330BeforePatch/'.$file['path'];
            if (is_file($beforePatch)) {
                $contents = file_get_contents($beforePatch);
                self::assertIsString($contents);
            }

            foreach ([$source.'/'.$file['path'], $source.'/vendor/symfony/ux-toolkit/kits/shadcn/'.$recipe.'/'.$file['path']] as $path) {
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0777, true);
                }
                file_put_contents($path, $contents);
            }
        }

        foreach (['circle-check', 'eye', 'eye-off', 'key-round', 'mail', 'trash-2', 'triangle-alert'] as $icon) {
            copy(
                $repository.'/resources/skeleton/assets/icons/lucide/'.$icon.'.svg',
                $source.'/assets/icons/lucide/'.$icon.'.svg',
            );
        }

        file_put_contents($source.'/vendor/composer/installed.json', json_encode([
            'packages' => [[
                'name' => 'symfony/ux-toolkit',
                'version' => 'v3.3.0',
                'source' => ['reference' => 'f01228fe7d0e3bd26b1c27eb34b0cc1012a9b208'],
                'license' => ['MIT'],
            ]],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $source;
    }
}
