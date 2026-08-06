<?php

declare(strict_types=1);

namespace Te4g\Starter\Composer;

use Te4g\Starter\Exception\SubprocessFailed;
use Te4g\Starter\Project\ProjectLayout;

final readonly class RuntimeDependencyInstaller
{
    public function __construct(private ComposerRunnerInterface $runner)
    {
    }

    public function install(ProjectLayout $layout): void
    {
        $this->require('runtime dependencies', [
            'require',
            ...RuntimeDependencySet::runtime(),
            '--no-interaction',
            '--no-progress',
            '--with-all-dependencies',
        ], $layout);
        $this->require('development dependencies', [
            'require',
            ...RuntimeDependencySet::development(),
            '--dev',
            '--no-interaction',
            '--no-progress',
            '--with-all-dependencies',
        ], $layout);
    }

    /** @param list<string> $arguments */
    private function require(string $phase, array $arguments, ProjectLayout $layout): void
    {
        $result = $this->runner->run($arguments, $layout->root());
        if (!$result->isSuccessful()) {
            throw SubprocessFailed::forResult($phase, $result);
        }
    }
}
