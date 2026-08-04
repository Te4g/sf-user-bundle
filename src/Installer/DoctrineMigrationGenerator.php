<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\MigrationGenerationFailed;

final readonly class DoctrineMigrationGenerator implements MigrationGeneratorInterface
{
    public function __construct(private InstallerProcessRunnerInterface $processRunner)
    {
    }

    public function generate(string $projectDir): ?string
    {
        try {
            $layout = ProjectLayout::at($projectDir);
            $console = $layout->path('bin/console');
            $migrationDirectory = $layout->path('migrations');
            if (!is_file($console) || is_link($console) || !is_dir($migrationDirectory) || is_link($migrationDirectory)) {
                throw MigrationGenerationFailed::failed();
            }

            $before = $this->inventory($migrationDirectory);
            $result = $this->processRunner->run([
                PHP_BINARY,
                $console,
                'doctrine:migrations:diff',
                '--no-interaction',
            ], $layout->projectDir(), 120.0);
            $after = $this->inventory($migrationDirectory);

            foreach ($before as $name => $state) {
                if (!isset($after[$name]) || $state !== $after[$name]) {
                    throw MigrationGenerationFailed::failed();
                }
            }

            $created = array_values(array_diff(array_keys($after), array_keys($before)));
            sort($created, SORT_STRING);
            if (!$result->isSuccessful() || count($created) > 1) {
                throw MigrationGenerationFailed::failed();
            }
            if ([] === $created) {
                return null;
            }

            return 'migrations/'.$created[0];
        } catch (\Throwable) {
            throw MigrationGenerationFailed::failed();
        }
    }

    /** @return array<string, array{device: int, inode: int, mode: int, hash: string}> */
    private function inventory(string $directory): array
    {
        $entries = scandir($directory);
        if (false === $entries) {
            throw MigrationGenerationFailed::failed();
        }

        $inventory = [];
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            if ('' === $entry || str_contains($entry, "\0") || basename($entry) !== $entry) {
                throw MigrationGenerationFailed::failed();
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (!is_file($path) || is_link($path)) {
                throw MigrationGenerationFailed::failed();
            }
            $stat = lstat($path);
            $hash = hash_file('sha256', $path);
            if (!is_array($stat) || !is_string($hash)) {
                throw MigrationGenerationFailed::failed();
            }

            $inventory[$entry] = [
                'device' => (int) $stat['dev'],
                'inode' => (int) $stat['ino'],
                'mode' => (int) $stat['mode'],
                'hash' => $hash,
            ];
        }
        ksort($inventory, SORT_STRING);

        return $inventory;
    }
}
