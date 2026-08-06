<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Generation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Te4g\Starter\Exception\GenerationFailed;
use Te4g\Starter\Generation\GenerationPlan;
use Te4g\Starter\Generation\PlannedResource;
use Te4g\Starter\Generation\StagedFileWriter;
use Te4g\Starter\Project\ProjectLayout;

final class StagedFileWriterTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/te4g-starter-writer-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function testInvalidPhpNeverReachesTheApplication(): void
    {
        $plan = $this->planForResource('src/Broken.php', '<?php syntax error');

        try {
            $this->writer()->write($this->layout(), $plan);
            self::fail('Expected GenerationFailed to be thrown.');
        } catch (GenerationFailed) {
            self::assertFileDoesNotExist($this->layout()->path('src/Broken.php'));
        }
    }

    #[DataProvider('invalidStaticResourceProvider')]
    public function testInvalidStaticResourcesNeverReachTheApplication(string $path, string $contents): void
    {
        $plan = $this->planForResource($path, $contents);

        try {
            $this->writer()->write($this->layout(), $plan);
            self::fail('Expected GenerationFailed to be thrown.');
        } catch (GenerationFailed) {
            self::assertFileDoesNotExist($this->layout()->path($path));
        }
    }

    public function testCopiedFilesRemainWhenPostCopyWorkFailsElsewhere(): void
    {
        try {
            $this->writer()->write($this->layout(), $this->validPlan());
            throw new \RuntimeException('Downstream installation work failed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Downstream installation work failed.', $exception->getMessage());
        }

        self::assertFileExists($this->layout()->path('src/Entity/User.php'));
    }

    public function testPlannedCreateRefusesACustomFileIntroducedBeforeWriting(): void
    {
        $layout = $this->layout();
        $plan = $this->planForResource('src/Example.php', '<?php final class GeneratedExample {}');
        $target = $layout->path('src/Example.php');
        self::assertTrue(mkdir(dirname($target), 0700, true));
        self::assertNotFalse(file_put_contents($target, '<?php final class CustomExample {}'));

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessage('src/Example.php: planned create target changed');

        try {
            $this->writer()->write($layout, $plan);
        } finally {
            self::assertSame('<?php final class CustomExample {}', file_get_contents($target));
        }
    }

    public function testPlannedCreateRefusesASymlinkIntroducedBeforeWriting(): void
    {
        $layout = $this->layout();
        $plan = $this->planForResource('src/Example.php', '<?php final class GeneratedExample {}');
        $target = $layout->path('src/Example.php');
        self::assertTrue(mkdir(dirname($target), 0700, true));
        self::assertTrue(symlink('../composer.json', $target));

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessage('src/Example.php: planned create target changed');

        try {
            $this->writer()->write($layout, $plan);
        } finally {
            self::assertSame('{}', file_get_contents($layout->path('composer.json')));
            self::assertTrue(is_link($target));
        }
    }

    public function testPlannedReplacementRefusesContentChangedBeforeWriting(): void
    {
        $layout = $this->layout();
        $target = $layout->path('config/packages/security.yaml');
        self::assertTrue(mkdir(dirname($target), 0700, true));
        self::assertNotFalse(file_put_contents($target, 'fresh baseline'));
        $source = $this->temporaryDirectory.'/replacement';
        self::assertNotFalse(file_put_contents($source, 'generated security'));
        $plan = new GenerationPlan([
            PlannedResource::replace('config/packages/security.yaml', $source, 'fresh baseline'),
        ], []);
        self::assertNotFalse(file_put_contents($target, 'customized security'));

        $this->expectException(GenerationFailed::class);
        $this->expectExceptionMessage('config/packages/security.yaml: planned replacement target changed');

        try {
            $this->writer()->write($layout, $plan);
        } finally {
            self::assertSame('customized security', file_get_contents($target));
        }
    }

    public function testCleanupUnlinksASwappedStagingRootWithoutTraversingOutsideDirectory(): void
    {
        $staging = $this->temporaryDirectory.'/staging';
        $outside = $this->temporaryDirectory.'/outside';
        self::assertTrue(mkdir($staging, 0700, true));
        self::assertTrue(mkdir($outside, 0700, true));
        self::assertNotFalse(file_put_contents($outside.'/keep.txt', 'keep'));
        $identity = lstat($staging);
        self::assertIsArray($identity);
        self::assertTrue(rename($staging, $this->temporaryDirectory.'/former-staging'));
        self::assertTrue(symlink($outside, $staging));

        $method = new \ReflectionMethod(StagedFileWriter::class, 'removeTemporaryDirectory');
        $method->invoke($this->writer(), $staging, $identity);

        self::assertFileExists($outside.'/keep.txt');
        self::assertFileDoesNotExist($staging);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidStaticResourceProvider(): iterable
    {
        yield 'YAML' => ['config/packages/broken.yaml', "value: [\n"];
        yield 'JSON' => ['assets/broken.json', '{'];
        yield 'Twig' => ['templates/broken.html.twig', '{% if %}'];
        yield 'SVG' => ['assets/broken.svg', '<svg>'];
    }

    private function writer(): StagedFileWriter
    {
        return new StagedFileWriter();
    }

    private function validPlan(): GenerationPlan
    {
        return $this->planForResource('src/Entity/User.php', '<?php final class User {}');
    }

    private function planForResource(string $relativePath, string $contents): GenerationPlan
    {
        $source = $this->temporaryDirectory.'/resource';
        self::assertNotFalse(file_put_contents($source, $contents));

        return new GenerationPlan([
            PlannedResource::create($relativePath, $source),
        ], []);
    }

    private function layout(): ProjectLayout
    {
        $root = $this->temporaryDirectory.'/project';
        if (!is_dir($root.'/bin')) {
            self::assertTrue(mkdir($root.'/bin', 0700, true));
            self::assertNotFalse(file_put_contents($root.'/composer.json', '{}'));
            self::assertNotFalse(file_put_contents($root.'/bin/console', '<?php'));
        }

        return ProjectLayout::discover($root);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory.'/'.$entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
