<?php

declare(strict_types=1);

namespace Te4g\Starter\Tests\Fixtures;

use Symfony\Component\Process\Process;

final readonly class PinnedTailwindBinary
{
    private const array VERSION_4_3_3_SHA256 = [
        'Darwin-arm64' => 'cdf646702987a743464dff4d9c60fd4480d1c1e73dd819a9a67f1078815dce9d',
        'Darwin-x86_64' => '7922e0953f2110c05976e3bf58f14e643d90427575e766b7d433f5f80cbee7e1',
        'Linux-arm64' => '55fd0b241214eff3de1e8ee4f22796662f2d2e7a49bcfca7477cfd0bac398195',
        'Linux-x86_64' => 'dc61b3ac6b8c9ca874c0cc4c57b2409791a64c5540404ca5f5367360babc313a',
        'Windows-x86_64' => 'e0e260ce048014e9268f6237ff18f8ccf02cef521cbd0ae04e82c2cdf7aa3955',
    ];

    public function __construct(
        private string $expectedVersion,
        private string $expectedSha256,
    ) {
    }

    public static function tailwind433(): self
    {
        $platform = PHP_OS_FAMILY.'-'.self::architecture();
        $sha256 = self::VERSION_4_3_3_SHA256[$platform] ?? null;
        if (null === $sha256) {
            throw new \InvalidArgumentException(sprintf('Tailwind CSS 4.3.3 has no pinned SHA-256 for platform %s.', $platform));
        }

        return new self('4.3.3', $sha256);
    }

    public function validate(?string $path): string
    {
        if (null === $path || '' === trim($path)) {
            throw new \InvalidArgumentException('TE4G_TAILWIND_BINARY must point to a pre-provisioned Tailwind CSS 4.3.3 binary.');
        }
        if (is_link($path)) {
            throw new \InvalidArgumentException('TE4G_TAILWIND_BINARY must not be a symlink.');
        }
        if (!is_file($path) || !is_executable($path)) {
            throw new \InvalidArgumentException('TE4G_TAILWIND_BINARY must be a regular executable file.');
        }

        $sha256 = hash_file('sha256', $path);
        if (false === $sha256 || !hash_equals($this->expectedSha256, $sha256)) {
            throw new \InvalidArgumentException(sprintf('TE4G_TAILWIND_BINARY has the wrong SHA-256; expected %s.', $this->expectedSha256));
        }

        $process = new Process([$path, '--help']);
        $process->setTimeout(10);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        $plainOutput = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output);
        if (!$process->isSuccessful() || null === $plainOutput || !preg_match('/(?:\A|\R)≈ tailwindcss v'.preg_quote($this->expectedVersion, '/').'(?:\R|\z)/', $plainOutput)) {
            throw new \InvalidArgumentException(sprintf('TE4G_TAILWIND_BINARY must report exactly Tailwind CSS %s.', $this->expectedVersion));
        }

        return $path;
    }

    private static function architecture(): string
    {
        return match (strtolower(php_uname('m'))) {
            'aarch64', 'arm64' => 'arm64',
            'amd64', 'x86_64' => 'x86_64',
            default => strtolower(php_uname('m')),
        };
    }
}
