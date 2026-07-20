# TemplateBundle

Service-ready Symfony bundle template for quickly starting a reusable bundle with configuration, dependency injection, service wiring, and tests already in place.

## Start From The GitHub Template

Create a new repository from this repository using GitHub's template workflow first. The template repository contains rename tooling that turns the placeholder `Acme\Bundle\TemplateBundle` bundle into your real bundle namespace and package.

After GitHub creates your repository, clone it locally and run the first-run commands below.

## Requirements

- PHP 8.4
- Composer
- Symfony 7.x components

## Create A Bundle

Install dependencies:

```bash
composer install
```

Rename the template bundle:

```bash
make rename
```

When prompted for the bundle namespace, enter your final bundle class namespace, for example:

```text
Teag\Bundle\InvoiceBundle
```

Refresh Composer autoload metadata and run the full quality suite:

```bash
composer dump-autoload
make test
```

## What Rename Updates

`make rename` updates the generated project from the template placeholders to your bundle names:

- PHP namespaces and class names
- Composer package name
- Composer PSR-4 autoload mappings
- PHPUnit test namespaces
- README package references
- Bundle alias
- Bundle, extension, and bundle test file names

Generated bundles also remove the template-only configurator tests under `tests/Configurator/`. The rename tooling remains in `tools/Configurator/` for the template repository itself.

## Project Structure

- `src/` contains the bundle PHP code.
- `src/DependencyInjection/` contains configuration and extension classes.
- `src/Service/ExampleService.php` provides a minimal service wired through bundle configuration.
- `config/services.php` registers bundle services.
- `tests/` contains PHPUnit unit and integration tests.
- `tests/Fixtures/App/TestKernel.php` boots the bundle in a fixture Symfony kernel.
- `tools/Configurator/` contains template-only rename tooling.

## Add Bundle Behavior

Use the existing example flow as the path for real behavior:

1. Add or update config options in `src/DependencyInjection/Configuration.php`.
2. Map config values to extension parameters in `src/DependencyInjection/*Extension.php`.
3. Wire services in `config/services.php`.
4. Cover small behavior with unit tests in `tests/`.
5. Cover container and bundle boot behavior with fixture-kernel integration tests.
6. Remove or replace `src/Service/ExampleService.php` after real bundle behavior exists.

## Quality Commands

Run the full suite:

```bash
make test
```

Run individual checks:

```bash
make cs-check
make cs-fix
make stan
make phpunit
```

## Publish To Packagist

Publish the generated bundle from a public Git repository after `make rename` has set its final package name. Packagist package names cannot be changed after the first submission, so confirm the `name` field before publishing.

Packagist derives versions from Git tags. Remove the template's fixed `version` field and validate the package before the first release:

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

Sign in to [Packagist](https://packagist.org) with GitHub and submit the public repository at [packagist.org/packages/new](https://packagist.org/packages/new). Confirm that the Packagist GitHub application can access the repository; it adds the webhook that synchronizes later pushes and tags automatically.

For each subsequent release, run the quality gate, push a new semantic-version tag, and verify installation with `composer require vendor/package:^1.0` in a clean Symfony application. See the [Packagist publishing guide](https://packagist.org/about) for current platform details.

## Test In A Symfony Application

Use a separate Symfony skeleton to verify bundle registration, configuration, and services outside the template test fixture. Before publishing, install the local bundle through a Composer path repository:

```bash
composer create-project symfony/skeleton bundle-smoke-test
cd bundle-smoke-test
composer config repositories.local-bundle path ../path/to/your-bundle
composer require vendor/package:@dev
```

Enable the bundle in `config/bundles.php` when Symfony Flex has not installed a recipe:

```php
return [
    // ...
    Vendor\Bundle\YourBundle\YourBundle::class => ['all' => true],
];
```

Configure its alias in `config/packages/your_bundle.yaml`, then verify that Symfony boots it and resolves its configuration and services:

```yaml
your_bundle:
    message: 'Bundle integration test'
```

```bash
php bin/console cache:clear
php bin/console debug:config your_bundle
php bin/console debug:container 'Vendor\Bundle\YourBundle\Service\ExampleService'
php bin/console debug:container --parameter=your_bundle.message
```

After publishing, switch the smoke-test application to a release tag, then repeat the same console checks:

```bash
composer config --unset repositories.local-bundle
composer require vendor/package:^1.0
```

Replace `ExampleService` and the example setting with the bundle's real public behavior as it evolves.
