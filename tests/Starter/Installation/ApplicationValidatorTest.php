<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Installation;

use PHPUnit\Framework\TestCase;
use Te4g\Starter\Exception\GenerationFailed;
use Te4g\Starter\Exception\SubprocessFailed;
use Te4g\Starter\Installation\ApplicationValidator;
use Te4g\Starter\Process\ProcessResult;
use Te4g\Starter\Process\SymfonyProcessRunner;
use Te4g\Starter\Project\ProjectLayout;
use Te4g\Starter\Tests\Fixtures\RecordingProcessRunner;

final class ApplicationValidatorTest extends TestCase
{
    private const array ICONS = [
        'circle-check',
        'eye',
        'eye-off',
        'key-round',
        'mail',
        'trash-2',
        'triangle-alert',
    ];

    private string $projectDirectory;

    protected function setUp(): void
    {
        $this->projectDirectory = sys_get_temp_dir().'/te4g-starter-validator-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDirectory.'/bin', 0700, true));
        self::assertTrue(mkdir($this->projectDirectory.'/assets/icons/lucide', 0700, true));
        self::assertNotFalse(file_put_contents($this->projectDirectory.'/composer.json', '{}'));
        self::assertNotFalse(file_put_contents($this->projectDirectory.'/bin/console', '<?php'));

        foreach (self::ICONS as $icon) {
            self::assertNotFalse(file_put_contents($this->projectDirectory.'/assets/icons/lucide/'.$icon.'.svg', '<svg/>'));
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDirectory);
    }

    public function testValidationRunsExactBoundedCommandsInOrder(): void
    {
        $runner = new RecordingProcessRunner();
        (new ApplicationValidator($runner))->validate($this->layout());

        self::assertSame([
            ['php', 'bin/console', 'lint:container', '--no-interaction'],
            ['php', 'bin/console', 'lint:yaml', 'config', '--no-interaction'],
            ['php', 'bin/console', 'lint:xliff', 'translations', '--no-interaction'],
            ['php', 'bin/console', 'lint:twig', 'templates', '--no-interaction'],
            ['php', 'bin/console', 'tailwind:build', '--no-interaction'],
            ['php', 'bin/console', 'debug:asset-map', '--no-interaction'],
        ], $runner->commands);
        self::assertSame(
            array_fill(0, 6, $this->layout()->root()),
            array_column($runner->calls, 'cwd'),
        );
        foreach (array_column($runner->calls, 'timeout') as $timeout) {
            self::assertGreaterThan(0.0, $timeout);
            self::assertLessThanOrEqual(600.0, $timeout);
        }
    }

    public function testValidationNeverRunsADatabaseOrMigrationCommand(): void
    {
        $runner = new RecordingProcessRunner();
        (new ApplicationValidator($runner))->validate($this->layout());

        $commands = implode("\n", array_map(static fn (array $call): string => implode(' ', $call), $runner->commands));
        self::assertStringNotContainsString('doctrine:', $commands);
        self::assertStringNotContainsString('make:migration', $commands);
    }

    public function testSubprocessFailurePreservesPhaseCommandExitCodeAndBothOutputs(): void
    {
        $failure = new ProcessResult(
            ['php', 'bin/console', 'lint:xliff', 'translations', '--no-interaction'],
            3,
            'xliff stdout',
            'xliff broke',
        );
        $runner = new RecordingProcessRunner(
            ProcessResult::success(),
            ProcessResult::success(),
            $failure,
        );

        try {
            (new ApplicationValidator($runner))->validate($this->layout());
            self::fail('The failed XLIFF validation should throw.');
        } catch (SubprocessFailed $exception) {
            self::assertSame('XLIFF validation', $exception->phase());
            self::assertSame($failure->command(), $exception->command());
            self::assertSame(3, $exception->exitCode());
            self::assertSame('xliff stdout', $exception->stdout());
            self::assertSame('xliff broke', $exception->stderr());
            self::assertCount(3, $runner->commands);
        }
    }

    public function testMissingLockedLucideIconFailsAfterAllApplicationCommands(): void
    {
        self::assertTrue(unlink($this->projectDirectory.'/assets/icons/lucide/mail.svg'));
        $runner = new RecordingProcessRunner();

        try {
            (new ApplicationValidator($runner))->validate($this->layout());
            self::fail('A missing locked icon should fail validation.');
        } catch (GenerationFailed $exception) {
            self::assertSame('assets/icons/lucide/mail.svg: locked offline icon is missing', $exception->getMessage());
            self::assertCount(6, $runner->commands);
        }
    }

    public function testSymfonyRunnerPreservesNonzeroProcessEvidenceWithoutAShell(): void
    {
        $command = [PHP_BINARY, '-r', 'fwrite(STDOUT, $argv[1]); fwrite(STDERR, "failure"); exit(7);', 'literal $(command)'];

        $result = (new SymfonyProcessRunner())->run($command, $this->layout()->root(), 2.0);

        self::assertSame($command, $result->command());
        self::assertSame(7, $result->exitCode());
        self::assertSame('literal $(command)', $result->stdout());
        self::assertSame('failure', $result->stderr());
    }

    public function testSymfonyRunnerTurnsATimeoutIntoCompleteFailureEvidence(): void
    {
        $command = [PHP_BINARY, '-r', 'usleep(500000);'];

        $result = (new SymfonyProcessRunner())->run($command, $this->layout()->root(), 0.05);

        self::assertSame($command, $result->command());
        self::assertSame(124, $result->exitCode());
        self::assertStringContainsString('exceeded the timeout of 0.05 seconds', $result->stderr());
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
