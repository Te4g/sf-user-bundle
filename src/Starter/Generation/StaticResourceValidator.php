<?php

declare(strict_types=1);

namespace Te4g\Starter\Generation;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Te4g\Starter\Exception\GenerationFailed;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final readonly class StaticResourceValidator
{
    public function validate(string $path): void
    {
        try {
            match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'php' => $this->validatePhp($path),
                'yaml', 'yml' => Yaml::parseFile($path),
                'json' => $this->validateJson($path),
                'twig' => $this->validateTwig($path),
                'svg' => $this->validateSvg($path),
                default => null,
            };
        } catch (\Throwable $exception) {
            throw GenerationFailed::invalidResource($path, $exception);
        }
    }

    private function validatePhp(string $path): void
    {
        $process = new Process([PHP_BINARY, '-l', $path]);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput().$process->getOutput()));
        }
    }

    private function validateJson(string $path): void
    {
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException('Unable to read JSON resource.');
        }

        json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    private function validateTwig(string $path): void
    {
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException('Unable to read Twig resource.');
        }

        (new Environment(new ArrayLoader()))->createTemplate($contents);
    }

    private function validateSvg(string $path): void
    {
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException('Unable to read SVG resource.');
        }

        $wasUsingInternalErrors = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($contents, LIBXML_NONET)) {
                throw new \RuntimeException('Invalid SVG XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($wasUsingInternalErrors);
        }
    }
}
