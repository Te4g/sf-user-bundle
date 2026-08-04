<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerExecutionFailed;

final readonly class OfflineIconValidator
{
    /** @param array<string, string> $managedFiles */
    public function validate(ProjectLayout $layout, array $managedFiles): void
    {
        $token = '[a-z0-9]+(?:-[a-z0-9]+)*';
        $pattern = "#(?:'$token:$token')|(?:\"$token:$token\")#i";
        $icons = [];

        foreach ($managedFiles as $relativePath => $contents) {
            if (!str_starts_with($relativePath, 'templates/') || !str_ends_with($relativePath, '.twig')) {
                continue;
            }
            if (preg_match_all($pattern, $contents, $matches)) {
                foreach ($matches[0] as $match) {
                    $icons[] = trim($match, "'\"");
                }
            }
        }

        $icons = array_values(array_unique($icons));
        sort($icons, SORT_STRING);
        foreach ($icons as $icon) {
            [$prefix, $name] = explode(':', $icon, 2);
            $relativePath = sprintf('assets/icons/%s/%s.svg', $prefix, $name);
            $expected = $managedFiles[$relativePath] ?? null;
            if (!is_string($expected)) {
                throw InstallerExecutionFailed::failed();
            }

            try {
                $path = $layout->path($relativePath);
            } catch (\Throwable) {
                throw InstallerExecutionFailed::failed();
            }
            $actual = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            if (!is_string($actual) || !hash_equals(hash('sha256', $expected), $actual)) {
                throw InstallerExecutionFailed::failed();
            }
        }
    }
}
