<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Te4g\Starter\Composer\ComposerRunnerInterface;
use Te4g\Starter\Composer\RuntimeDependencyInstaller;
use Te4g\Starter\Console\InstallCommand;
use Te4g\Starter\Generation\ReplacementPolicy;
use Te4g\Starter\Generation\ResourceCatalog;
use Te4g\Starter\Generation\StagedFileWriter;
use Te4g\Starter\Installation\ApplicationValidator;
use Te4g\Starter\Installation\StarterInstaller;
use Te4g\Starter\Process\ProcessResult;
use Te4g\Starter\Project\ProjectInspector;
use Te4g\Starter\Tests\Fixtures\RecordingComposerRunner;
use Te4g\Starter\Tests\Fixtures\RecordingProcessRunner;

final class InstallCommandTest extends TestCase
{
    private string $projectDirectory;

    protected function setUp(): void
    {
        $this->projectDirectory = sys_get_temp_dir().'/te4g-starter-command-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDirectory.'/bin', 0700, true));
        self::assertTrue(mkdir($this->projectDirectory.'/resources'));
        self::assertTrue(mkdir($this->projectDirectory.'/baselines'));
        self::assertTrue(mkdir($this->projectDirectory.'/assets/icons/lucide', 0700, true));
        self::assertNotFalse(file_put_contents($this->projectDirectory.'/composer.json', '{"require":{"symfony/framework-bundle":"^8.1"}}'));
        self::assertNotFalse(file_put_contents($this->projectDirectory.'/bin/console', '<?php'));

        foreach (['circle-check', 'eye', 'eye-off', 'key-round', 'mail', 'trash-2', 'triangle-alert'] as $icon) {
            self::assertNotFalse(file_put_contents($this->projectDirectory.'/assets/icons/lucide/'.$icon.'.svg', '<svg/>'));
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDirectory);
    }

    public function testCommandPrintsCompleteSubprocessFailure(): void
    {
        $failure = new ProcessResult(
            ['php', 'bin/console', 'lint:twig', 'templates', '--no-interaction'],
            3,
            'twig <info>stdout</info>',
            '<error>twig broke</error>',
        );
        $tester = $this->tester(new RecordingProcessRunner(
            ProcessResult::success(),
            ProcessResult::success(),
            ProcessResult::success(),
            ProcessResult::success(),
            $failure,
        ));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Twig validation', $tester->getDisplay());
        self::assertStringContainsString('php bin/console lint:twig templates --no-interaction', $tester->getDisplay());
        self::assertStringContainsString('exit code 3', $tester->getDisplay());
        self::assertStringContainsString('twig <info>stdout</info>', $tester->getDisplay());
        self::assertStringContainsString('<error>twig broke</error>', $tester->getDisplay());
    }

    public function testCommandPrintsCompleteComposerTimeoutFailure(): void
    {
        $command = ['composer', 'require', 'symfony/framework-bundle:^8.1'];
        $timeout = new ProcessResult(
            $command,
            124,
            'composer <info>stdout</info>',
            '<error>composer stderr</error>'."\n".'The process exceeded the timeout of 600 seconds.',
        );
        $tester = $this->tester(
            new RecordingProcessRunner(),
            composerRunner: new RecordingComposerRunner($timeout),
        );

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('runtime dependencies', $tester->getDisplay());
        self::assertStringContainsString("command: composer require 'symfony/framework-bundle:^8.1'", $tester->getDisplay());
        self::assertStringContainsString('exit code 124', $tester->getDisplay());
        self::assertStringContainsString('composer <info>stdout</info>', $tester->getDisplay());
        self::assertStringContainsString('<error>composer stderr</error>', $tester->getDisplay());
        self::assertStringContainsString('exceeded the timeout of 600 seconds', $tester->getDisplay());
        self::assertStringNotContainsString('exception', strtolower($tester->getDisplay()));
    }

    public function testSuccessPrintsOnlyCompletedPhasesOwnershipAndNextCommands(): void
    {
        $runner = new RecordingProcessRunner();
        $tester = $this->tester($runner);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(<<<'OUTPUT'
Completed: preflight
Completed: dependencies
Completed: importmap
Completed: generation
Completed: validation
Starter installed. The generated code now belongs to this application.

Next:
  php bin/console make:migration
  php bin/console doctrine:migrations:migrate
  composer remove te4g/starter --dev
OUTPUT."\n", $tester->getDisplay());

        $commands = implode("\n", array_map(static fn (array $command): string => implode(' ', $command), $runner->commands));
        self::assertStringNotContainsString('make:migration', $commands);
        self::assertStringNotContainsString('doctrine:', $commands);
        self::assertStringNotContainsString('composer remove', $commands);
    }

    public function testConflictsPrintEveryExactReasonWithoutPromptOrGenericFailure(): void
    {
        self::assertTrue(mkdir($this->projectDirectory.'/src/Entity', 0700, true));
        self::assertNotFalse(file_put_contents($this->projectDirectory.'/src/Entity/User.php', '<?php'));
        $tester = $this->tester(new RecordingProcessRunner(), '8.3.99', '8.2.0');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(<<<'OUTPUT'
composer.json: PHP 8.4 or newer is required
composer.json: Symfony 8.1.x is required
src/Entity/User.php: an account system already exists
OUTPUT."\n", $tester->getDisplay());
        self::assertStringNotContainsString('confirm', strtolower($tester->getDisplay()));
        self::assertStringNotContainsString('installation failed', strtolower($tester->getDisplay()));
    }

    private function tester(
        RecordingProcessRunner $processRunner,
        string $phpVersion = '8.4.0',
        string $symfonyVersion = '8.1.0',
        ?ComposerRunnerInterface $composerRunner = null,
    ): CommandTester {
        $installer = new StarterInstaller(
            new ProjectInspector($phpVersion, $symfonyVersion),
            new ResourceCatalog($this->projectDirectory.'/resources'),
            new ReplacementPolicy($this->projectDirectory.'/baselines'),
            new RuntimeDependencyInstaller($composerRunner ?? new RecordingComposerRunner(ProcessResult::success())),
            $processRunner,
            new StagedFileWriter(),
            new ApplicationValidator($processRunner),
        );

        return new CommandTester(new InstallCommand($installer, $this->projectDirectory));
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
