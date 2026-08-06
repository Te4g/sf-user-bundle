<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Composer;

use PHPUnit\Framework\TestCase;
use Te4g\Starter\Composer\RuntimeDependencyInstaller;
use Te4g\Starter\Composer\RuntimeDependencySet;
use Te4g\Starter\Exception\SubprocessFailed;
use Te4g\Starter\Process\ProcessResult;
use Te4g\Starter\Project\ProjectLayout;
use Te4g\Starter\Tests\Fixtures\RecordingComposerRunner;

final class RuntimeDependencyInstallerTest extends TestCase
{
    private string $projectDirectory;

    protected function setUp(): void
    {
        $this->projectDirectory = sys_get_temp_dir().'/te4g-starter-composer-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDirectory.'/bin', 0700, true));
        self::assertNotFalse(file_put_contents($this->projectDirectory.'/composer.json', '{}'));
        self::assertNotFalse(file_put_contents($this->projectDirectory.'/bin/console', '<?php'));
    }

    protected function tearDown(): void
    {
        unlink($this->projectDirectory.'/bin/console');
        rmdir($this->projectDirectory.'/bin');
        unlink($this->projectDirectory.'/composer.json');
        rmdir($this->projectDirectory);
    }

    public function testInstallsRuntimeThenDevelopmentRequirements(): void
    {
        $runner = new RecordingComposerRunner(ProcessResult::success());
        $layout = $this->layout();
        (new RuntimeDependencyInstaller($runner))->install($layout);

        self::assertSame([
            'require',
            ...RuntimeDependencySet::runtime(),
            '--no-interaction',
            '--no-progress',
            '--with-all-dependencies',
        ], $runner->calls[0][0]);
        self::assertSame($layout->root(), $runner->calls[0][1]);
        self::assertSame([
            'require',
            ...RuntimeDependencySet::development(),
            '--dev',
            '--no-interaction',
            '--no-progress',
            '--with-all-dependencies',
        ], $runner->calls[1][0]);
        self::assertSame($layout->root(), $runner->calls[1][1]);
    }

    public function testFailurePreservesCommandAndStderr(): void
    {
        $result = new ProcessResult(
            ['composer', 'require'],
            2,
            'resolution output',
            'dependency conflict',
        );
        $runner = new RecordingComposerRunner($result);

        try {
            (new RuntimeDependencyInstaller($runner))->install($this->layout());
            self::fail('The failed runtime dependency installation should throw.');
        } catch (SubprocessFailed $exception) {
            self::assertSame('runtime dependencies', $exception->phase());
            self::assertSame(['composer', 'require'], $exception->command());
            self::assertSame(2, $exception->exitCode());
            self::assertSame('resolution output', $exception->stdout());
            self::assertSame('dependency conflict', $exception->stderr());
            self::assertStringContainsString('dependency conflict', $exception->getMessage());
            self::assertCount(1, $runner->calls);
        }
    }

    private function layout(): ProjectLayout
    {
        return ProjectLayout::discover($this->projectDirectory);
    }
}
