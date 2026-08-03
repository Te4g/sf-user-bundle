<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Te4g\UserBundle\Exception\InstallerConflict;

final readonly class InstallationManifest
{
    /**
     * @param array<string, string> $files
     */
    private function __construct(
        private string $bundleVersion,
        private \DateTimeImmutable $generatedAt,
        private array $files,
    ) {
    }

    /** @param array<array-key, mixed> $files */
    public static function create(string $bundleVersion, \DateTimeImmutable $generatedAt, array $files): self
    {
        if ('' === $bundleVersion) {
            throw InstallerConflict::invalidManifest();
        }

        $validatedFiles = [];
        foreach ($files as $path => $hash) {
            if (!is_string($path)
                || !is_string($hash)
                || !self::isSafeRelativePath($path)
                || 1 !== preg_match('/^[a-f0-9]{64}$/', $hash)
            ) {
                throw InstallerConflict::invalidManifest();
            }

            $validatedFiles[$path] = $hash;
        }

        ksort($validatedFiles, SORT_STRING);

        return new self($bundleVersion, $generatedAt, $validatedFiles);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw InstallerConflict::invalidManifest();
        }

        if (!is_array($data)
            || ['bundleVersion', 'generatedAt', 'files'] !== array_keys($data)
            || !is_string($data['bundleVersion'])
            || !is_string($data['generatedAt'])
            || !is_array($data['files'])
        ) {
            throw InstallerConflict::invalidManifest();
        }

        $generatedAt = \DateTimeImmutable::createFromFormat(DATE_ATOM, $data['generatedAt']);
        if (false === $generatedAt || $generatedAt->format(DATE_ATOM) !== $data['generatedAt']) {
            throw InstallerConflict::invalidManifest();
        }

        return self::create($data['bundleVersion'], $generatedAt, $data['files']);
    }

    public function bundleVersion(): string
    {
        return $this->bundleVersion;
    }

    public function generatedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }

    /** @return array<string, string> */
    public function files(): array
    {
        return $this->files;
    }

    public function hashFor(string $relativePath): ?string
    {
        return $this->files[$relativePath] ?? null;
    }

    public function toJson(): string
    {
        return json_encode([
            'bundleVersion' => $this->bundleVersion,
            'generatedAt' => $this->generatedAt->format(DATE_ATOM),
            'files' => $this->files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private static function isSafeRelativePath(string $path): bool
    {
        if ('' === $path || str_contains($path, "\0")) {
            return false;
        }

        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/') || 1 === preg_match('/^[A-Za-z]:\//', $path)) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return false;
            }
        }

        return true;
    }
}
