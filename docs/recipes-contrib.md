# Symfony recipes-contrib handoff

The repository keeps two deliberately related recipe layouts:

- `flex/`: the package-owned source used by the isolated smoke test;
- `recipes-contrib/te4g/user-bundle/0.1/`: the review-ready handoff layout for Symfony recipes-contrib.

The recipe registers `Te4g\UserBundle\Te4gUserBundle` in every environment and copies `config/packages/te4g_user.yaml`. The package-local source also exposes the `te4g-user` alias. Symfony's contrib checker prohibits aliases, so the handoff is generated from the source with that one key removed; its bundle/copy declarations and config bytes remain identical. The copied file has `enabled: false`; the explicit installer safely recognizes and upgrades only that exact bootstrap.

## Local validation

```bash
php vendor/bin/phpunit tests/Integration/FlexRecipeTest.php
TE4G_TAILWIND_BINARY=/absolute/path/to/tailwindcss make smoke
```

`FlexRecipeTest` validates the manifest schema, the single alias omission, and exact config parity. The smoke test serves the source as a local private Flex endpoint, installs a clean Symfony 8.1 application, verifies the recipe registration/bootstrap/lock entry, runs the installer twice, and leaves exactly one generated migration unexecuted. The official checker commands `lint:manifests --contrib` and `lint:yaml` pass locally. Pull-request-only checks (package existence, contribution metadata, generated endpoint, and clean project matrices) remain the external acceptance boundary and can run only after a separately authorized publication/PR.

To stage the recipe in a local checkout of `symfony/recipes-contrib` without publishing it:

```bash
mkdir -p te4g/user-bundle/0.1/config/packages
cp /path/to/user-bundle/recipes-contrib/te4g/user-bundle/0.1/manifest.json \
   te4g/user-bundle/0.1/manifest.json
cp /path/to/user-bundle/recipes-contrib/te4g/user-bundle/0.1/config/packages/te4g_user.yaml \
   te4g/user-bundle/0.1/config/packages/te4g_user.yaml
```

No external repository, branch, release, or pull request is created by this task.

## Proposed pull request

Title:

```text
[te4g/user-bundle] Add recipe for 0.1
```

Body:

```text
Registers Te4gUserBundle for all environments and installs a deliberately
disabled config/packages/te4g_user.yaml bootstrap.

The package's explicit te4g:user:install command recognizes only the exact
bootstrap bytes, generates application-owned persistence/config/UI files, and
creates one migration without executing it. Modified or lookalike files remain
conflicts.

Package-side validation covers manifest structure, exact handoff parity, clean
Flex installation, second-install idempotence, boot/routes/assets/icons/HTTP,
and the modified-managed-file refusal path.
```
