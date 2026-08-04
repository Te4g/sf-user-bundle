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
use Symfonycasts\TailwindBundle\TailwindBinary;
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
    private const string TAILWIND_RUNTIME_PREFIX = 'var/tailwind/';

    /** @var array<string, string> */
    private const array IMPORTMAP_PACKAGE_PATHS = [
        'shadcn/dist/tailwind.css' => 'assets/vendor/shadcn/dist/tailwind.css',
        'tw-animate-css/dist/tw-animate.css' => 'assets/vendor/tw-animate-css/dist/tw-animate.css',
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
            $missingImportmapRequirements = $this->missingImportmapRequirements($layout, $requirements);
            $this->printPlan($io, $plan, $tailwindConfigurationNeeded, $missingImportmapRequirements);

            $currentManifest = $this->manifestStore->load($layout);
            if ([] === $plan->files()
                && !$tailwindConfigurationNeeded
                && [] === $missingImportmapRequirements
                && null !== $currentManifest
                && $this->manifestOwnsRenderedFiles($currentManifest->files(), $renderedFiles)
                && !$this->manifestContainsTailwindRuntime($currentManifest->files())
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

            if ([] !== $missingImportmapRequirements) {
                $importmapWrites = ['assets/vendor/installed.php', 'importmap.php'];
                foreach ($missingImportmapRequirements as $requirement) {
                    $importmapWrites[] = self::IMPORTMAP_PACKAGE_PATHS[$requirement];
                }
                $this->runConsoleStep(
                    $transaction,
                    $layout,
                    ['importmap:require', ...$missingImportmapRequirements, '--no-interaction'],
                    $importmapWrites,
                    [],
                    2 + count($missingImportmapRequirements),
                );
                if ([] !== $this->missingImportmapRequirements($layout, $requirements)) {
                    throw InstallerExecutionFailed::failed();
                }
                $io->writeln('Installed import-map requirements.');
            } else {
                $io->writeln('Import-map requirements were already installed.');
            }

            $this->runConsoleStep($transaction, $layout, ['lint:container', '--no-interaction']);
            $io->writeln('Validated the service container.');
            $tailwindRuntime = $this->tailwindRuntimePlan($layout);
            $this->runConsoleStep(
                $transaction,
                $layout,
                ['tailwind:build', '--no-interaction'],
                $tailwindRuntime['allowedPaths'],
                maximumChanges: count($tailwindRuntime['allowedPaths']),
            );
            $this->validateTailwindRuntime($layout, $tailwindRuntime);
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

            $persistentExternalPaths = array_values(array_filter(
                $transaction->externallyWrittenPaths(),
                static fn (string $relativePath): bool => !str_starts_with($relativePath, self::TAILWIND_RUNTIME_PREFIX),
            ));
            $manifest = $this->finalManifest($layout, $renderedFiles, $persistentExternalPaths);
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
        if (array_keys(self::IMPORTMAP_PACKAGE_PATHS) !== $requirements) {
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
        $settings = $this->tailwindSettings($layout);

        return null !== $settings['binary'] || null !== $settings['binaryVersion'];
    }

    /**
     * @return array{binary: ?string, binaryVersion: ?string, binaryPlatform: string, inputCss: string}
     */
    private function tailwindSettings(ProjectLayout $layout): array
    {
        $settings = [
            'binary' => null,
            'binaryVersion' => null,
            'binaryPlatform' => 'auto',
            'inputCss' => 'assets/styles/app.css',
        ];
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
            if (array_key_exists('binary', $tailwind)) {
                if (null !== $tailwind['binary'] && !is_string($tailwind['binary'])) {
                    throw InstallerExecutionFailed::failed();
                }
                $binary = is_string($tailwind['binary']) ? trim($tailwind['binary']) : '';
                $settings['binary'] = '' === $binary ? null : $binary;
            }
            if (array_key_exists('binary_version', $tailwind)) {
                if (null !== $tailwind['binary_version'] && !is_string($tailwind['binary_version'])) {
                    throw InstallerExecutionFailed::failed();
                }
                $version = is_string($tailwind['binary_version']) ? trim($tailwind['binary_version']) : '';
                $settings['binaryVersion'] = '' === $version ? null : 'v'.ltrim($version, 'vV');
            }
            if (array_key_exists('binary_platform', $tailwind)) {
                if (!is_string($tailwind['binary_platform']) || '' === trim($tailwind['binary_platform'])) {
                    throw InstallerExecutionFailed::failed();
                }
                $settings['binaryPlatform'] = trim($tailwind['binary_platform']);
            }
            if (array_key_exists('input_css', $tailwind)) {
                $inputCss = is_array($tailwind['input_css']) ? reset($tailwind['input_css']) : $tailwind['input_css'];
                if (!is_string($inputCss) || '' === trim($inputCss)) {
                    throw InstallerExecutionFailed::failed();
                }
                $settings['inputCss'] = trim($inputCss);
            }
        }

        return $settings;
    }

    /**
     * @param list<string> $requirements
     *
     * @return list<string>
     */
    private function missingImportmapRequirements(ProjectLayout $layout, array $requirements): array
    {
        $contents = file_get_contents($layout->path('importmap.php'));
        if (!is_string($contents)) {
            return $requirements;
        }
        $registryPath = $layout->path('assets/vendor/installed.php');
        $registry = is_file($registryPath) && !is_link($registryPath)
            ? file_get_contents($registryPath)
            : false;
        $missing = [];
        foreach ($requirements as $requirement) {
            $relativePath = self::IMPORTMAP_PACKAGE_PATHS[$requirement] ?? null;
            if (!is_string($relativePath)) {
                throw InstallerExecutionFailed::failed();
            }
            $path = $layout->path($relativePath);
            $entry = $this->phpArrayEntry($contents, $requirement);
            $registered = is_string($registry) ? $this->phpArrayEntry($registry, $requirement) : null;
            $localPaths = [$relativePath, './'.$relativePath];
            $isLocal = null !== $entry && in_array($entry['path'] ?? null, $localPaths, true);
            $isRemote = null !== $entry
                && isset($entry['version'])
                && '' !== $entry['version']
                && 'css' === ($entry['type'] ?? null)
                && null !== $registered
                && isset($registered['version'])
                && '' !== $registered['version'];
            if ((!$isLocal && !$isRemote)
                || !is_file($path)
                || is_link($path)
            ) {
                $missing[] = $requirement;
            }
        }

        return $missing;
    }

    /** @return array<string, string>|null */
    private function phpArrayEntry(string $contents, string $key): ?array
    {
        try {
            $tokens = array_values(\PhpToken::tokenize($contents, TOKEN_PARSE));
        } catch (\Throwable) {
            return null;
        }

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            if (T_CONSTANT_ENCAPSED_STRING !== $tokens[$index]->id
                || $key !== $this->phpStringLiteral($tokens[$index]->text)
            ) {
                continue;
            }
            $arrow = $this->nextPhpToken($tokens, $index + 1);
            $value = null === $arrow ? null : $this->nextPhpToken($tokens, $arrow + 1);
            if (null === $arrow || T_DOUBLE_ARROW !== $tokens[$arrow]->id || null === $value) {
                continue;
            }
            if (T_ARRAY === $tokens[$value]->id) {
                $value = $this->nextPhpToken($tokens, $value + 1);
            }
            if (null === $value || !in_array($tokens[$value]->text, ['[', '('], true)) {
                continue;
            }

            $fields = [];
            $depth = 1;
            for ($cursor = $value + 1; $cursor < $count; ++$cursor) {
                $text = $tokens[$cursor]->text;
                if (in_array($text, ['[', '(', '{'], true)) {
                    ++$depth;
                    continue;
                }
                if (in_array($text, [']', ')', '}'], true)) {
                    if (0 === --$depth) {
                        return $fields;
                    }
                    continue;
                }
                if (1 !== $depth || T_CONSTANT_ENCAPSED_STRING !== $tokens[$cursor]->id) {
                    continue;
                }
                $field = $this->phpStringLiteral($tokens[$cursor]->text);
                $fieldArrow = $this->nextPhpToken($tokens, $cursor + 1);
                $fieldValue = null === $fieldArrow ? null : $this->nextPhpToken($tokens, $fieldArrow + 1);
                if (null === $field
                    || null === $fieldArrow
                    || T_DOUBLE_ARROW !== $tokens[$fieldArrow]->id
                    || null === $fieldValue
                    || T_CONSTANT_ENCAPSED_STRING !== $tokens[$fieldValue]->id
                ) {
                    continue;
                }
                $literal = $this->phpStringLiteral($tokens[$fieldValue]->text);
                if (null !== $literal) {
                    $fields[$field] = $literal;
                }
            }

            return null;
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private function nextPhpToken(array $tokens, int $offset): ?int
    {
        for ($index = $offset, $count = count($tokens); $index < $count; ++$index) {
            if (!in_array($tokens[$index]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }

    private function phpStringLiteral(string $literal): ?string
    {
        if (strlen($literal) < 2 || !in_array($literal[0], ["'", '"'], true)) {
            return null;
        }
        $value = substr($literal, 1, -1);
        $value = "'" === $literal[0]
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], $value)
            : stripcslashes($value);

        return str_contains($value, "\0") ? null : $value;
    }

    /**
     * @return array{allowedPaths: list<string>, outputPath: string, binaryPath: ?string}
     */
    private function tailwindRuntimePlan(ProjectLayout $layout): array
    {
        $settings = $this->tailwindSettings($layout);
        $inputName = pathinfo(str_replace('\\', '/', $settings['inputCss']), PATHINFO_FILENAME);
        if (1 !== preg_match('/\A[A-Za-z0-9._-]+\z/D', $inputName)) {
            throw InstallerExecutionFailed::failed();
        }

        $outputPath = self::TAILWIND_RUNTIME_PREFIX.$inputName.'.built.css';
        $allowedPaths = [$outputPath];
        $binaryPath = null;
        if (null === $settings['binary']) {
            $version = $settings['binaryVersion'];
            if (null === $version || 1 !== preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+\z/D', $version)) {
                throw InstallerExecutionFailed::failed();
            }
            try {
                $binaryName = TailwindBinary::getBinaryName(ltrim($version, 'v'), $settings['binaryPlatform']);
            } catch (\Throwable) {
                throw InstallerExecutionFailed::failed();
            }
            $binaryPath = self::TAILWIND_RUNTIME_PREFIX.$version.'/'.$binaryName;
            $absoluteBinaryPath = $layout->path($binaryPath);
            if (is_link($absoluteBinaryPath) || (file_exists($absoluteBinaryPath) && !is_file($absoluteBinaryPath))) {
                throw InstallerExecutionFailed::failed();
            }
            if (!is_file($absoluteBinaryPath) || 0 === filesize($absoluteBinaryPath)) {
                $allowedPaths[] = $binaryPath;
            }
        }

        return [
            'allowedPaths' => $allowedPaths,
            'outputPath' => $outputPath,
            'binaryPath' => $binaryPath,
        ];
    }

    /** @param array{allowedPaths: list<string>, outputPath: string, binaryPath: ?string} $runtime */
    private function validateTailwindRuntime(ProjectLayout $layout, array $runtime): void
    {
        foreach (array_filter([$runtime['outputPath'], $runtime['binaryPath']]) as $relativePath) {
            $path = $layout->path($relativePath);
            if (!is_file($path) || is_link($path) || 0 === filesize($path)) {
                throw InstallerExecutionFailed::failed();
            }
        }
    }

    private function tailwindConfiguration(): string
    {
        return "symfonycasts_tailwind:\n    binary_version: ".self::TAILWIND_VERSION."\n";
    }

    /**
     * @param array<string, string> $manifestFiles
     * @param array<string, string> $renderedFiles
     */
    private function manifestOwnsRenderedFiles(array $manifestFiles, array $renderedFiles): bool
    {
        foreach ($renderedFiles as $relativePath => $contents) {
            $expected = hash('sha256', $contents);
            if (!isset($manifestFiles[$relativePath]) || !hash_equals($expected, $manifestFiles[$relativePath])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string> $manifestFiles */
    private function manifestContainsTailwindRuntime(array $manifestFiles): bool
    {
        foreach (array_keys($manifestFiles) as $relativePath) {
            if (str_starts_with($relativePath, self::TAILWIND_RUNTIME_PREFIX)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $renderedFiles
     * @param list<string>          $externalPaths
     */
    private function finalManifest(ProjectLayout $layout, array $renderedFiles, array $externalPaths): \Te4g\UserBundle\Installer\InstallationManifest
    {
        $files = $this->manifestStore->load($layout)?->files() ?? [];
        foreach (array_keys($files) as $relativePath) {
            if (str_starts_with($relativePath, self::TAILWIND_RUNTIME_PREFIX)) {
                unset($files[$relativePath]);
            }
        }
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

    /** @param list<string> $missingImportmapRequirements */
    private function printPlan(
        SymfonyStyle $io,
        InstallationPlan $plan,
        bool $tailwindConfigurationNeeded,
        array $missingImportmapRequirements,
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
        if ([] !== $missingImportmapRequirements) {
            $io->writeln('REQUIRE '.implode(' ', $missingImportmapRequirements));
        }
        $io->writeln('VALIDATE container, Tailwind, Twig, YAML, and offline icons');
        $io->writeln('GENERATE Doctrine migration for review');
        $io->writeln('FINALIZE '.ManifestStore::RELATIVE_PATH);
    }
}
