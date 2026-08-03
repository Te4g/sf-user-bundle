<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Installer;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Te4g\UserBundle\Exception\InstallerWriteFailed;
use Twig\Environment;
use Twig\Source;

final readonly class InstallationValidator implements InstallationValidatorInterface
{
    public function __construct(private Environment $twig)
    {
    }

    public function validate(string $absolutePath, string $relativePath): void
    {
        try {
            if (str_ends_with($relativePath, '.php')) {
                $this->validatePhp($absolutePath);
            } elseif (str_ends_with($relativePath, '.yaml') || str_ends_with($relativePath, '.yml')) {
                Yaml::parseFile($absolutePath);
            } elseif (str_ends_with($relativePath, '.json')) {
                $this->validateJson($absolutePath);
            } elseif (str_ends_with($relativePath, '.twig')) {
                $this->validateTwig($absolutePath, $relativePath);
            }
        } catch (InstallerWriteFailed $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw InstallerWriteFailed::validationFailed($exception);
        }
    }

    private function validatePhp(string $absolutePath): void
    {
        $process = new Process([PHP_BINARY, '-l', $absolutePath]);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw InstallerWriteFailed::validationFailed();
        }
    }

    /** @throws \JsonException */
    private function validateJson(string $absolutePath): void
    {
        $contents = file_get_contents($absolutePath);
        if (false === $contents) {
            throw InstallerWriteFailed::validationFailed();
        }

        json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    private function validateTwig(string $absolutePath, string $relativePath): void
    {
        $contents = file_get_contents($absolutePath);
        if (false === $contents) {
            throw InstallerWriteFailed::validationFailed();
        }

        $this->twig->parse($this->twig->tokenize(new Source($contents, $relativePath)));
    }
}
