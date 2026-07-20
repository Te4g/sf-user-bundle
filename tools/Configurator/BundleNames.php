<?php

declare(strict_types=1);

namespace SymfonyBundleTemplate\Configurator;

use Symfony\Component\DependencyInjection\Container;

final readonly class BundleNames
{
    private function __construct(
        public string $namespace,
        public string $namespacePrefix,
        public string $testNamespace,
        public string $testNamespacePrefix,
        public string $bundleClass,
        public string $extensionClass,
        public string $alias,
        public string $messageParameter,
        public string $packageName,
    ) {
    }

    public static function fromNamespace(string $namespace): self
    {
        $namespace = trim($namespace, " \t\n\r\0\x0B\\");

        if ('' === $namespace) {
            throw new \InvalidArgumentException('The bundle namespace cannot be empty.');
        }

        if (1 !== preg_match('/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)+$/', $namespace)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid bundle namespace.', $namespace));
        }

        $parts = explode('\\', $namespace);
        $vendor = $parts[0];
        $bundleClass = $parts[count($parts) - 1];

        if (!str_ends_with($bundleClass, 'Bundle')) {
            throw new \InvalidArgumentException(sprintf('The bundle class "%s" must end with "Bundle".', $bundleClass));
        }

        $bundleBaseName = substr($bundleClass, 0, -strlen('Bundle'));

        if ('' === $bundleBaseName) {
            throw new \InvalidArgumentException('The bundle class must include a name before the "Bundle" suffix.');
        }

        $alias = self::pascalToSnake($bundleBaseName);

        return new self(
            namespace: $namespace,
            namespacePrefix: $namespace.'\\',
            testNamespace: $namespace.'\\Tests',
            testNamespacePrefix: $namespace.'\\Tests\\',
            bundleClass: $bundleClass,
            extensionClass: $bundleBaseName.'Extension',
            alias: $alias,
            messageParameter: $alias.'.message',
            packageName: self::pascalToKebab($vendor).'/'.self::pascalToKebab($bundleClass),
        );
    }

    private static function pascalToSnake(string $value): string
    {
        return Container::underscore($value);
    }

    private static function pascalToKebab(string $value): string
    {
        return str_replace('_', '-', self::pascalToSnake($value));
    }
}
