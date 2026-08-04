<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfonycasts\TailwindBundle\TailwindBinary;
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
        self::assertFileExists($this->projectDir.'/assets/vendor/installed.php');
        self::assertFileExists($this->projectDir.'/var/tailwind/app.built.css');
        self::assertFileExists($this->projectDir.'/'.$this->tailwindBinaryPath());

        $manifest = json_decode($this->read(ManifestStore::RELATIVE_PATH), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('0.1.0', $manifest['bundleVersion']);
        self::assertArrayHasKey('assets/styles/app.css', $manifest['files']);
        self::assertArrayHasKey('config/packages/te4g_user_tailwind.yaml', $manifest['files']);
        self::assertArrayHasKey('importmap.php', $manifest['files']);
        self::assertArrayHasKey('assets/vendor/shadcn/dist/tailwind.css', $manifest['files']);
        self::assertArrayHasKey('assets/vendor/tw-animate-css/dist/tw-animate.css', $manifest['files']);
        self::assertArrayHasKey('assets/vendor/installed.php', $manifest['files']);
        self::assertArrayHasKey('migrations/Version20260803010101.php', $manifest['files']);
        self::assertArrayNotHasKey('var/tailwind/app.built.css', $manifest['files']);
        self::assertArrayNotHasKey($this->tailwindBinaryPath(), $manifest['files']);

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

    public function testLegacyTailwindCacheManifestEntriesAreRemoved(): void
    {
        self::assertSame(
            Command::SUCCESS,
            $this->command(
                new InstallerCommandProcessRunner($this->projectDir),
                new InstallerCommandMigrationGenerator($this->projectDir),
            )->execute(['--no-interaction' => true], ['interactive' => false]),
        );
        $manifest = json_decode($this->read(ManifestStore::RELATIVE_PATH), true, 512, JSON_THROW_ON_ERROR);
        $manifest['files']['var/tailwind/app.built.css'] = hash_file('sha256', $this->projectDir.'/var/tailwind/app.built.css');
        $manifest['files'][$this->tailwindBinaryPath()] = hash_file('sha256', $this->projectDir.'/'.$this->tailwindBinaryPath());
        ksort($manifest['files'], SORT_STRING);
        $this->write(ManifestStore::RELATIVE_PATH, json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n");
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $tester = $this->command($runner, $migration);
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertNotSame([], $runner->calls);
        $updated = json_decode($this->read(ManifestStore::RELATIVE_PATH), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('var/tailwind/app.built.css', $updated['files']);
        self::assertArrayNotHasKey($this->tailwindBinaryPath(), $updated['files']);
    }

    public function testMissingLegacyTailwindCacheManifestEntriesDoNotBlockCleanup(): void
    {
        self::assertSame(
            Command::SUCCESS,
            $this->command(
                new InstallerCommandProcessRunner($this->projectDir),
                new InstallerCommandMigrationGenerator($this->projectDir),
            )->execute(['--no-interaction' => true], ['interactive' => false]),
        );
        $manifest = json_decode($this->read(ManifestStore::RELATIVE_PATH), true, 512, JSON_THROW_ON_ERROR);
        $manifest['files']['var/tailwind/app.built.css'] = hash_file('sha256', $this->projectDir.'/var/tailwind/app.built.css');
        $manifest['files'][$this->tailwindBinaryPath()] = hash_file('sha256', $this->projectDir.'/'.$this->tailwindBinaryPath());
        ksort($manifest['files'], SORT_STRING);
        $this->write(ManifestStore::RELATIVE_PATH, json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n");
        unlink($this->projectDir.'/var/tailwind/app.built.css');
        unlink($this->projectDir.'/'.$this->tailwindBinaryPath());

        $tester = $this->command(
            new InstallerCommandProcessRunner($this->projectDir),
            new InstallerCommandMigrationGenerator($this->projectDir),
        );
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $updated = json_decode($this->read(ManifestStore::RELATIVE_PATH), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('var/tailwind/app.built.css', $updated['files']);
        self::assertArrayNotHasKey($this->tailwindBinaryPath(), $updated['files']);
    }

    public function testChangedLegacyTailwindCacheManifestEntriesDoNotBlockCleanup(): void
    {
        self::assertSame(
            Command::SUCCESS,
            $this->command(
                new InstallerCommandProcessRunner($this->projectDir),
                new InstallerCommandMigrationGenerator($this->projectDir),
            )->execute(['--no-interaction' => true], ['interactive' => false]),
        );
        $manifest = json_decode($this->read(ManifestStore::RELATIVE_PATH), true, 512, JSON_THROW_ON_ERROR);
        $manifest['files']['var/tailwind/app.built.css'] = hash_file('sha256', $this->projectDir.'/var/tailwind/app.built.css');
        $manifest['files'][$this->tailwindBinaryPath()] = hash_file('sha256', $this->projectDir.'/'.$this->tailwindBinaryPath());
        ksort($manifest['files'], SORT_STRING);
        $this->write(ManifestStore::RELATIVE_PATH, json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n");
        $this->write('var/tailwind/app.built.css', 'changed runtime output');
        $this->write($this->tailwindBinaryPath(), 'changed runtime binary');

        $tester = $this->command(
            new InstallerCommandProcessRunner($this->projectDir),
            new InstallerCommandMigrationGenerator($this->projectDir),
        );
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $updated = json_decode($this->read(ManifestStore::RELATIVE_PATH), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('var/tailwind/app.built.css', $updated['files']);
        self::assertArrayNotHasKey($this->tailwindBinaryPath(), $updated['files']);
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

    public function testPreinstalledApplicationManagedImportmapPackagesRemainUnownedAndRepeatIsANoOp(): void
    {
        $this->write('importmap.php', <<<'PHP'
            <?php

            return [
                'app' => ['path' => './assets/app.js', 'entrypoint' => true],
                'shadcn/dist/tailwind.css' => ['path' => './assets/vendor/shadcn/dist/tailwind.css'],
                'tw-animate-css/dist/tw-animate.css' => ['path' => './assets/vendor/tw-animate-css/dist/tw-animate.css'],
            ];
            PHP);
        $this->write('assets/vendor/shadcn/dist/tailwind.css', "application shadcn\n");
        $this->write('assets/vendor/tw-animate-css/dist/tw-animate.css', "application animation\n");
        $this->write('assets/vendor/installed.php', <<<'PHP'
            <?php

            return [
                'application-owned' => ['version' => '1.0.0', 'dependencies' => [], 'extraFiles' => []],
                'shadcn/dist/tailwind.css' => ['version' => '4.16.1', 'dependencies' => [], 'extraFiles' => []],
                'tw-animate-css/dist/tw-animate.css' => ['version' => '1.4.0', 'dependencies' => [], 'extraFiles' => []],
            ];
            PHP);
        $applicationFiles = [
            'importmap.php' => $this->read('importmap.php'),
            'assets/vendor/shadcn/dist/tailwind.css' => $this->read('assets/vendor/shadcn/dist/tailwind.css'),
            'assets/vendor/tw-animate-css/dist/tw-animate.css' => $this->read('assets/vendor/tw-animate-css/dist/tw-animate.css'),
            'assets/vendor/installed.php' => $this->read('assets/vendor/installed.php'),
        ];

        $firstRunner = new InstallerCommandProcessRunner($this->projectDir);
        $firstMigration = new InstallerCommandMigrationGenerator($this->projectDir);
        $first = $this->command($firstRunner, $firstMigration);
        self::assertSame(Command::SUCCESS, $first->execute(['--no-interaction' => true], ['interactive' => false]), $first->getDisplay());
        self::assertNotContains('importmap:require', array_column($firstRunner->calls, 'name'));
        $manifest = json_decode($this->read(ManifestStore::RELATIVE_PATH), true, 512, JSON_THROW_ON_ERROR);
        foreach ($applicationFiles as $relativePath => $contents) {
            self::assertSame($contents, $this->read($relativePath));
            self::assertArrayNotHasKey($relativePath, $manifest['files']);
        }

        $beforeRepeat = $this->inventory();
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);
        $repeat = $this->command($runner, $migration);
        $status = $repeat->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $repeat->getDisplay());
        self::assertStringContainsString('Already installed; no changes required.', $repeat->getDisplay());
        self::assertSame($beforeRepeat, $this->inventory());
        self::assertSame([], $runner->calls);
        self::assertSame(0, $migration->calls);
    }

    public function testArbitraryProjectLocalImportmapAssetsRemainApplicationOwnedAndRepeatIsANoOp(): void
    {
        $this->write('importmap.php', <<<'PHP'
            <?php

            return [
                'app' => ['path' => './assets/app.js', 'entrypoint' => true],
                'shadcn/dist/tailwind.css' => ['path' => './assets/styles/application-shadcn.css'],
                'tw-animate-css/dist/tw-animate.css' => ['path' => 'assets/styles/application-animations.css'],
            ];
            PHP);
        $this->write('assets/styles/application-shadcn.css', "application shadcn\n");
        $this->write('assets/styles/application-animations.css', "application animation\n");
        $applicationFiles = [
            'importmap.php' => $this->read('importmap.php'),
            'assets/styles/application-shadcn.css' => $this->read('assets/styles/application-shadcn.css'),
            'assets/styles/application-animations.css' => $this->read('assets/styles/application-animations.css'),
        ];

        $firstRunner = new InstallerCommandProcessRunner($this->projectDir);
        $first = $this->command($firstRunner, new InstallerCommandMigrationGenerator($this->projectDir));
        self::assertSame(
            Command::SUCCESS,
            $first->execute(['--no-interaction' => true], ['interactive' => false]),
            $first->getDisplay(),
        );
        self::assertNotContains('importmap:require', array_column($firstRunner->calls, 'name'));
        $manifest = json_decode($this->read(ManifestStore::RELATIVE_PATH), true, 512, JSON_THROW_ON_ERROR);
        foreach ($applicationFiles as $relativePath => $contents) {
            self::assertSame($contents, $this->read($relativePath));
            self::assertArrayNotHasKey($relativePath, $manifest['files']);
        }

        $beforeRepeat = $this->inventory();
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);
        $repeat = $this->command($runner, $migration);
        $status = $repeat->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $repeat->getDisplay());
        self::assertStringContainsString('Already installed; no changes required.', $repeat->getDisplay());
        self::assertSame($beforeRepeat, $this->inventory());
        self::assertSame([], $runner->calls);
        self::assertSame(0, $migration->calls);
    }

    public function testTraversingApplicationImportmapAssetFailsClosedBeforeProcesses(): void
    {
        $this->write('importmap.php', <<<'PHP'
            <?php

            return [
                'app' => ['path' => './assets/app.js', 'entrypoint' => true],
                'shadcn/dist/tailwind.css' => ['path' => '../outside.css'],
            ];
            PHP);
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);
        $tester = $this->command($runner, $migration);

        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame([], $runner->calls);
        self::assertSame(0, $migration->calls);
        self::assertFileDoesNotExist($this->projectDir.'/src/Entity/User.php');
    }

    public function testSymlinkedApplicationImportmapAssetFailsClosedBeforeProcesses(): void
    {
        $outside = sys_get_temp_dir().'/te4g-user-importmap-outside-'.bin2hex(random_bytes(8)).'.css';
        file_put_contents($outside, 'outside');
        symlink($outside, $this->projectDir.'/assets/styles/application-shadcn.css');
        $this->write('importmap.php', <<<'PHP'
            <?php

            return [
                'app' => ['path' => './assets/app.js', 'entrypoint' => true],
                'shadcn/dist/tailwind.css' => ['path' => './assets/styles/application-shadcn.css'],
            ];
            PHP);
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        try {
            $tester = $this->command($runner, $migration);
            $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

            self::assertSame(Command::FAILURE, $status);
            self::assertSame([], $runner->calls);
            self::assertSame(0, $migration->calls);
            self::assertSame('outside', file_get_contents($outside));
        } finally {
            unlink($outside);
        }
    }

    public function testDynamicApplicationImportmapAssetFailsClosedBeforeProcesses(): void
    {
        $this->write('importmap.php', <<<'PHP'
            <?php

            return [
                'app' => ['path' => './assets/app.js', 'entrypoint' => true],
                'shadcn/dist/tailwind.css' => ['path' => application_shadcn_path()],
            ];
            PHP);
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);
        $tester = $this->command($runner, $migration);

        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame([], $runner->calls);
        self::assertSame(0, $migration->calls);
        self::assertFileDoesNotExist($this->projectDir.'/src/Entity/User.php');
    }

    public function testPartiallyPreinstalledImportmapRequiresOnlyMissingPackage(): void
    {
        $this->write('importmap.php', <<<'PHP'
            <?php

            return [
                'app' => ['path' => './assets/app.js', 'entrypoint' => true],
                'shadcn/dist/tailwind.css' => ['path' => './assets/vendor/shadcn/dist/tailwind.css'],
            ];
            PHP);
        $this->write('assets/vendor/shadcn/dist/tailwind.css', "application shadcn\n");
        $this->write('assets/vendor/installed.php', <<<'PHP'
            <?php return [
                'shadcn/dist/tailwind.css' => ['version' => '4.16.1', 'dependencies' => [], 'extraFiles' => []],
            ];
            PHP);
        $runner = new InstallerCommandProcessRunner($this->projectDir);
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $tester = $this->command($runner, $migration);
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertSame([
            PHP_BINARY,
            realpath($this->projectDir).'/bin/console',
            'importmap:require',
            'tw-animate-css/dist/tw-animate.css',
            '--no-interaction',
        ], $runner->calls[0]['command']);
        self::assertSame("application shadcn\n", $this->read('assets/vendor/shadcn/dist/tailwind.css'));
        $manifest = json_decode($this->read(ManifestStore::RELATIVE_PATH), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('assets/vendor/shadcn/dist/tailwind.css', $manifest['files']);
        self::assertArrayHasKey('assets/vendor/tw-animate-css/dist/tw-animate.css', $manifest['files']);
        self::assertArrayHasKey('assets/vendor/installed.php', $manifest['files']);
        self::assertArrayHasKey('importmap.php', $manifest['files']);

        $beforeRepeat = $this->inventory();
        $repeatRunner = new InstallerCommandProcessRunner($this->projectDir);
        $repeatMigration = new InstallerCommandMigrationGenerator($this->projectDir);
        $repeat = $this->command($repeatRunner, $repeatMigration);
        $repeatStatus = $repeat->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $repeatStatus, $repeat->getDisplay());
        self::assertStringContainsString('Already installed; no changes required.', $repeat->getDisplay());
        self::assertSame($beforeRepeat, $this->inventory());
        self::assertSame([], $repeatRunner->calls);
        self::assertSame(0, $repeatMigration->calls);
    }

    public function testSuccessfulImportmapProcessMustProduceEveryRequiredArtifact(): void
    {
        $runner = new InstallerCommandProcessRunner(
            $this->projectDir,
            mutateAt: 'importmap:require',
            mutation: function (): void {
                $this->write('assets/vendor/installed.php', <<<'PHP'
                    <?php return [
                        'shadcn/dist/tailwind.css' => ['version' => 'test', 'dependencies' => [], 'extraFiles' => []],
                    ];
                    PHP);
            },
        );
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $tester = $this->command($runner, $migration);
        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(['importmap:require'], array_column($runner->calls, 'name'));
        $this->assertRolledBackToFreshState();
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

    public function testImportmapRegistryAndExistingParentsRestoreAfterLaterFailure(): void
    {
        $registry = "<?php return ['application/package' => ['version' => '1.0.0']];\n";
        $this->write('assets/vendor/installed.php', $registry);
        mkdir($this->projectDir.'/assets/vendor/shadcn/dist', 0777, true);
        mkdir($this->projectDir.'/assets/vendor/tw-animate-css/dist', 0777, true);
        $runner = new InstallerCommandProcessRunner($this->projectDir, 'lint:container');
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $status = $this->command($runner, $migration)->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame($registry, $this->read('assets/vendor/installed.php'));
        self::assertSame($this->originalImportmap, $this->read('importmap.php'));
        self::assertDirectoryExists($this->projectDir.'/assets/vendor/shadcn/dist');
        self::assertDirectoryExists($this->projectDir.'/assets/vendor/tw-animate-css/dist');
        self::assertDirectoryExists($this->projectDir.'/migrations');
        self::assertFileDoesNotExist($this->projectDir.'/assets/vendor/shadcn/dist/tailwind.css');
        self::assertFileDoesNotExist($this->projectDir.'/assets/vendor/tw-animate-css/dist/tw-animate.css');
    }

    public function testTailwindCacheAndOutputAreRestoredAfterALaterFailure(): void
    {
        $this->write('var/tailwind/app.built.css', 'application compiled css');
        $this->write($this->tailwindBinaryPath(), 'application cached binary');
        $runner = new InstallerCommandProcessRunner($this->projectDir, 'lint:twig');
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $status = $this->command($runner, $migration)->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame('application compiled css', $this->read('var/tailwind/app.built.css'));
        self::assertSame('application cached binary', $this->read($this->tailwindBinaryPath()));
        self::assertDirectoryExists($this->projectDir.'/migrations');
        self::assertFileDoesNotExist($this->projectDir.'/config/te4g_user.install.json');
    }

    public function testZeroSizedTailwindBinaryIsRestoredAfterFailedDownloadAndLaterFailure(): void
    {
        $this->write($this->tailwindBinaryPath(), '');
        $runner = new InstallerCommandProcessRunner($this->projectDir, 'lint:twig');
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $status = $this->command($runner, $migration)->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertFileExists($this->projectDir.'/'.$this->tailwindBinaryPath());
        self::assertSame(0, filesize($this->projectDir.'/'.$this->tailwindBinaryPath()));
        self::assertFileDoesNotExist($this->projectDir.'/var/tailwind/app.built.css');
        self::assertDirectoryExists($this->projectDir.'/migrations');
    }

    public function testUnexpectedTailwindPathFailsClosedAndIsPreservedAsUnowned(): void
    {
        $runner = new InstallerCommandProcessRunner(
            $this->projectDir,
            mutateAt: 'tailwind:build',
            mutation: function (): void {
                $this->write('var/tailwind/unexpected.txt', 'application file');
            },
        );
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $status = $this->command($runner, $migration)->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame('application file', $this->read('var/tailwind/unexpected.txt'));
        self::assertFileDoesNotExist($this->projectDir.'/var/tailwind/app.built.css');
        self::assertFileDoesNotExist($this->projectDir.'/'.$this->tailwindBinaryPath());
        self::assertFileDoesNotExist($this->projectDir.'/config/te4g_user.install.json');
    }

    public function testUnexpectedFileKeepsInstallerCreatedDirectoryWhileOwnedFilesRollback(): void
    {
        $runner = new InstallerCommandProcessRunner(
            $this->projectDir,
            mutateAt: 'lint:container',
            mutation: function (): void {
                $this->write('assets/vendor/shadcn/dist/application.txt', 'application file');
            },
        );
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);

        $status = $this->command($runner, $migration)->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame('application file', $this->read('assets/vendor/shadcn/dist/application.txt'));
        self::assertFileDoesNotExist($this->projectDir.'/assets/vendor/shadcn/dist/tailwind.css');
        self::assertDirectoryExists($this->projectDir.'/assets/vendor/shadcn/dist');
        self::assertDirectoryExists($this->projectDir.'/migrations');
    }

    public function testConcurrentTailwindOutputEditIsNeverOverwrittenDuringRollback(): void
    {
        $runner = new InstallerCommandProcessRunner(
            $this->projectDir,
            mutateAt: 'lint:twig',
            mutation: function (): void {
                file_put_contents($this->projectDir.'/var/tailwind/app.built.css', 'concurrent application edit');
            },
        );
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);
        $tester = $this->command($runner, $migration);

        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame('concurrent application edit', $this->read('var/tailwind/app.built.css'));
        self::assertStringContainsString('Rollback incomplete; application changes were preserved.', $tester->getDisplay());
        self::assertFileDoesNotExist($this->projectDir.'/config/te4g_user.install.json');
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

    public function testExternalRollbackNeverTouchesAReplacementParentWithTheSameWrittenFileInode(): void
    {
        $outside = $this->projectDir.'-external-parent-swap';
        $runner = new InstallerCommandProcessRunner(
            $this->projectDir,
            mutateAt: 'lint:container',
            mutation: function () use ($outside): void {
                rename($this->projectDir.'/assets/vendor/shadcn/dist', $outside);
                mkdir($this->projectDir.'/assets/vendor/shadcn/dist');
                link($outside.'/tailwind.css', $this->projectDir.'/assets/vendor/shadcn/dist/tailwind.css');
                unlink($outside.'/tailwind.css');
                rmdir($outside);
            },
        );
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);
        $tester = $this->command($runner, $migration);

        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Rollback incomplete; application changes were preserved.', $tester->getDisplay());
        self::assertFileExists($this->projectDir.'/assets/vendor/shadcn/dist/tailwind.css');
        self::assertSame("@theme inline {}\n", $this->read('assets/vendor/shadcn/dist/tailwind.css'));
        self::assertFileDoesNotExist($this->projectDir.'/config/te4g_user.install.json');
    }

    public function testExternalRestoreNeverTouchesReplacementTailwindParentWithHardLinkedFiles(): void
    {
        $outside = $this->projectDir.'-tailwind-parent-swap';
        $this->write('var/tailwind/app.built.css', 'application compiled css');
        $runner = new InstallerCommandProcessRunner(
            $this->projectDir,
            mutateAt: 'lint:twig',
            mutation: function () use ($outside): void {
                rename($this->projectDir.'/var/tailwind', $outside);
                mkdir($this->projectDir.'/var/tailwind');
                link($outside.'/app.built.css', $this->projectDir.'/var/tailwind/app.built.css');
                unlink($outside.'/app.built.css');
                $version = basename(dirname($this->tailwindBinaryPath()));
                mkdir($this->projectDir.'/var/tailwind/'.$version);
                $binary = basename($this->tailwindBinaryPath());
                link($outside.'/'.$version.'/'.$binary, $this->projectDir.'/var/tailwind/'.$version.'/'.$binary);
                unlink($outside.'/'.$version.'/'.$binary);
                rmdir($outside.'/'.$version);
                rmdir($outside);
            },
        );
        $migration = new InstallerCommandMigrationGenerator($this->projectDir);
        $tester = $this->command($runner, $migration);

        $status = $tester->execute(['--no-interaction' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Rollback incomplete; application changes were preserved.', $tester->getDisplay());
        self::assertSame('compiled by Tailwind', $this->read('var/tailwind/app.built.css'));
        self::assertFileExists($this->projectDir.'/'.$this->tailwindBinaryPath());
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
        self::assertDirectoryExists($this->projectDir.'/migrations');
        self::assertFileDoesNotExist($this->projectDir.'/src/Entity/User.php');
        self::assertFileDoesNotExist($this->projectDir.'/config/packages/te4g_user_tailwind.yaml');
        self::assertFileDoesNotExist($this->projectDir.'/config/te4g_user.install.json');
        self::assertFileDoesNotExist($this->projectDir.'/migrations/Version20260803010101.php');
        self::assertFileDoesNotExist($this->projectDir.'/assets/vendor/shadcn/dist/tailwind.css');
        self::assertFileDoesNotExist($this->projectDir.'/assets/vendor/tw-animate-css/dist/tw-animate.css');
        self::assertFileDoesNotExist($this->projectDir.'/assets/vendor/installed.php');
        self::assertDirectoryDoesNotExist($this->projectDir.'/assets/vendor/shadcn');
        self::assertDirectoryDoesNotExist($this->projectDir.'/assets/vendor/tw-animate-css');
        self::assertDirectoryDoesNotExist($this->projectDir.'/var/tailwind');
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

    private function tailwindBinaryPath(): string
    {
        return 'var/tailwind/v4.3.3/'.TailwindBinary::getBinaryName('4.3.3');
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
            $requirements = array_values(array_filter(
                array_slice($command, 3),
                static fn (string $argument): bool => '--no-interaction' !== $argument,
            ));
            $existingImportmap = file_get_contents($this->projectDir.'/importmap.php') ?: '';
            $entries = "    'app' => ['path' => './assets/app.js', 'entrypoint' => true],\n";
            $remotePackages = [];
            foreach (['shadcn/dist/tailwind.css', 'tw-animate-css/dist/tw-animate.css'] as $requirement) {
                if (in_array($requirement, $requirements, true)) {
                    $version = 'shadcn/dist/tailwind.css' === $requirement ? '4.16.1' : '1.4.0';
                    $entries .= sprintf("    '%s' => ['version' => '%s', 'type' => 'css'],\n", $requirement, $version);
                    $remotePackages[$requirement] = $version;
                } elseif (str_contains($existingImportmap, "'".$requirement."'")) {
                    $entries .= sprintf("    '%s' => ['path' => './assets/vendor/%s'],\n", $requirement, $requirement);
                }
            }
            $this->write('importmap.php', "<?php\n\nreturn [\n".$entries."];\n");
            foreach ($requirements as $requirement) {
                if ('shadcn/dist/tailwind.css' === $requirement) {
                    $this->write('assets/vendor/shadcn/dist/tailwind.css', "@theme inline {}\n");
                } elseif ('tw-animate-css/dist/tw-animate.css' === $requirement) {
                    $this->write('assets/vendor/tw-animate-css/dist/tw-animate.css', ".animate-in {}\n");
                }
            }
            $registry = [];
            foreach ($remotePackages as $requirement => $version) {
                $registry[$requirement] = ['version' => $version, 'dependencies' => [], 'extraFiles' => []];
            }
            $this->write('assets/vendor/installed.php', '<?php return '.var_export($registry, true).';');
        }

        if ('tailwind:build' === $name) {
            $this->write('var/tailwind/app.built.css', 'compiled by Tailwind');
            if (is_file($this->projectDir.'/config/packages/te4g_user_tailwind.yaml')) {
                $binary = $this->projectDir.'/'.$this->tailwindBinaryPath();
                if (!is_file($binary) || 0 === filesize($binary)) {
                    $this->write($this->tailwindBinaryPath(), 'downloaded Tailwind binary');
                }
            }
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

    private function tailwindBinaryPath(): string
    {
        return 'var/tailwind/v4.3.3/'.TailwindBinary::getBinaryName('4.3.3');
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

    public function generate(string $projectDir): ?string
    {
        ++$this->calls;
        self::assertCanonicalProjectDirectory($this->projectDir, $projectDir);
        if ($this->fails) {
            throw new MigrationGenerationFailed(self::SECRET_FAILURE);
        }

        $path = $this->projectDir.'/migrations/Version20260803010101.php';
        if (is_file($path)) {
            return null;
        }
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
