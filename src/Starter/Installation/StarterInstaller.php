<?php

declare(strict_types=1);

namespace Te4g\Starter\Installation;

use Te4g\Starter\Composer\RuntimeDependencyInstaller;
use Te4g\Starter\Exception\SubprocessFailed;
use Te4g\Starter\Generation\ReplacementPolicy;
use Te4g\Starter\Generation\ResourceCatalog;
use Te4g\Starter\Generation\StagedFileWriter;
use Te4g\Starter\Process\ProcessRunnerInterface;
use Te4g\Starter\Project\ProjectInspector;
use Te4g\Starter\Project\ProjectLayout;

final readonly class StarterInstaller
{
    private const float PROCESS_TIMEOUT = 300.0;

    public function __construct(
        private ProjectInspector $inspector,
        private ResourceCatalog $resources,
        private ReplacementPolicy $replacementPolicy,
        private RuntimeDependencyInstaller $dependencies,
        private ProcessRunnerInterface $processRunner,
        private StagedFileWriter $writer,
        private ApplicationValidator $validator,
    ) {
    }

    /** @param callable(string): void $progress */
    public function install(ProjectLayout $layout, callable $progress): void
    {
        $report = $this->inspector->inspect($layout);
        $report->throwIfIncompatible();
        $resources = $this->resources->files();
        $this->replacementPolicy->plan($layout, $resources)->throwIfConflicted();
        $progress('preflight');

        $this->dependencies->install($layout);
        $progress('dependencies');

        $this->runImportMapRequirements($layout);
        $progress('importmap');

        $plan = $this->replacementPolicy->plan($layout, $resources);
        $plan->throwIfConflicted();
        $this->writer->write($layout, $plan);
        $progress('generation');

        $this->validator->validate($layout);
        $progress('validation');
    }

    private function runImportMapRequirements(ProjectLayout $layout): void
    {
        $command = [
            'php',
            'bin/console',
            'importmap:require',
            'shadcn/dist/tailwind.css',
            'tw-animate-css/dist/tw-animate.css',
            '--no-interaction',
        ];
        $result = $this->processRunner->run($command, $layout->root(), self::PROCESS_TIMEOUT);
        if (!$result->isSuccessful()) {
            throw SubprocessFailed::forResult('import map requirements', $result);
        }
    }
}
