#!/usr/bin/env php
<?php

declare(strict_types=1);

use SymfonyBundleTemplate\Configurator\BundleNames;
use SymfonyBundleTemplate\Configurator\ProjectConfigurator;

require dirname(__DIR__).'/vendor/autoload.php';

function usage(): void
{
    fwrite(STDERR, 'Usage: php scripts/rename.php "Teag\\Bundle\\InvoiceBundle"'.PHP_EOL);
}

$namespace = $argv[1] ?? null;

if (null === $namespace || '' === trim($namespace)) {
    usage();
    exit(1);
}

try {
    $names = BundleNames::fromNamespace($namespace);

    (new ProjectConfigurator())->configure(dirname(__DIR__), $names);

    fwrite(STDOUT, sprintf('Bundle renamed to %s.'.PHP_EOL, $names->namespace));
    fwrite(STDOUT, sprintf('Package name: %s.'.PHP_EOL, $names->packageName));
    fwrite(STDOUT, sprintf('Bundle alias: %s.'.PHP_EOL, $names->alias));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
