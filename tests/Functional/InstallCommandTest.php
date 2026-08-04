<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Te4g\UserBundle\Command\InstallCommand;
use Te4g\UserBundle\Exception\MigrationGenerationFailed;
use Te4g\UserBundle\Installer\CssFragmentMerger;
use Te4g\UserBundle\Installer\InstallationPlanner;
use Te4g\UserBundle\Installer\InstallationValidatorInterface;
use Te4g\UserBundle\Installer\InstallerProcessResult;
use Te4g\UserBundle\Installer\InstallerProcessRunnerInterface;
use Te4g\UserBundle\Installer\ManifestStore;
use Te4g\UserBundle\Installer\MarkerEditor;
use Te4g\UserBundle\Installer\MigrationGeneratorInterface;
use Te4g\UserBundle\Installer\OfflineIconValidator;
use Te4g\UserBundle\Installer\PreflightChecker;
use Te4g\UserBundle\Installer\ProjectLayout;
use Te4g\UserBundle\Installer\ProjectLock;
use Te4g\UserBundle\Installer\SkeletonRenderer;
use Te4g\UserBundle\Installer\StagedWriter;

final class InstallCommandTest extends TestCase
{
    private string $projectDir;
    private string $originalCss;
    private string $originalImportmap;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/te4g-user-command-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir, 0777, true);
        $this->createCompatibleProject();
        $this->originalCss = $this->read('assets/styles/app.css');
        $this->originalImportmap = $this->read('importmap.php');
    }

    protected function tearDown(): void
    {
        @chmod($this->projectDir.'/config', 0777);
        (new Filesystem())->remove($this->projectDir);
    }

    public function testCommandExposesDryRunAndStandardNonInteractiveButNeverForce(): void
    {
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);
        $command = $this->installCommand($runner, $migration);
        $application = new Application();
        $application->addCommand($command);
        $command->mergeApplicationDefinition();

        self::assertTrue($command->getDefinition()->hasOption('dry-run'));
        self::assertTrue($command->getDefinition()->hasOption('no-interaction'));
        self::assertFalse($command->getDefinition()->hasOption('force'));
    }

    public function testDryRunPrintsTheCompletePlanWithoutWritingOrRunningProcesses(): void
    {
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);
        $before = $this->inventory();

        $tester = $this->command($runner, $migration);
        $status = $tester->execute(['--dry-run' => true, '--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('CREATE src/Entity/User.php', $tester->getDisplay());
        self::assertStringContainsString('MERGE assets/styles/app.css', $tester->getDisplay());
        self::assertStringContainsString('CONFIGURE Tailwind CSS v4.3.3', $tester->getDisplay());
        self::assertStringContainsString('REQUIRE shadcn/dist/tailwind.css tw-animate-css/dist/tw-animate.css', $tester->getDisplay());
        self::assertStringContainsString('GENERATE Doctrine migration for review', $tester->getDisplay());
        self::assertSame($before, $this->inventory());
        self::assertSame([], $runner->calls);
        self::assertSame(0, $migration->calls);
    }

    public function testInteractiveDeclineCancelsWithoutWritingOrRunningProcesses(): void
    {
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);
        $before = $this->inventory();
        $tester = $this->command($runner, $migration);
        $tester->setInputs(['no']);

        $status = $tester->execute([], ['interactive' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Installation cancelled.', $tester->getDisplay());
        self::assertSame($before, $this->inventory());
        self::assertSame([], $runner->calls);
        self::assertSame(0, $migration->calls);
    }

    public function testNonInteractiveFreshInstallUsesTheExactSafeOrderAndFinalizesManifest(): void
    {
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $tester = $this->command($runner, $migration);
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertFileExists($this->projectDir.'/src/Entity/User.php');
        self::assertFileExists($this->projectDir.'/migrations/Version20260803010101.php');
        self::assertFileExists($this->projectDir.'/config/te4g_user.install.json');
        self::assertSame(1, $migration->calls);
        self::assertSame([
            'importmap:require',
            'lint:container',
            'tailwind:build',
            'lint:twig',
            'lint:yaml',
        ], array_column($runner->calls, 'name'));
        foreach ($runner->calls as $call) {
            self::assertSame(realpath($this->projectDir), $call['cwd']);
            self::assertSame(120.0, $call['timeout']);
            self::assertSame(PHP_BINARY, $call['command'][0]);
            self::assertSame(realpath($this->projectDir).'/bin/console', $call['command'][1]);
            self::assertNotContains('tailwind:init', $call['command']);
            self::assertNotContains('doctrine:migrations:migrate', $call['command']);
        }
        self::assertSame([
            PHP_BINARY,
            realpath($this->projectDir).'/bin/console',
            'importmap:require',
            'shadcn/dist/tailwind.css',
            'tw-animate-css/dist/tw-animate.css',
            '--no-interaction',
        ], $runner->calls[0]['command']);
        self::assertSame(<<<'YAML'
            symfonycasts_tailwind:
                binary_version: v4.3.3

            YAML, $this->read('config/packages/te4g_user_tailwind.yaml'));
        self::assertStringContainsString('shadcn/dist/tailwind.css', $this->read('importmap.php'));
        self::assertStringContainsString('/* <te4g-user name="shadcn"> */', $this->read('assets/styles/app.css'));

        $manifest = json_decode($this->read(ManifestStore::RELATIVE_PATH), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('0.1.0', $manifest['bundleVersion']);
        self::assertArrayHasKey('assets/styles/app.css', $manifest['files']);
        self::assertArrayHasKey('config/packages/te4g_user_tailwind.yaml', $manifest['files']);
        self::assertArrayHasKey('importmap.php', $manifest['files']);
        self::assertArrayHasKey('assets/vendor/shadcn/dist/tailwind.css', $manifest['files']);
        self::assertArrayHasKey('assets/vendor/tw-animate-css/dist/tw-animate.css', $manifest['files']);
        self::assertArrayHasKey('migrations/Version20260803010101.php', $manifest['files']);

        $display = $tester->getDisplay();
        $this->assertOutputOrder($display, [
            'Preflight passed.',
            'Rendered application files.',
            'Installation plan:',
            'Applied staged files.',
            'Configured Tailwind CSS v4.3.3.',
            'Installed import-map requirements.',
            'Validated the service container.',
            'Built Tailwind CSS.',
            'Linted Twig templates.',
            'Linted YAML configuration.',
            'Verified the offline icon inventory.',
            'Generated Doctrine migration for review.',
            'Finalized installation manifest.',
            'Installation complete.',
        ]);
    }

    public function testRepeatInstallIsANoOpWithoutProcessesOrMigration(): void
    {
        $firstRunner = new InstallerCommandProcessRunner($this->projectDir);
        $firstMigration = new InstallerCommandMigrationGenerator($this->projectDir);
        self::assertSame(
            Command::SUCCESS,
            $this->command($firstRunner, $firstMigration)->execute(['--no-interaction' => true], ['interactive' => false]),
        );
        $before = $this->inventory();

        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);
        $tester = $this->command($runner, $migration);
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertStringContainsString('Already installed; no changes required.', $tester->getDisplay());
        self::assertSame($before, $this->inventory());
        self::assertSame([], $runner->calls);
        self::assertSame(0, $migration->calls);
    }

    public function testRepeatInstallWithApplicationManagedTailwindIsANoOp(): void
    {
        $this->write('config/packages/symfonycasts_tailwind.yaml', <<<'YAML'
            symfonycasts_tailwind:
                binary: /application/bin/tailwindcss

            YAML);
        $firstRunner = new InstallerCommandProcessRunner($this->projectDir);
        $firstMigration = new InstallerCommandMigrationGenerator($this->projectDir);
        self::assertSame(
            Command::SUCCESS,
            $this->command($firstRunner, $firstMigration)->execute(['--no-interaction' => true], ['interactive' => false]),
        );
        self::assertFileDoesNotExist($this->projectDir.'/config/packages/te4g_user_tailwind.yaml');
        $before = $this->inventory();

        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);
        $tester = $this->command($runner, $migration);
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertStringContainsString('Already installed; no changes required.', $tester->getDisplay());
        self::assertSame($before, $this->inventory());
        self::assertSame([], $runner->calls);
        self::assertSame(0, $migration->calls);
    }

    public function testPreflightAggregatesSecurityRouteComponentAndUnsafePathConflictsBeforeWriting(): void
    {
        copy(
            dirname(__DIR__).'/Fixtures/Installer/conflicting-security/config/packages/security.yaml',
            $this->projectDir.'/config/packages/security.yaml',
        );
        $this->write('config/routes/application.yaml', <<<'YAML'
            te4g_user_login:
                path: /mine
            YAML);
        $this->write('templates/components/Button.html.twig', 'mine');
        symlink(
            sys_get_temp_dir().'/te4g-user-command-missing-target',
            $this->projectDir.'/assets/controllers/password_visibility_controller.js',
        );
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $tester = $this->command($runner, $migration);
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('main-firewall-provider-conflict', $display);
        self::assertStringContainsString('main-firewall-authenticator-conflict', $display);
        self::assertStringContainsString('route-name-te4g-user-login-conflict', $display);
        self::assertStringContainsString('existing-component', $display);
        self::assertStringContainsString('unsafe-target-path', $display);
        self::assertSame([], $runner->calls);
        self::assertSame(0, $migration->calls);
        self::assertFileDoesNotExist($this->projectDir.'/src/Entity/User.php');
    }

    public function testApplicationEditAfterInstallIsNeverOverwritten(): void
    {
        self::assertSame(
            Command::SUCCESS,
            $this->command(
                new InstallerCommandProcessRunner($this->projectDir),
                new InstallerCommandMigrationGenerator($this->projectDir),
            )->execute(['--no-interaction' => true], ['interactive' => false]),
        );
        file_put_contents($this->projectDir.'/src/Entity/User.php', 'application edit');
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $status = $this->command($runner, $migration)->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame('application edit', $this->read('src/Entity/User.php'));
        self::assertSame([], $runner->calls);
        self::assertSame(0, $migration->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function subprocessFailures(): iterable
    {
        yield 'import map' => ['importmap:require'];
        yield 'container' => ['lint:container'];
        yield 'tailwind' => ['tailwind:build'];
        yield 'twig' => ['lint:twig'];
        yield 'yaml' => ['lint:yaml'];
    }

    #[DataProvider('subprocessFailures')]
    public function testEverySubprocessFailureRollsBackAllOwnedChangesWithoutLeakingOutput(string $failedCommand): void
    {
        $runner = new InstallerCommandProcessRunner($this->projectDir, $failedCommand);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $tester = $this->command($runner, $migration);
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Installation failed.', $tester->getDisplay());
        self::assertStringNotContainsString(InstallerCommandProcessRunner::SECRET_OUTPUT, $tester->getDisplay());
        $this->assertRolledBackToFreshState();
    }

    public function testMigrationFailureRollsBackEveryGeneratedFileAndHidesFailureDetails(): void
    {
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir, true);

        $tester = $this->command($runner, $migration);
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Installation failed.', $tester->getDisplay());
        self::assertStringNotContainsString(InstallerCommandMigrationGenerator::SECRET_FAILURE, $tester->getDisplay());
        $this->assertRolledBackToFreshState();
    }

    public function testUnexpectedSubprocessFileIsRejectedAndPreservedAsUnowned(): void
    {
        $runner = new InstallerCommandProcessRunner(
            $this->projectDir,
            mutateAt: 'lint:container',
            mutation: function (): void {
                $this->write('src/Unexpected.php', 'concurrent application file');
            },
        );
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $status = $this->command($runner, $migration)->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame('concurrent application file', $this->read('src/Unexpected.php'));
        self::assertFileDoesNotExist($this->projectDir.'/src/Entity/User.php');
        self::assertSame($this->originalCss, $this->read('assets/styles/app.css'));
        self::assertSame($this->originalImportmap, $this->read('importmap.php'));
    }

    public function testConcurrentEditOfAnInstalledFileIsNeverOverwrittenDuringRollback(): void
    {
        $runner = new InstallerCommandProcessRunner(
            $this->projectDir,
            mutateAt: 'tailwind:build',
            mutation: function (): void {
                file_put_contents($this->projectDir.'/src/Entity/User.php', 'concurrent application edit');
            },
        );
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $tester = $this->command($runner, $migration);
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame('concurrent application edit', $this->read('src/Entity/User.php'));
        self::assertStringContainsString('Rollback incomplete; application changes were preserved.', $tester->getDisplay());
        self::assertFileDoesNotExist($this->projectDir.'/config/te4g_user.install.json');
    }

    public function testConcurrentParentReplacementWithTheSameFileInodesFailsClosed(): void
    {
        $outside = $this->projectDir.'-entity-parent-swap';
        $runner = new InstallerCommandProcessRunner(
            $this->projectDir,
            mutateAt: 'tailwind:build',
            mutation: function () use ($outside): void {
                rename($this->projectDir.'/src/Entity', $outside);
                mkdir($this->projectDir.'/src/Entity');
                foreach (scandir($outside) ?: [] as $entry) {
                    if ('.' === $entry || '..' === $entry) {
                        continue;
                    }
                    link($outside.'/'.$entry, $this->projectDir.'/src/Entity/'.$entry);
                    unlink($outside.'/'.$entry);
                }
                rmdir($outside);
            },
        );
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $tester = $this->command($runner, $migration);
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Rollback incomplete; application changes were preserved.', $tester->getDisplay());
        self::assertFileExists($this->projectDir.'/src/Entity/User.php');
        self::assertFileDoesNotExist($this->projectDir.'/config/te4g_user.install.json');
    }

    public function testUnrelatedApplicationIconCannotTriggerANetworkCapableLockCommand(): void
    {
        $this->write('templates/application.html.twig', "{{ ux_icon('lucide:cloud-download') }}\n");
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $status = $this->command($runner, $migration)->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertNotContains('ux:icons:lock', array_column($runner->calls, 'name'));
        self::assertFileDoesNotExist($this->projectDir.'/assets/icons/lucide/cloud-download.svg');
        self::assertFileExists($this->projectDir.'/config/te4g_user.install.json');
    }

    public function testManifestFinalizationFailureRollsBackMigrationAndReleasesTheProjectLock(): void
    {
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator(
            $this->projectDir,
            afterGenerate: function (): void {
                chmod($this->projectDir.'/config', 0555);
            },
        );

        $tester = $this->command($runner, $migration);
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);
        chmod($this->projectDir.'/config', 0777);

        self::assertSame(Command::FAILURE, $status);
        self::assertFileDoesNotExist($this->projectDir.'/migrations/Version20260803010101.php');
        self::assertFileDoesNotExist($this->projectDir.'/config/te4g_user.install.json');
        self::assertFileDoesNotExist($this->projectDir.'/src/Entity/User.php');

        $lock = ProjectLock::acquire(ProjectLayout::at($this->projectDir), new \Te4g\UserBundle\Installer\InstallerFilesystem());
        $lock->release(new \Te4g\UserBundle\Installer\InstallerFilesystem());
        self::assertFileExists($this->projectDir.'/var/te4g-user-installer/install.lock');
    }

    private function command(
        InstallerCommandProcessRunner $runner,
        InstallerCommandMigrationGenerator $migration,
    ): CommandTester {
        $command = $this->installCommand($runner, $migration);
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand($command);

        return new CommandTester($application->find('te4g:user:install'));
    }

    private function installCommand(
        InstallerCommandProcessRunner $runner,
        InstallerCommandMigrationGenerator $migration,
    ): InstallCommand {
        $manifestStore = new ManifestStore('0.1.0');
        $cssFragmentMerger = new CssFragmentMerger(new MarkerEditor());

        return new InstallCommand(
            $this->projectDir,
            new PreflightChecker($manifestStore, $cssFragmentMerger, '8.4.10', '8.1.2'),
            new SkeletonRenderer('0.1.0'),
            new InstallationPlanner($manifestStore, new \DateTimeImmutable('2026-08-03T12:00:00+00:00')),
            new StagedWriter(new InstallerCommandValidator()),
            $cssFragmentMerger,
            new OfflineIconValidator(),
            $runner,
            $migration,
            $manifestStore,
        );
    }

    private function createCompatibleProject(): void
    {
        foreach ([
            'assets/controllers',
            'assets/styles',
            'bin',
            'config/packages',
            'config/routes',
            'migrations',
            'src/Controller',
            'src/Entity',
            'src/Repository',
            'templates',
            'translations',
        ] as $directory) {
            mkdir($this->projectDir.'/'.$directory, 0777, true);
        }

        $this->write('composer.json', <<<'JSON'
            {
                "autoload": {
                    "psr-4": {
                        "App\\": "src/"
                    }
                }
            }
            JSON);
        $this->write('bin/console', "<?php\n");
        $this->write('assets/app.js', "import './bootstrap.js';\n");
        $this->write('assets/controllers.json', "{\"controllers\": [], \"entrypoints\": []}\n");
        $this->write('assets/styles/app.css', ".application-owned { display: block; }\n");
        $this->write('importmap.php', "<?php\n\nreturn ['app' => ['path' => './assets/app.js', 'entrypoint' => true]];\n");
        $this->write('config/bundles.php', <<<'PHP'
            <?php

            return [
                Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
                Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
                Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
                Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
                Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
                Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
                Symfony\UX\Turbo\TurboBundle::class => ['all' => true],
                Symfony\UX\TwigComponent\TwigComponentBundle::class => ['all' => true],
                Symfony\UX\Icons\UXIconsBundle::class => ['all' => true],
                Symfonycasts\TailwindBundle\SymfonycastsTailwindBundle::class => ['all' => true],
                SymfonyCasts\Bundle\VerifyEmail\SymfonyCastsVerifyEmailBundle::class => ['all' => true],
                SymfonyCasts\Bundle\ResetPassword\SymfonyCastsResetPasswordBundle::class => ['all' => true],
                Te4g\UserBundle\Te4gUserBundle::class => ['all' => true],
            ];
            PHP);
        $this->write('config/packages/security.yaml', <<<'YAML'
            security:
                firewalls:
                    main:
                        lazy: true
            YAML);
        $this->write('config/routes/framework.yaml', <<<'YAML'
            _preview_error:
                resource: '@FrameworkBundle/Resources/config/routing/errors.xml'
                prefix: /_error
            YAML);
    }

    private function assertRolledBackToFreshState(): void
    {
        self::assertFileDoesNotExist($this->projectDir.'/src/Entity/User.php');
        self::assertFileDoesNotExist($this->projectDir.'/config/packages/te4g_user_tailwind.yaml');
        self::assertFileDoesNotExist($this->projectDir.'/config/te4g_user.install.json');
        self::assertFileDoesNotExist($this->projectDir.'/migrations/Version20260803010101.php');
        self::assertFileDoesNotExist($this->projectDir.'/assets/vendor/shadcn/dist/tailwind.css');
        self::assertFileDoesNotExist($this->projectDir.'/assets/vendor/tw-animate-css/dist/tw-animate.css');
        self::assertSame($this->originalCss, $this->read('assets/styles/app.css'));
        self::assertSame($this->originalImportmap, $this->read('importmap.php'));
    }

    /** @param list<string> $needles */
    private function assertOutputOrder(string $display, array $needles): void
    {
        $offset = -1;
        foreach ($needles as $needle) {
            $next = strpos($display, $needle);
            self::assertNotFalse($next, $needle);
            self::assertGreaterThan($offset, $next, $needle);
            $offset = $next;
        }
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->projectDir.'/'.$relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->projectDir.'/'.$relativePath);
        self::assertIsString($contents);

        return $contents;
    }

    /** @return array<string, string> */
    private function inventory(): array
    {
        $inventory = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $path => $info) {
            if (!$info->isFile()) {
                continue;
            }
            $relative = substr((string) $path, strlen($this->projectDir) + 1);
            if (str_starts_with($relative, 'var/te4g-user-installer/')) {
                continue;
            }
            $hash = hash_file('sha256', (string) $path);
            self::assertIsString($hash);
            $inventory[$relative] = $hash;
        }
        ksort($inventory, SORT_STRING);

        return $inventory;
    }
}

final class InstallerCommandValidator implements InstallationValidatorInterface
{
    public function validate(string $absolutePath, string $relativePath): void
    {
    }
}

final class InstallerCommandProcessRunner implements InstallerProcessRunnerInterface
{
    public const string SECRET_OUTPUT = 'database-password=do-not-leak';

    /** @var list<array{name: string, command: list<string>, cwd: string, timeout: float}> */
    public array $calls = [];

    public function __construct(
        private readonly string $projectDir,
        private readonly ?string $failAt = null,
        private readonly ?string $mutateAt = null,
        private readonly ?\Closure $mutation = null,
    ) {
    }

    public function run(array $command, string $cwd, float $timeout): InstallerProcessResult
    {
        $name = $command[2] ?? '';
        $this->calls[] = ['name' => $name, 'command' => $command, 'cwd' => $cwd, 'timeout' => $timeout];

        if ('importmap:require' === $name) {
            $this->write('importmap.php', <<<'PHP'
                <?php

                return [
                    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
                    'shadcn/dist/tailwind.css' => ['path' => './assets/vendor/shadcn/dist/tailwind.css'],
                    'tw-animate-css/dist/tw-animate.css' => ['path' => './assets/vendor/tw-animate-css/dist/tw-animate.css'],
                ];
                PHP);
            $this->write('assets/vendor/shadcn/dist/tailwind.css', "@theme inline {}\n");
            $this->write('assets/vendor/tw-animate-css/dist/tw-animate.css', ".animate-in {}\n");
        }

        if ($this->mutateAt === $name) {
            if (null === $this->mutation) {
                throw new \LogicException('A configured mutation step requires a mutation callback.');
            }
            ($this->mutation)();
        }

        return new InstallerProcessResult($this->failAt === $name ? 17 : 0);
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->projectDir.'/'.$relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }
}

final class InstallerCommandMigrationGenerator implements MigrationGeneratorInterface
{
    public const string SECRET_FAILURE = 'mapping error contains a schema secret';

    public int $calls = 0;

    public function __construct(
        private readonly string $projectDir,
        private readonly bool $fails = false,
        private readonly ?\Closure $afterGenerate = null,
    ) {
    }

    public function generate(string $projectDir): string
    {
        ++$this->calls;
        self::assertCanonicalProjectDirectory($this->projectDir, $projectDir);
        if ($this->fails) {
            throw new MigrationGenerationFailed(self::SECRET_FAILURE);
        }

        $path = $this->projectDir.'/migrations/Version20260803010101.php';
        file_put_contents($path, "<?php\n// generated migration; never executed\n");
        if (null !== $this->afterGenerate) {
            ($this->afterGenerate)();
        }

        return 'migrations/Version20260803010101.php';
    }

    private static function assertCanonicalProjectDirectory(string $expected, string $actual): void
    {
        if (realpath($expected) !== $actual) {
            throw new \LogicException('The command passed a non-canonical project directory.');
        }
    }
}
