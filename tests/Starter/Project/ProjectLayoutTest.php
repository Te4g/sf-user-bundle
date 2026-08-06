<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Project;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Te4g\Starter\Exception\InvalidProject;
use Te4g\Starter\Project\ProjectLayout;

final class ProjectLayoutTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/te4g-starter-layout-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
    }

    public function testDiscoversProjectRootFromNestedDirectory(): void
    {
        $layout = ProjectLayout::discover($this->fixture('bare-skeleton/bin'));

        self::assertSame($this->fixture('bare-skeleton'), $layout->root());
    }

    public function testNormalizesSafeRelativePaths(): void
    {
        $layout = ProjectLayout::discover($this->fixture('bare-skeleton'));

        self::assertSame($this->fixture('bare-skeleton/src/Entity/User.php'), $layout->path('src\\./Entity//User.php'));
    }

    #[DataProvider('unsafePathProvider')]
    public function testRejectsUnsafeRelativePaths(string $path): void
    {
        $this->expectException(InvalidProject::class);

        ProjectLayout::discover($this->fixture('bare-skeleton'))->path($path);
    }

    public function testRejectsSymlinkedParentEscapingTheProjectRoot(): void
    {
        $project = $this->temporaryDirectory.'/project';
        $outside = $this->temporaryDirectory.'/outside';
        self::assertTrue(mkdir($project.'/bin', 0700, true));
        self::assertTrue(mkdir($outside, 0700, true));
        self::assertNotFalse(file_put_contents($project.'/composer.json', '{}'));
        self::assertNotFalse(file_put_contents($project.'/bin/console', '<?php'));
        self::assertTrue(symlink($outside, $project.'/escaped'));

        $this->expectException(InvalidProject::class);

        ProjectLayout::discover($project)->path('escaped/User.php');
    }

    public function testRejectsWorkingDirectoryOutsideASymfonyProject(): void
    {
        $this->expectException(InvalidProject::class);

        ProjectLayout::discover($this->temporaryDirectory);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePathProvider(): iterable
    {
        yield 'traversal' => ['../outside'];
        yield 'NUL byte' => ["src\0/User.php"];
        yield 'Unix absolute path' => ['/etc/passwd'];
        yield 'Windows absolute path' => ['C:\\Windows\\System32'];
    }

    private function fixture(string $path): string
    {
        return dirname(__DIR__, 2).'/Fixtures/Starter/'.$path;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        self::assertIsArray($entries);
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory.'/'.$entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);

                continue;
            }

            $this->removeDirectory($path);
        }

        rmdir($directory);
    }
}
