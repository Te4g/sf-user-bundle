<?php

declare(strict_types=1);

namespace Te4g\Starter\Generation;

use Te4g\Starter\Project\Conflict;
use Te4g\Starter\Project\ProjectLayout;

final readonly class ReplacementPolicy
{
    public function __construct(private string $baselineRoot)
    {
    }

    /** @param array<string, string> $resources */
    public function plan(ProjectLayout $layout, array $resources): GenerationPlan
    {
        ksort($resources, SORT_STRING);
        $writes = [];
        $conflicts = [];

        foreach ($resources as $relativePath => $sourcePath) {
            $target = $layout->path($relativePath);
            if (!file_exists($target) && !is_link($target)) {
                $writes[] = PlannedResource::create($relativePath, $sourcePath);

                continue;
            }

            if ('src/Entity/User.php' === $relativePath) {
                $conflicts[] = new Conflict($relativePath, 'an account system already exists');

                continue;
            }

            if (is_link($target)) {
                $conflicts[] = new Conflict($relativePath, 'existing target is a symlink');

                continue;
            }

            if (is_dir($target)) {
                $conflicts[] = new Conflict($relativePath, 'existing target is a directory');

                continue;
            }

            if (!is_file($target) || !is_readable($target)) {
                $conflicts[] = new Conflict($relativePath, 'existing target cannot be read');

                continue;
            }

            $baseline = $this->baselineContents($relativePath);
            $existing = file_get_contents($target);
            if (null === $baseline || false === $existing || $existing !== $baseline) {
                $conflicts[] = new Conflict($relativePath, 'existing content is not a supported fresh Symfony baseline');

                continue;
            }

            $writes[] = PlannedResource::replace($relativePath, $sourcePath, $existing);
        }

        return new GenerationPlan($writes, $conflicts);
    }

    private function baselineContents(string $relativePath): ?string
    {
        $baseline = rtrim($this->baselineRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_link($baseline) || !is_file($baseline) || !is_readable($baseline)) {
            return null;
        }

        $contents = file_get_contents($baseline);

        return false === $contents ? null : $contents;
    }
}
