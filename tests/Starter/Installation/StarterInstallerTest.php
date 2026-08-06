<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Installation;

use PHPUnit\Framework\TestCase;
use Te4g\Starter\Composer\ComposerRunnerInterface;
use Te4g\Starter\Composer\RuntimeDependencyInstaller;
use Te4g\Starter\Exception\GenerationFailed;
use Te4g\Starter\Generation\ReplacementPolicy;
use Te4g\Starter\Generation\ResourceCatalog;
use Te4g\Starter\Generation\StagedFileWriter;
use Te4g\Starter\Installation\ApplicationValidator;
use Te4g\Starter\Installation\StarterInstaller;
use Te4g\Starter\Process\ProcessResult;
use Te4g\Starter\Project\ProjectLayout;
use Te4g\Starter\Tests\Fixtures\OperationTrace;
use Te4g\Starter\Tests\Fixtures\RecordingComposerRunner;
use Te4g\Starter\Tests\Fixtures\RecordingProcessRunner;

final class StarterInstallerTest extends TestCase
{
    private string $projectDirectory;

    protected function setUp(): void
    {
        $this->projectDirectory = sys_get_temp_dir().'/te4g-starter-installer-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDirectory.'/bin', 0700, true));
        self::assertNotFalse(file_put_contents($this->projectDirectory.'/composer.json', '{"require":{"symfony/framework-bundle":"^8.1"}}'));
        self::assertNotFalse(file_put_contents($this->projectDirectory.'/bin/console', '<?php'));
        self::assertTrue(mkdir($this->projectDirectory.'/resources'));
        self::assertTrue(mkdir($this->projectDirectory.'/baselines'));
        self::assertTrue(mkdir($this->projectDirectory.'/assets/icons/lucide', 0700, true));

        foreach (['circle-check', 'eye', 'eye-off', 'key-round', 'mail', 'trash-2', 'triangle-alert'] as $icon) {
            self::assertNotFalse(file_put_contents($this->projectDirectory.'/assets/icons/lucide/'.$icon.'.svg', '<svg/>'));
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDirectory);
    }

    public function testPipelineOrdersPreflightDependenciesGenerationAndValidation(): void
    {
        $trace = new OperationTrace();
        $installer = $this->installer($trace);

        $installer->install($this->layout(), static function (string $phase) use ($trace): void {
            $trace->record($phase);
        });

        self::assertSame([
            'preflight',
            'dependencies',
            'importmap',
            'generation',
            'validation',
        ], $trace->operations());
    }

    public function testInitialResourceConflictStopsBeforeHostDependencyMutation(): void
    {
        self::assertTrue(mkdir($this->projectDirectory.'/resources/assets'));
        self::assertNotFalse(file_put_contents($this->projectDirectory.'/resources/assets/app.js', 'generated'));
        self::assertNotFalse(file_put_contents($this->projectDirectory.'/assets/app.js', 'custom'));
        $composerRunner = new RecordingComposerRunner(ProcessResult::success());
        $processRunner = new RecordingProcessRunner();
        $trace = new OperationTrace();

        try {
            $this->installerWith($composerRunner, $processRunner)->install(
                $this->layout(),
                static function (string $phase) use ($trace): void {
                    $trace->record($phase);
                },
            );
            self::fail('The initial resource conflict should fail preflight.');
        } catch (GenerationFailed $exception) {
            self::assertSame('assets/app.js: existing content is not a supported fresh Symfony baseline', $exception->getMessage());
            self::assertSame([], $composerRunner->calls);
            self::assertSame([], $processRunner->commands);
            self::assertSame([], $trace->operations());
            self::assertSame('custom', file_get_contents($this->projectDirectory.'/assets/app.js'));
        }
    }

    public function testSecondPlanRecognizesFreshFlexOutputAndSuppliesTheWrite(): void
    {
        self::assertTrue(mkdir($this->projectDirectory.'/resources/assets'));
        self::assertTrue(mkdir($this->projectDirectory.'/baselines/assets'));
        self::assertNotFalse(file_put_contents($this->projectDirectory.'/resources/assets/app.js', 'generated application'));
        self::assertNotFalse(file_put_contents($this->projectDirectory.'/baselines/assets/app.js', 'fresh Flex baseline'));
        $composerRunner = new class ($this->projectDirectory) implements ComposerRunnerInterface {
            private int $calls = 0;

            public function __construct(private readonly string $projectDirectory)
            {
            }

            public function run(array $arguments, string $cwd): ProcessResult
            {
                if (0 === $this->calls++) {
                    file_put_contents($this->projectDirectory.'/assets/app.js', 'fresh Flex baseline');
                }

                return new ProcessResult(['composer', ...$arguments], 0, '', '');
            }
        };
        $processRunner = new RecordingProcessRunner();

        $this->installerWith($composerRunner, $processRunner)->install($this->layout(), static function (): void {
        });

        self::assertSame('generated application', file_get_contents($this->projectDirectory.'/assets/app.js'));
        self::assertSame([
            'php',
            'bin/console',
            'importmap:require',
            'shadcn/dist/tailwind.css',
            'tw-animate-css/dist/tw-animate.css',
            '--no-interaction',
        ], $processRunner->commands[0]);
        self::assertSame($this->layout()->root(), $processRunner->calls[0]['cwd']);
        self::assertGreaterThan(0.0, $processRunner->calls[0]['timeout']);
    }

    private function installer(OperationTrace $trace): StarterInstaller
    {
        $processRunner = new RecordingProcessRunner();

        return $this->installerWith(
            new RecordingComposerRunner(ProcessResult::success()),
            $processRunner,
        );
    }

    private function installerWith(
        ComposerRunnerInterface $composerRunner,
        RecordingProcessRunner $processRunner,
    ): StarterInstaller {
        return new StarterInstaller(
            new \Te4g\Starter\Project\ProjectInspector('8.4.0', '8.1.0'),
            new ResourceCatalog($this->projectDirectory.'/resources'),
            new ReplacementPolicy($this->projectDirectory.'/baselines'),
            new RuntimeDependencyInstaller($composerRunner),
            $processRunner,
            new StagedFileWriter(),
            new ApplicationValidator($processRunner),
        );
    }

    private function layout(): ProjectLayout
    {
        return ProjectLayout::discover($this->projectDirectory);
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
