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
        if (!mkdir($temporaryDirectory, 0700, true) || !chmod($temporaryDirectory, 0700)) {
            throw GenerationFailed::stagingUnavailable();
        }
        $temporaryIdentity = lstat($temporaryDirectory);
        if (false === $temporaryIdentity) {
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
                $stagedPath = $this->stagedPath($temporaryDirectory, $resource->relativePath());
                if ($resource->isCreation()) {
                    $this->createTarget($stagedPath, $target, $resource->relativePath());

                    continue;
                }

                $this->replaceTarget($stagedPath, $target, $resource);
            }
        } finally {
            $this->removeTemporaryDirectory($temporaryDirectory, $temporaryIdentity);
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
        $this->createDirectories($directory, 0700, $destination);

        if (!copy($source, $destination) || !chmod($destination, 0600)) {
            throw GenerationFailed::cannotWrite($destination);
        }
    }

    private function createTarget(string $source, string $target, string $relativePath): void
    {
        $directory = dirname($target);
        $this->createDirectories($directory, 0755, $target);

        $input = fopen($source, 'rb');
        if (false === $input) {
            throw GenerationFailed::unreadableResource($source);
        }

        $destination = @fopen($target, 'xb');
        if (false === $destination) {
            fclose($input);
            if (file_exists($target) || is_link($target)) {
                throw GenerationFailed::plannedTargetChanged($relativePath, 'create');
            }

            throw GenerationFailed::cannotWrite($target);
        }

        $identity = fstat($destination);
        if (false === $identity) {
            fclose($input);
            fclose($destination);
            throw GenerationFailed::cannotWrite($target);
        }

        $complete = false;
        try {
            if (false === stream_copy_to_stream($input, $destination)) {
                throw GenerationFailed::cannotWrite($target);
            }

            if (!fflush($destination)) {
                throw GenerationFailed::cannotWrite($target);
            }

            clearstatcache(true, $target);
            $targetIdentity = lstat($target);
            if (false === $targetIdentity
                || is_link($target)
                || !is_file($target)
                || !$this->sameIdentity($identity, $targetIdentity)) {
                throw GenerationFailed::plannedTargetChanged($relativePath, 'create');
            }

            if (!chmod($target, 0644)) {
                throw GenerationFailed::cannotWrite($target);
            }

            clearstatcache(true, $target);
            $normalizedIdentity = lstat($target);
            $normalizedPermissions = fileperms($target);
            if (false === $normalizedIdentity
                || false === $normalizedPermissions
                || is_link($target)
                || !is_file($target)
                || !$this->sameIdentity($identity, $normalizedIdentity)
                || ($normalizedPermissions & 0777) !== 0644) {
                throw GenerationFailed::plannedTargetChanged($relativePath, 'create');
            }

            $complete = true;
        } finally {
            fclose($input);
            fclose($destination);

            if (!$complete) {
                $this->removeCreatedFile($target, $identity);
            }
        }
    }

    private function createDirectories(string $directory, int $permissions, string $target): void
    {
        if (is_dir($directory)) {
            return;
        }

        $missingDirectories = [];
        $candidate = $directory;
        while (!is_dir($candidate)) {
            if (file_exists($candidate) || is_link($candidate)) {
                throw GenerationFailed::cannotWrite($target);
            }

            $missingDirectories[] = $candidate;
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                throw GenerationFailed::cannotWrite($target);
            }
            $candidate = $parent;
        }

        foreach (array_reverse($missingDirectories) as $missingDirectory) {
            if (!@mkdir($missingDirectory, $permissions)) {
                throw GenerationFailed::cannotWrite($target);
            }

            $identity = lstat($missingDirectory);
            if (false === $identity
                || is_link($missingDirectory)
                || !is_dir($missingDirectory)
                || !chmod($missingDirectory, $permissions)) {
                throw GenerationFailed::cannotWrite($target);
            }

            clearstatcache(true, $missingDirectory);
            $normalizedIdentity = lstat($missingDirectory);
            $normalizedPermissions = fileperms($missingDirectory);
            if (false === $normalizedIdentity
                || false === $normalizedPermissions
                || !$this->sameIdentity($identity, $normalizedIdentity)
                || ($normalizedPermissions & 0777) !== $permissions) {
                throw GenerationFailed::cannotWrite($target);
            }
        }
    }

    /**
     * @param array<int|string, int> $expected
     * @param array<int|string, int> $actual
     */
    private function sameIdentity(array $expected, array $actual): bool
    {
        return $expected['dev'] === $actual['dev'] && $expected['ino'] === $actual['ino'];
    }

    /** @param array<int|string, int> $expectedIdentity */
    private function removeCreatedFile(string $target, array $expectedIdentity): void
    {
        clearstatcache(true, $target);
        $actualIdentity = lstat($target);
        if (false !== $actualIdentity
            && !is_link($target)
            && is_file($target)
            && $this->sameIdentity($expectedIdentity, $actualIdentity)) {
            unlink($target);
        }
    }

    private function replaceTarget(string $source, string $target, PlannedResource $resource): void
    {
        $this->assertReplacementTarget($target, $resource);
        $permissions = fileperms($target);
        if (false === $permissions) {
            throw GenerationFailed::plannedTargetChanged($resource->relativePath(), 'replacement');
        }
        $temporaryTarget = tempnam(dirname($target), '.te4g-starter-');
        if (false === $temporaryTarget) {
            throw GenerationFailed::cannotWrite($target);
        }

        try {
            $this->copy($source, $temporaryTarget);
            if (!chmod($temporaryTarget, $permissions & 0777)) {
                throw GenerationFailed::cannotWrite($target);
            }
            $this->assertReplacementTarget($target, $resource);
            if (!rename($temporaryTarget, $target)) {
                throw GenerationFailed::cannotWrite($target);
            }
        } finally {
            if (file_exists($temporaryTarget) || is_link($temporaryTarget)) {
                unlink($temporaryTarget);
            }
        }
    }

    private function assertReplacementTarget(string $target, PlannedResource $resource): void
    {
        $contents = !is_link($target) && is_file($target) && is_readable($target) ? file_get_contents($target) : false;
        if (false === $contents || $contents !== $resource->expectedContents()) {
            throw GenerationFailed::plannedTargetChanged($resource->relativePath(), 'replacement');
        }
    }

    /** @param array<int|string, int> $expectedIdentity */
    private function removeTemporaryDirectory(string $directory, array $expectedIdentity): void
    {
        $actualIdentity = lstat($directory);
        if (false === $actualIdentity) {
            return;
        }

        if (is_link($directory)) {
            unlink($directory);

            return;
        }

        if (!is_dir($directory)
            || $actualIdentity['dev'] !== $expectedIdentity['dev']
            || $actualIdentity['ino'] !== $expectedIdentity['ino']) {
            return;
        }

        $this->removeOwnedDirectory($directory);
    }

    private function removeOwnedDirectory(string $directory): void
    {
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
                $this->removeOwnedDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
