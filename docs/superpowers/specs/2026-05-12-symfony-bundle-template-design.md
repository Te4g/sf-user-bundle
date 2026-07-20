# Symfony Bundle Template Design

## Goal

Make this repository a modern, service-ready Symfony bundle template that is easy to generate from, easy to understand after generation, and easy to test locally and in CI.

The template is optimized first for GitHub template repository usage, while keeping the structure clean enough to support a Composer-based skeleton workflow later.

## Decisions

- The generated bundle targets PHP 8.4 and Symfony 7.x.
- The repository remains a valid working bundle before generation.
- New bundles start from a service-ready skeleton, not a web/controller skeleton.
- Testing includes fast bundle-level tests and a tiny Symfony fixture app for integration behavior.
- Generation uses a tested configurator, not broad ad hoc text replacement.

## Architecture

The template stays executable as `Acme\Bundle\TemplateBundle`. It should pass the same checks that generated bundles are expected to pass.

The generation workflow is handled by a small configurator library, with `scripts/rename.php` acting as a thin CLI entrypoint. The configurator validates input, computes derived names, and rewrites a known list of files deliberately. Unknown files are not rewritten implicitly.

This keeps the repository pleasant to work in before generation, while making the rename behavior testable and predictable.

## Template Contents

The generated bundle includes:

- `src/<BundleName>.php`: bundle entrypoint extending Symfony's bundle base class.
- `src/DependencyInjection/<ExtensionName>.php`: loads service definitions and bundle configuration.
- `src/DependencyInjection/Configuration.php`: defines one example configuration option with a useful default.
- `src/Service/ExampleService.php`: tiny service proving configuration flows into services.
- `config/services.php`: PHP service wiring.
- `tests/`: fast unit tests for pure PHP behavior.
- `tests/Fixtures/App`: tiny Symfony app/kernel fixture for integration tests that boot the bundle.

The example service should stay intentionally small. Its purpose is to demonstrate bundle configuration, service wiring, and testing, not to provide domain behavior.

## Generation Workflow

The primary workflow is:

1. Create a repository from the GitHub template.
2. Run `composer install`.
3. Run `make rename`.
4. Enter a namespace such as `Teag\Bundle\InvoiceBundle`.
5. The configurator updates known files.
6. Run `make test`.

The configurator updates:

- PHP namespaces and class names.
- Composer package name.
- Composer PSR-4 autoload and dev autoload.
- PHPUnit and test namespaces.
- README title and usage snippets.
- Bundle alias derived from the bundle class name, such as `invoice` for `InvoiceBundle`.

The configurator validates the namespace before writing anything. Invalid input fails with a clear error and leaves the repository unchanged.

## Testing Design

The template should make testing a normal part of creating a bundle.

Local commands:

- `make test`: complete local gate.
- `make cs-check`: PHP-CS-Fixer dry run.
- `make cs-fix`: PHP-CS-Fixer write mode.
- `make stan`: PHPStan using `phpstan.neon.dist`.
- `make phpunit`: PHPUnit using `phpunit.xml.dist`.

Test coverage includes:

- Pure unit tests for name derivation and namespace validation.
- Configurator tests against temporary copied fixtures.
- Bundle tests that boot a tiny Symfony fixture app.
- Service wiring tests proving configured values reach `ExampleService`.

The CI workflow should run `make test` so local checks and GitHub checks agree.

## Documentation

The README should stay short and operational:

- Create a repo from the template.
- Install dependencies.
- Run the rename command.
- Run tests.
- Add services and configuration.
- Remove or replace the example service once real bundle behavior exists.

Reference documentation can live under `docs/` if it grows beyond the README, but the first-run path should remain visible on the README page.

## Error Handling

The configurator should fail before writing files when:

- The namespace is empty.
- The namespace is not a valid PHP namespace.
- The namespace does not end in a class name ending with `Bundle`.
- The target bundle class name cannot be derived.
- A required known file is missing.
- Composer metadata cannot be parsed.

When a write fails after validation, the script should report which file failed. The implementation plan should consider whether file updates need a temporary-file-and-rename strategy to reduce partial writes.

## Boundaries

This design does not include:

- Controllers, routes, Twig templates, or frontend assets beyond empty placeholder directories.
- Symfony Flex recipe generation.
- Publishing to Packagist.
- A full Composer `create-project` workflow.
- Multi-Symfony-version matrix testing.

Those can be added later once the GitHub-template workflow is reliable.

## Success Criteria

- A generated bundle can be renamed from `Acme\Bundle\TemplateBundle` to a real namespace with one command.
- The repository remains valid and testable before it is renamed.
- Generated bundles have a working service/configuration example.
- Unit and integration tests demonstrate how to test bundle behavior.
- `make test` passes locally and in CI.
- The rename behavior is covered by tests and is not implemented as broad, unbounded string replacement.
