<?php

declare(strict_types=1);

namespace Te4g\Starter\Generation;

use Te4g\Starter\Exception\GenerationFailed;

final readonly class ResourceCatalog
{
    public function __construct(private string $resourceRoot)
    {
    }

    /** @return array<string, string> */
    public function files(): array
    {
        $root = realpath($this->resourceRoot);
        if (false === $root || !is_dir($root)) {
            throw GenerationFailed::resourceCatalogUnavailable();
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile()) {
                continue;
            }

            $source = $file->getRealPath();
            if (false === $source || !$this->isWithin($root, $source)) {
                throw GenerationFailed::unsafeResourcePath();
            }

            $relativePath = ltrim(substr($source, strlen($root)), DIRECTORY_SEPARATOR);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            if ('' === $relativePath || in_array('..', explode('/', $relativePath), true)) {
                throw GenerationFailed::unsafeResourcePath();
            }

            $files[$relativePath] = $source;
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    private function isWithin(string $root, string $path): bool
    {
        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }
}
