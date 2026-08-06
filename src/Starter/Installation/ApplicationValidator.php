<?php

declare(strict_types=1);

namespace Te4g\Starter\Installation;

use Te4g\Starter\Exception\GenerationFailed;
use Te4g\Starter\Exception\SubprocessFailed;
use Te4g\Starter\Process\ProcessRunnerInterface;
use Te4g\Starter\Project\ProjectLayout;

final readonly class ApplicationValidator
{
    private const float PROCESS_TIMEOUT = 300.0;

    /** @var list<array{phase: string, command: list<string>}> */
    private const array VALIDATIONS = [
        [
            'phase' => 'container validation',
            'command' => ['php', 'bin/console', 'lint:container', '--no-interaction'],
        ],
        [
            'phase' => 'YAML validation',
            'command' => ['php', 'bin/console', 'lint:yaml', 'config', '--no-interaction'],
        ],
        [
            'phase' => 'Twig validation',
            'command' => ['php', 'bin/console', 'lint:twig', 'templates', '--no-interaction'],
        ],
        [
            'phase' => 'Tailwind build',
            'command' => ['php', 'bin/console', 'tailwind:build', '--no-interaction'],
        ],
        [
            'phase' => 'asset map validation',
            'command' => ['php', 'bin/console', 'debug:asset-map', '--no-interaction'],
        ],
    ];

    /** @var list<string> */
    private const array LOCKED_LUCIDE_ICONS = [
        'circle-check',
        'eye',
        'eye-off',
        'key-round',
        'mail',
        'trash-2',
        'triangle-alert',
    ];

    public function __construct(private ProcessRunnerInterface $runner)
    {
    }

    public function validate(ProjectLayout $layout): void
    {
        foreach (self::VALIDATIONS as $validation) {
            $result = $this->runner->run($validation['command'], $layout->root(), self::PROCESS_TIMEOUT);
            if (!$result->isSuccessful()) {
                throw SubprocessFailed::forResult($validation['phase'], $result);
            }
        }

        foreach (self::LOCKED_LUCIDE_ICONS as $icon) {
            $relativePath = 'assets/icons/lucide/'.$icon.'.svg';
            $path = $layout->path($relativePath);
            if (is_link($path) || !is_file($path)) {
                throw GenerationFailed::missingLockedIcon($relativePath);
            }
        }
    }
}
