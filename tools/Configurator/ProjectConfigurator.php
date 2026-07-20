<?php

declare(strict_types=1);

namespace SymfonyBundleTemplate\Configurator;

final class ProjectConfigurator
{
    private const OLD_NAMESPACE = 'Acme\Bundle\TemplateBundle';
    private const OLD_NAMESPACE_PREFIX = self::OLD_NAMESPACE.'\\';
    private const OLD_TEST_NAMESPACE_PREFIX = self::OLD_NAMESPACE.'\Tests\\';
    private const OLD_BUNDLE_CLASS = 'TemplateBundle';
    private const OLD_EXTENSION_CLASS = 'TemplateExtension';
    private const OLD_ALIAS = 'template';
    private const OLD_PACKAGE_NAME = 'acme/bundle-template';
    private const OLD_MESSAGE_PARAMETER = 'template.message';

    /**
     * @var list<string>
     */
    private const REQUIRED_FILES = [
        'composer.json',
        'README.md',
        'src/TemplateBundle.php',
        'src/DependencyInjection/TemplateExtension.php',
        'src/DependencyInjection/Configuration.php',
        'src/Service/ExampleService.php',
        'config/services.php',
        'tests/TemplateBundleTest.php',
        'tests/DependencyInjection/ConfigurationTest.php',
        'tests/Fixtures/App/TestKernel.php',
        'tests/Integration/BundleKernelTest.php',
    ];

    /**
     * @var list<string>
     */
    private const TEXT_FILES = [
        'composer.json',
        'README.md',
        'src/TemplateBundle.php',
        'src/DependencyInjection/TemplateExtension.php',
        'src/DependencyInjection/Configuration.php',
        'src/Service/ExampleService.php',
        'config/services.php',
        'tests/TemplateBundleTest.php',
        'tests/DependencyInjection/ConfigurationTest.php',
        'tests/Fixtures/App/TestKernel.php',
        'tests/Integration/BundleKernelTest.php',
    ];

    public function configure(string $projectDir, BundleNames $names): void
    {
        $projectDir = rtrim(trim($projectDir), '/');

        if ('' === $projectDir) {
            throw new \RuntimeException('The project directory cannot be empty.');
        }

        $this->assertRequiredFilesExist($projectDir);
        $this->assertRenameTargetsDoNotExist($projectDir, $names);

        $composer = $this->readComposer($projectDir);

        foreach (self::TEXT_FILES as $file) {
            if ('composer.json' === $file || 'README.md' === $file) {
                continue;
            }

            $contents = $this->rewriteText($this->readFile($projectDir.'/'.$file), $names);

            if (str_ends_with($file, '.php')) {
                $contents = $this->sortTopLevelUseStatements($contents);
            }

            $this->writeFile($projectDir.'/'.$file, $contents);
        }

        $this->writeComposer($projectDir, $composer, $names);
        $this->writeFile($projectDir.'/README.md', $this->buildGeneratedReadme($names));
        $this->renameFile($projectDir, 'src/'.self::OLD_BUNDLE_CLASS.'.php', 'src/'.$names->bundleClass.'.php');
        $this->renameFile(
            $projectDir,
            'tests/'.self::OLD_BUNDLE_CLASS.'Test.php',
            'tests/'.$names->bundleClass.'Test.php',
        );
        $this->renameFile(
            $projectDir,
            'src/DependencyInjection/'.self::OLD_EXTENSION_CLASS.'.php',
            'src/DependencyInjection/'.$names->extensionClass.'.php',
        );
        $this->removeDirectory($projectDir.'/tests/Configurator');
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposer(string $projectDir): array
    {
        try {
            $composer = json_decode($this->readFile($projectDir.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('composer.json could not be parsed: '.$exception->getMessage(), 0, $exception);
        }

        if (!is_array($composer)) {
            throw new \RuntimeException('composer.json must contain a JSON object.');
        }

        return $composer;
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function writeComposer(string $projectDir, array $composer, BundleNames $names): void
    {
        $autoloadDev = [];

        if (isset($composer['autoload-dev']) && is_array($composer['autoload-dev'])) {
            $autoloadDev = $composer['autoload-dev'];
        }

        $autoloadDevPsr4 = [];

        if (isset($autoloadDev['psr-4']) && is_array($autoloadDev['psr-4'])) {
            foreach ($autoloadDev['psr-4'] as $namespace => $path) {
                if (self::OLD_TEST_NAMESPACE_PREFIX === $namespace) {
                    continue;
                }

                if (!is_string($namespace) || !is_string($path)) {
                    continue;
                }

                $autoloadDevPsr4[$namespace] = $path;
            }
        }

        $autoloadDevPsr4[$names->testNamespacePrefix] = 'tests/';

        $composer['name'] = $names->packageName;
        $composer['autoload'] = [
            'psr-4' => [
                $names->namespacePrefix => 'src/',
            ],
        ];
        $composer['autoload-dev'] = array_merge($autoloadDev, [
            'psr-4' => $autoloadDevPsr4,
        ]);

        $contents = json_encode($composer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $this->writeFile($projectDir.'/composer.json', $contents.PHP_EOL);
    }

    private function rewriteText(string $contents, BundleNames $names): string
    {
        $replacements = [
            self::OLD_NAMESPACE_PREFIX => $names->namespacePrefix,
            self::OLD_NAMESPACE => $names->namespace,
            self::OLD_TEST_NAMESPACE_PREFIX => $names->testNamespacePrefix,
            self::OLD_BUNDLE_CLASS => $names->bundleClass,
            self::OLD_EXTENSION_CLASS => $names->extensionClass,
            self::OLD_MESSAGE_PARAMETER => $names->messageParameter,
            '\''.self::OLD_ALIAS.'\'' => '\''.$names->alias.'\'',
            '"'.self::OLD_ALIAS.'"' => '"'.$names->alias.'"',
            self::OLD_PACKAGE_NAME => $names->packageName,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $contents);
    }

    private function buildGeneratedReadme(BundleNames $names): string
    {
        return <<<MARKDOWN
# {$names->bundleClass}

Symfony bundle package with configuration, dependency injection, service wiring, and tests already in place.

Package: `{$names->packageName}`
Bundle namespace: `{$names->namespace}`
Bundle alias: `{$names->alias}`

## Requirements

- PHP 8.4
- Composer
- Symfony 7.x components

## Install

```bash
composer require {$names->packageName}
```

## Development

Refresh Composer autoload metadata and run the full quality suite:

```bash
composer dump-autoload
make test
```

Run individual checks:

```bash
make cs-check
make cs-fix
make stan
make phpunit
```

## Project Structure

- `src/` contains the bundle PHP code.
- `src/DependencyInjection/` contains configuration and extension classes.
- `src/Service/ExampleService.php` provides a minimal service wired through bundle configuration.
- `config/services.php` registers bundle services.
- `tests/` contains PHPUnit unit and integration tests.
- `tests/Fixtures/App/TestKernel.php` boots the bundle in a fixture Symfony kernel.

## Add Bundle Behavior

Use the existing example flow as the path for real behavior:

1. Add or update config options in `src/DependencyInjection/Configuration.php`.
2. Map config values to extension parameters in `src/DependencyInjection/*Extension.php`.
3. Wire services in `config/services.php`.
4. Cover small behavior with unit tests in `tests/`.
5. Cover container and bundle boot behavior with fixture-kernel integration tests.
6. Remove or replace `src/Service/ExampleService.php` after real bundle behavior exists.

## Publish To Packagist

Packagist package names cannot be changed after the first submission, so confirm the `name` field before publishing. Packagist derives versions from Git tags; remove the template's fixed `version` field and validate the package before the first release:

```bash
composer config --unset version
composer validate --strict
make test
```

Commit and push the release, then create and push a semantic-version tag:

```bash
git add composer.json README.md
git commit -m "Prepare 1.0.0 release"
git push origin main
git tag v1.0.0
git push origin v1.0.0
```

Sign in to [Packagist](https://packagist.org) with GitHub and submit the public repository at [packagist.org/packages/new](https://packagist.org/packages/new). Confirm that the Packagist GitHub application can access the repository so later pushes and tags synchronize automatically.

For each subsequent release, run the quality gate, push a new semantic-version tag, and verify installation with `composer require {$names->packageName}:^1.0` in a clean Symfony application. See the [Packagist publishing guide](https://packagist.org/about) for current platform details.

## Test In A Symfony Application

Use a separate Symfony skeleton to verify bundle registration, configuration, and services outside this bundle's test fixture. Before publishing, install the local bundle through a Composer path repository:

```bash
composer create-project symfony/skeleton bundle-smoke-test
cd bundle-smoke-test
composer config repositories.local-bundle path ../path/to/your-bundle
composer require {$names->packageName}:@dev
```

Enable the bundle in `config/bundles.php` when Symfony Flex has not installed a recipe:

```php
return [
    // ...
    {$names->namespace}\\{$names->bundleClass}::class => ['all' => true],
];
```

Configure its alias in `config/packages/{$names->alias}.yaml`, then verify that Symfony boots it and resolves its configuration and services:

```yaml
{$names->alias}:
    message: 'Bundle integration test'
```

```bash
php bin/console cache:clear
php bin/console debug:config {$names->alias}
php bin/console debug:container '{$names->namespace}\\Service\\ExampleService'
php bin/console debug:container --parameter={$names->messageParameter}
```

After publishing, switch the smoke-test application to a release tag, then repeat the same console checks:

```bash
composer config --unset repositories.local-bundle
composer require {$names->packageName}:^1.0
```

Replace `ExampleService` and the example setting with the bundle's real public behavior as it evolves.

MARKDOWN;
    }

    private function sortTopLevelUseStatements(string $contents): string
    {
        $hasFinalNewline = str_ends_with($contents, PHP_EOL);
        $lines = explode(PHP_EOL, $hasFinalNewline ? substr($contents, 0, -strlen(PHP_EOL)) : $contents);
        $useStart = null;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, 'use ') && str_ends_with($trimmed, ';')) {
                $useStart = $index;

                break;
            }

            if (
                '' === $trimmed
                || '<?php' === $trimmed
                || str_starts_with($trimmed, 'declare(')
                || str_starts_with($trimmed, 'namespace ')
            ) {
                continue;
            }

            return $contents;
        }

        if (null === $useStart) {
            return $contents;
        }

        $useEnd = $useStart;

        while (isset($lines[$useEnd])) {
            $trimmed = trim($lines[$useEnd]);

            if ('' === $trimmed || (str_starts_with($trimmed, 'use ') && str_ends_with($trimmed, ';'))) {
                ++$useEnd;

                continue;
            }

            break;
        }

        $uses = [];

        foreach (array_slice($lines, $useStart, $useEnd - $useStart) as $line) {
            $trimmed = trim($line);

            if ('' === $trimmed) {
                continue;
            }

            if (str_starts_with($trimmed, 'use function ')) {
                $uses[] = [
                    'line' => $trimmed,
                    'sort' => substr($trimmed, strlen('use function ')),
                    'type' => 'function',
                ];

                continue;
            }

            if (str_starts_with($trimmed, 'use const ')) {
                $uses[] = [
                    'line' => $trimmed,
                    'sort' => substr($trimmed, strlen('use const ')),
                    'type' => 'const',
                ];

                continue;
            }

            $uses[] = [
                'line' => $trimmed,
                'sort' => substr($trimmed, strlen('use ')),
                'type' => 'class',
            ];
        }

        usort(
            $uses,
            static fn (array $left, array $right): int => strcasecmp($left['sort'], $right['sort'])
                ?: strcmp($left['line'], $right['line']),
        );

        $sortedUses = [];
        $previousType = null;

        foreach ($uses as $use) {
            if (null !== $previousType && $previousType !== $use['type']) {
                $sortedUses[] = '';
            }

            $sortedUses[] = $use['line'];
            $previousType = $use['type'];
        }

        if ([] !== $sortedUses) {
            $sortedUses[] = '';
        }

        array_splice($lines, $useStart, $useEnd - $useStart, $sortedUses);

        return implode(PHP_EOL, $lines).($hasFinalNewline ? PHP_EOL : '');
    }

    private function assertRequiredFilesExist(string $projectDir): void
    {
        foreach (self::REQUIRED_FILES as $file) {
            if (!is_file($projectDir.'/'.$file)) {
                throw new \RuntimeException('Required template file is missing: '.$file);
            }
        }
    }

    private function assertRenameTargetsDoNotExist(string $projectDir, BundleNames $names): void
    {
        foreach ([
            'src/'.$names->bundleClass.'.php',
            'src/DependencyInjection/'.$names->extensionClass.'.php',
            'tests/'.$names->bundleClass.'Test.php',
        ] as $file) {
            if (is_file($projectDir.'/'.$file)) {
                throw new \RuntimeException('Target file already exists: '.$file);
            }
        }
    }

    private function readFile(string $file): string
    {
        $contents = file_get_contents($file);

        if (!is_string($contents)) {
            throw new \RuntimeException('File could not be read: '.$file);
        }

        return $contents;
    }

    private function writeFile(string $file, string $contents): void
    {
        $temporaryFile = $file.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (false === file_put_contents($temporaryFile, $contents)) {
            throw new \RuntimeException('File could not be written: '.$file);
        }

        if (!rename($temporaryFile, $file)) {
            @unlink($temporaryFile);

            throw new \RuntimeException('File could not be replaced: '.$file);
        }
    }

    private function renameFile(string $projectDir, string $source, string $target): void
    {
        if (!rename($projectDir.'/'.$source, $projectDir.'/'.$target)) {
            throw new \RuntimeException(sprintf('File could not be renamed from %s to %s.', $source, $target));
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if (!is_array($items)) {
            throw new \RuntimeException('Directory could not be read: '.$directory);
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            if (!unlink($path)) {
                throw new \RuntimeException('File could not be removed: '.$path);
            }
        }

        if (!rmdir($directory)) {
            throw new \RuntimeException('Directory could not be removed: '.$directory);
        }
    }
}
