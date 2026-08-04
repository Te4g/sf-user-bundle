<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use Te4g\UserBundle\Exception\InstallerConflict;
use Te4g\UserBundle\Exception\InstallerExecutionFailed;
use Te4g\UserBundle\Exception\InstallerRollbackIncomplete;
use Te4g\UserBundle\Installer\CssFragmentMerger;
use Te4g\UserBundle\Installer\InstallationPlan;
use Te4g\UserBundle\Installer\InstallationPlanner;
use Te4g\UserBundle\Installer\InstallationResult;
use Te4g\UserBundle\Installer\InstallerFilesystem;
use Te4g\UserBundle\Installer\InstallerProcessResult;
use Te4g\UserBundle\Installer\InstallerProcessRunnerInterface;
use Te4g\UserBundle\Installer\InstallerTransaction;
use Te4g\UserBundle\Installer\ManifestStore;
use Te4g\UserBundle\Installer\MigrationGeneratorInterface;
use Te4g\UserBundle\Installer\OfflineIconValidator;
use Te4g\UserBundle\Installer\PlannedFile;
use Te4g\UserBundle\Installer\PreflightChecker;
use Te4g\UserBundle\Installer\ProjectLayout;
use Te4g\UserBundle\Installer\ProjectLock;
use Te4g\UserBundle\Installer\SkeletonRenderer;
use Te4g\UserBundle\Installer\StagedWriter;

#[AsCommand(
    name: 'te4g:user:install',
    description: 'Installs the application-owned Te4g user account system.',
)]
final class InstallCommand extends Command
{
    private const string CSS_FRAGMENT = 'assets/styles/te4g_user.css.fragment';
    private const string CSS_TARGET = 'assets/styles/app.css';
    private const string IMPORTMAP_REQUIREMENTS = 'importmap.required.json';
    private const string TAILWIND_CONFIG = 'config/packages/te4g_user_tailwind.yaml';
    private const string TAILWIND_VERSION = 'v4.3.3';

    /** @var list<string> */
    private const array IMPORTMAP_PACKAGES = [
        'shadcn/dist/tailwind.css',
        'tw-animate-css/dist/tw-animate.css',
    ];

    public function __construct(
        private readonly string $projectDir,
        private readonly PreflightChecker $preflightChecker,
        private readonly SkeletonRenderer $skeletonRenderer,
        private readonly InstallationPlanner $installationPlanner,
        private readonly StagedWriter $stagedWriter,
        private readonly CssFragmentMerger $cssFragmentMerger,
        private readonly OfflineIconValidator $offlineIconValidator,
        private readonly InstallerProcessRunnerInterface $processRunner,
        private readonly MigrationGeneratorInterface $migrationGenerator,
        private readonly ManifestStore $manifestStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the installation plan without changing the application.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $transaction = null;

        try {
            $layout = ProjectLayout::at($this->projectDir);
            $conflicts = $this->preflightChecker->check($layout);
            if ([] !== $conflicts) {
                $this->printConflicts($io, $conflicts);

                return self::FAILURE;
            }
            $io->writeln('Preflight passed.');

            [$plan, $renderedFiles, $requirements] = $this->buildPlan($layout);
            $io->writeln('Rendered application files.');
            if ($plan->hasConflicts()) {
                $this->printConflicts($io, $plan->conflicts());

                return self::FAILURE;
            }

            $tailwindConfigurationNeeded = !$this->tailwindIsConfigured($layout);
            $importmapInstallationNeeded = !$this->importmapIsInstalled($layout, $requirements);
            $this->printPlan($io, $plan, $tailwindConfigurationNeeded, $importmapInstallationNeeded);

            $currentManifest = $this->manifestStore->load($layout);
            if ([] === $plan->files()
                && !$tailwindConfigurationNeeded
                && !$importmapInstallationNeeded
                && null !== $currentManifest
                && $this->manifestOwnsRenderedFiles($layout, $currentManifest->files(), $renderedFiles)
            ) {
                $io->success('Already installed; no changes required.');

                return self::SUCCESS;
            }

            if ((bool) $input->getOption('dry-run')) {
                $io->success('Dry run complete; no files or processes were changed.');

                return self::SUCCESS;
            }

            if ($input->isInteractive() && !$io->confirm('Apply this installation plan?', false)) {
                $io->writeln('Installation cancelled.');

                return self::SUCCESS;
            }

            $transaction = new InstallerTransaction($layout);
            $stagedResult = $this->applyPlanWithLock($layout, $plan);
            $transaction->attach($stagedResult, array_map(
                static fn (PlannedFile $file): string => $file->relativePath(),
                $plan->files(),
            ));
            $io->writeln('Applied staged files.');

            if ($tailwindConfigurationNeeded) {
                $transaction->write(self::TAILWIND_CONFIG, $this->tailwindConfiguration());
                $io->writeln('Configured Tailwind CSS v4.3.3.');
            } else {
                $io->writeln('Tailwind CSS was already configured.');
            }

            if ($importmapInstallationNeeded) {
                $this->runConsoleStep(
                    $transaction,
                    $layout,
                    ['importmap:require', ...$requirements, '--no-interaction'],
                    ['importmap.php'],
                    ['assets/vendor/shadcn', 'assets/vendor/tw-animate-css'],
                    3,
                );
                $io->writeln('Installed import-map requirements.');
            } else {
                $io->writeln('Import-map requirements were already installed.');
            }

            $this->runConsoleStep($transaction, $layout, ['lint:container', '--no-interaction']);
            $io->writeln('Validated the service container.');
            $this->runConsoleStep($transaction, $layout, ['tailwind:build', '--no-interaction']);
            $io->writeln('Built Tailwind CSS.');
            $this->runConsoleStep($transaction, $layout, ['lint:twig', 'templates', '--no-interaction']);
            $io->writeln('Linted Twig templates.');
            $this->runConsoleStep($transaction, $layout, ['lint:yaml', 'config', '--no-interaction']);
            $io->writeln('Linted YAML configuration.');
            $this->offlineIconValidator->validate($layout, $renderedFiles);
            $io->writeln('Verified the offline icon inventory.');

            $migrationPath = $transaction->runStep(
                fn (): ?string => $this->migrationGenerator->generate($layout->projectDir()),
                [],
                ['migrations'],
                1,
                true,
            );
            $migrationChanges = $transaction->lastChangedPaths();
            if ((null === $migrationPath && [] !== $migrationChanges)
                || (null !== $migrationPath && [$migrationPath] !== $migrationChanges)
            ) {
                throw InstallerExecutionFailed::failed();
            }
            $io->writeln(null === $migrationPath
                ? 'No Doctrine migration was required.'
                : 'Generated Doctrine migration for review.');

            $manifest = $this->finalManifest($layout, $renderedFiles, $transaction->externallyWrittenPaths());
            $transaction->write(ManifestStore::RELATIVE_PATH, $manifest->toJson());
            $io->writeln('Finalized installation manifest.');

            $transaction->commit();
            $io->success('Installation complete. Review the generated migration before running it.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $rollbackIncomplete = false;
            if ($transaction instanceof InstallerTransaction) {
                try {
                    $transaction->rollback();
                } catch (InstallerRollbackIncomplete) {
                    $rollbackIncomplete = true;
                } catch (\Throwable) {
                    $rollbackIncomplete = true;
                }
            }

            if ($rollbackIncomplete) {
                $io->error('Rollback incomplete; application changes were preserved.');
            } else {
                $io->error('Installation failed.');
            }

            return self::FAILURE;
        }
    }

    /**
     * @return array{InstallationPlan, array<string, string>, list<string>}
     */
    private function buildPlan(ProjectLayout $layout): array
    {
        $renderedFiles = $this->skeletonRenderer->renderAll($layout);
        $fragment = $renderedFiles[self::CSS_FRAGMENT] ?? null;
        $requirementsJson = $renderedFiles[self::IMPORTMAP_REQUIREMENTS] ?? null;
        if (!is_string($fragment) || !is_string($requirementsJson)) {
            throw InstallerExecutionFailed::failed();
        }
        unset($renderedFiles[self::CSS_FRAGMENT], $renderedFiles[self::IMPORTMAP_REQUIREMENTS]);

        try {
            $requirements = json_decode($requirementsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InstallerExecutionFailed::failed();
        }
        if (self::IMPORTMAP_PACKAGES !== $requirements) {
            throw InstallerExecutionFailed::failed();
        }

        $cssPath = $layout->path(self::CSS_TARGET);
        $currentCss = file_get_contents($cssPath);
        if (!is_string($currentCss)) {
            throw InstallerExecutionFailed::failed();
        }
        $mergedCss = $this->cssFragmentMerger->merge($currentCss, $fragment);

        $skeletonPlan = $this->installationPlanner->plan($layout, $renderedFiles);
        $files = $skeletonPlan->files();
        if ($mergedCss !== $currentCss) {
            $files[] = PlannedFile::managedUpdate(self::CSS_TARGET, hash('sha256', $currentCss), $mergedCss);
        }
        usort($files, static fn (PlannedFile $left, PlannedFile $right): int => $left->relativePath() <=> $right->relativePath());

        $renderedFiles[self::CSS_TARGET] = $mergedCss;
        ksort($renderedFiles, SORT_STRING);
        $hashes = [];
        foreach ($renderedFiles as $relativePath => $contents) {
            $hashes[$relativePath] = hash('sha256', $contents);
        }

        $plan = InstallationPlan::forLayout(
            $layout,
            $files,
            $skeletonPlan->conflicts(),
            $this->manifestStore->create($hashes, $skeletonPlan->manifest()->generatedAt()),
        );

        return [$plan, $renderedFiles, $requirements];
    }

    private function applyPlanWithLock(ProjectLayout $layout, InstallationPlan $plan): InstallationResult
    {
        if ([] !== $plan->files()) {
            return $this->stagedWriter->apply($plan);
        }

        $filesystem = new InstallerFilesystem();

        return new InstallationResult($layout, [], [], ProjectLock::acquire($layout, $filesystem), $filesystem);
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $allowedExactPaths
     * @param list<string> $allowedPrefixes
     */
    private function runConsoleStep(
        InstallerTransaction $transaction,
        ProjectLayout $layout,
        array $arguments,
        array $allowedExactPaths = [],
        array $allowedPrefixes = [],
        int $maximumChanges = 0,
    ): void {
        $result = $transaction->runStep(
            fn (): InstallerProcessResult => $this->processRunner->run([
                PHP_BINARY,
                $layout->path('bin/console'),
                ...$arguments,
            ], $layout->projectDir(), 120.0),
            $allowedExactPaths,
            $allowedPrefixes,
            $maximumChanges,
        );
        if (!$result->isSuccessful()) {
            throw InstallerExecutionFailed::failed();
        }
    }

    private function tailwindIsConfigured(ProjectLayout $layout): bool
    {
        $directory = $layout->path('config/packages');
        foreach (scandir($directory) ?: [] as $entry) {
            if (!preg_match('/\.ya?ml$/', $entry)) {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            try {
                $config = Yaml::parseFile($path);
            } catch (\Throwable) {
                throw InstallerExecutionFailed::failed();
            }
            $tailwind = is_array($config) ? ($config['symfonycasts_tailwind'] ?? null) : null;
            if (!is_array($tailwind)) {
                continue;
            }
            if (isset($tailwind['binary']) && is_string($tailwind['binary']) && '' !== trim($tailwind['binary'])) {
                return true;
            }
            if (isset($tailwind['binary_version']) && is_string($tailwind['binary_version']) && '' !== trim($tailwind['binary_version'])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $requirements */
    private function importmapIsInstalled(ProjectLayout $layout, array $requirements): bool
    {
        $contents = file_get_contents($layout->path('importmap.php'));
        if (!is_string($contents)) {
            return false;
        }
        foreach ($requirements as $requirement) {
            if (!str_contains($contents, "'".$requirement."'") && !str_contains($contents, '"'.$requirement.'"')) {
                return false;
            }
        }

        foreach ([
            'assets/vendor/shadcn/dist/tailwind.css',
            'assets/vendor/tw-animate-css/dist/tw-animate.css',
        ] as $relativePath) {
            $path = $layout->path($relativePath);
            if (!is_file($path) || is_link($path)) {
                return false;
            }
        }

        return true;
    }

    private function tailwindConfiguration(): string
    {
        return "symfonycasts_tailwind:\n    binary_version: ".self::TAILWIND_VERSION."\n";
    }

    /**
     * @param array<string, string> $manifestFiles
     * @param array<string, string> $renderedFiles
     */
    private function manifestOwnsRenderedFiles(ProjectLayout $layout, array $manifestFiles, array $renderedFiles): bool
    {
        foreach ($renderedFiles as $relativePath => $contents) {
            $expected = hash('sha256', $contents);
            if (!isset($manifestFiles[$relativePath]) || !hash_equals($expected, $manifestFiles[$relativePath])) {
                return false;
            }
        }

        $ownedExternalPaths = [
            'importmap.php',
            'assets/vendor/shadcn/dist/tailwind.css',
            'assets/vendor/tw-animate-css/dist/tw-animate.css',
        ];
        if (isset($manifestFiles[self::TAILWIND_CONFIG])) {
            $ownedExternalPaths[] = self::TAILWIND_CONFIG;
        }

        foreach ($ownedExternalPaths as $relativePath) {
            $path = $layout->path($relativePath);
            $expected = $manifestFiles[$relativePath] ?? null;
            $actual = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            if (!is_string($expected) || !is_string($actual) || !hash_equals($expected, $actual)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $renderedFiles
     * @param list<string>          $externalPaths
     */
    private function finalManifest(ProjectLayout $layout, array $renderedFiles, array $externalPaths): \Te4g\UserBundle\Installer\InstallationManifest
    {
        $files = $this->manifestStore->load($layout)?->files() ?? [];
        $paths = array_values(array_unique([...array_keys($renderedFiles), ...$externalPaths]));
        foreach ($paths as $relativePath) {
            if (ManifestStore::RELATIVE_PATH === $relativePath) {
                continue;
            }
            $path = $layout->path($relativePath);
            if (!is_file($path) || is_link($path)) {
                throw InstallerExecutionFailed::failed();
            }
            $hash = hash_file('sha256', $path);
            if (!is_string($hash)) {
                throw InstallerExecutionFailed::failed();
            }
            $files[$relativePath] = $hash;
        }

        return $this->manifestStore->create($files, new \DateTimeImmutable());
    }

    /** @param list<InstallerConflict> $conflicts */
    private function printConflicts(SymfonyStyle $io, array $conflicts): void
    {
        $io->error('Installation conflicts must be resolved before continuing.');
        foreach ($conflicts as $conflict) {
            $io->writeln(sprintf(
                'CONFLICT %s%s',
                null === $conflict->relativePath() ? '' : $conflict->relativePath().' ',
                $conflict->code(),
            ));
        }
    }

    private function printPlan(
        SymfonyStyle $io,
        InstallationPlan $plan,
        bool $tailwindConfigurationNeeded,
        bool $importmapInstallationNeeded,
    ): void {
        $io->writeln('Installation plan:');
        foreach ($plan->files() as $file) {
            $action = self::CSS_TARGET === $file->relativePath()
                ? 'MERGE'
                : ($file->isCreate() ? 'CREATE' : 'UPDATE');
            $io->writeln(sprintf('%s %s', $action, $file->relativePath()));
        }
        if ($tailwindConfigurationNeeded) {
            $io->writeln('CONFIGURE Tailwind CSS v4.3.3');
        }
        if ($importmapInstallationNeeded) {
            $io->writeln('REQUIRE '.implode(' ', self::IMPORTMAP_PACKAGES));
        }
        $io->writeln('VALIDATE container, Tailwind, Twig, YAML, and offline icons');
        $io->writeln('GENERATE Doctrine migration for review');
        $io->writeln('FINALIZE '.ManifestStore::RELATIVE_PATH);
    }
}
