<?php

declare(strict_types=1);

namespace Te4g\Starter\Generation;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Te4g\Starter\Exception\GenerationFailed;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

final readonly class StaticResourceValidator
{
    /** @var list<string> */
    private const array APPLICATION_TWIG_FUNCTIONS = [
        'asset',
        'csrf_token',
        'form_end',
        'form_errors',
        'form_rest',
        'form_start',
        'form_widget',
        'html_cva',
        'importmap',
        'inject',
        'path',
        'provide',
    ];

    /** @var list<string> */
    private const array APPLICATION_TWIG_FILTERS = [
        'html_attr_type',
        'tailwind_merge',
        'trans',
    ];

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

        $environment = new Environment(new ArrayLoader([
            'form_div_layout.html.twig' => '',
        ]));
        $environment->addTokenParser(new SyntaxOnlyPropsTokenParser());

        foreach (self::APPLICATION_TWIG_FUNCTIONS as $function) {
            $environment->addFunction(new TwigFunction(
                $function,
                static fn (array $arguments = []): null => null,
                ['is_variadic' => true],
            ));
        }
        foreach (self::APPLICATION_TWIG_FILTERS as $filter) {
            $environment->addFilter(new TwigFilter(
                $filter,
                static fn (mixed $value = null, array $arguments = []): null => null,
                ['is_variadic' => true],
            ));
        }

        $environment->parse($environment->tokenize(new Source($contents, $path)));
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
