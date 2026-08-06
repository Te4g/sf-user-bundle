<?php

declare(strict_types=1);

namespace Te4g\Starter\Generation;

use Te4g\Starter\Exception\GenerationFailed;
use Te4g\Starter\Project\ProjectLayout;

final readonly class StagedFileWriter
{
    public function __construct(private StaticResourceValidator $validator = new StaticResourceValidator())
    {
    }

    public function write(ProjectLayout $layout, GenerationPlan $plan): void
    {
        $plan->throwIfConflicted();
        $temporaryDirectory = sys_get_temp_dir().'/te4g-starter-generation-'.bin2hex(random_bytes(8));
        if (!mkdir($temporaryDirectory, 0700, true)) {
            throw GenerationFailed::stagingUnavailable();
        }

        try {
            foreach ($plan->writes() as $resource) {
                $stagedPath = $this->stagedPath($temporaryDirectory, $resource->relativePath());
                $this->copy($resource->sourcePath(), $stagedPath);
                $this->validator->validate($stagedPath);
            }

            foreach ($plan->writes() as $resource) {
                $target = $layout->path($resource->relativePath());
                $this->copy($this->stagedPath($temporaryDirectory, $resource->relativePath()), $target);
            }
        } finally {
            $this->removeDirectory($temporaryDirectory);
        }
    }

    private function stagedPath(string $temporaryDirectory, string $relativePath): string
    {
        $portablePath = str_replace('\\', '/', $relativePath);
        if ('' === $portablePath || str_starts_with($portablePath, '/') || str_contains($portablePath, "\0")) {
            throw GenerationFailed::unsafeResourcePath();
        }

        foreach (explode('/', $portablePath) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                throw GenerationFailed::unsafeResourcePath();
            }
        }

        return $temporaryDirectory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $portablePath);
    }

    private function copy(string $source, string $destination): void
    {
        if (is_link($source) || !is_file($source) || !is_readable($source)) {
            throw GenerationFailed::unreadableResource($source);
        }

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw GenerationFailed::cannotWrite($destination);
        }

        if (!copy($source, $destination)) {
            throw GenerationFailed::cannotWrite($destination);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
