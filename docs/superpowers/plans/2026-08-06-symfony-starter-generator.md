# Te4g Symfony Starter Generator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the existing runtime user bundle into `te4g/starter`, a one-shot Composer development tool that generates a complete application-owned Symfony account system and application shell.

**Architecture:** Keep the existing bundle working temporarily while a standalone `Te4g\Starter` executable, project inspector, Composer dependency promoter, and raw application resource catalog are built alongside it. Move the proven account workflows and UI into `resources/application/` under the fixed `App\` namespace, validate them in fresh skeleton and webapp fixtures, then delete the runtime bundle, Flex recipe, transactional installer, manifest, and migration generator.

**Tech Stack:** PHP 8.4+, Symfony 8.1, Symfony Console/Filesystem/Process/Yaml, Doctrine ORM 3.6.7, PHPUnit 12, PHPStan 2, Twig 3, Symfony Security/Form/Mailer/RateLimiter, SymfonyCasts VerifyEmailBundle and ResetPasswordBundle, AssetMapper, TailwindBundle, Twig Components, Turbo, Stimulus, UX Icons, and offline Lucide SVGs.

This plan supersedes `2026-08-03-user-bundle-implementation.md`.

## Global Constraints

- Composer package is exactly `te4g/starter`; executable is exactly `vendor/bin/te4g-starter`; the generator namespace is exactly `Te4g\Starter`.
- Support PHP 8.4 or newer and Symfony 8.1 fresh `symfony/skeleton` and fresh Symfony web applications.
- Generated PHP uses only the `App\` namespace; generated runtime files must contain no `Te4g` reference.
- Generated entities, repositories, controllers, forms, services, configuration, templates, assets, translations, tests, and migrations are application-owned.
- Runtime packages are explicit root requirements of the generated application; removing `te4g/starter` must not remove them.
- Temporarily pin `doctrine/orm` to exact version `3.6.7` so fresh migration generation does not resolve the incompatible ORM 3.6.8 and DBAL 4.4 combination.
- Use Twig Components, Turbo Drive, minimal Stimulus, TailwindBundle, AssetMapper, and offline Lucide icons; do not add Live Components, Encore, Vite, or a Node build.
- Reuse Symfony password hashing, form login, native login links, CSRF, Mailer, and RateLimiter plus SymfonyCasts verification/reset bundles; do not implement authentication or token cryptography.
- Installation never connects to a database, creates a database, generates a migration, or executes a migration.
- Installation is one-shot: no dry-run mode, upgrade protocol, generated-file hash manifest, managed-region merge, rerun repair, or transactional rollback.
- A preflight conflict prints its exact path and reason before generated files are copied; a subprocess failure preserves its command, exit code, stdout, and stderr.
- All implementation changes follow TDD, run the nearest focused test before each commit, and preserve unrelated working-tree changes.

---

## File and Responsibility Map

### Standalone package shell

- `bin/te4g-starter`: Composer binary proxy entrypoint.
- `src/Starter/Console/StarterApplication.php`: constructs the standalone Console application.
- `src/Starter/Console/InstallCommand.php`: maps CLI input/output to the installation pipeline.
- `src/Starter/Exception/*.php`: precise preflight, filesystem, dependency, and subprocess failures.

### Host inspection and dependencies

- `src/Starter/Project/ProjectLayout.php`: discovers and bounds host application paths.
- `src/Starter/Project/ProjectInspector.php`: validates PHP/Symfony/project shape and rejects existing account systems.
- `src/Starter/Project/Conflict.php`: one exact path/reason conflict.
- `src/Starter/Project/PreflightReport.php`: immutable compatible/conflict result.
- `src/Starter/Composer/RuntimeDependencySet.php`: authoritative runtime and development root requirements.
- `src/Starter/Composer/ComposerRunnerInterface.php`: dependency-command test seam.
- `src/Starter/Composer/ProcessComposerRunner.php`: executes Composer without a shell.
- `src/Starter/Composer/RuntimeDependencyInstaller.php`: promotes both dependency sets into the host root.

### One-shot generation

- `src/Starter/Generation/ResourceCatalog.php`: maps every raw file below `resources/application/` to the same host-relative path.
- `src/Starter/Generation/ReplacementPolicy.php`: permits only absent targets or byte-for-byte recognized fresh Symfony defaults.
- `src/Starter/Generation/GenerationPlan.php`: ordered creates/replacements or exact conflicts.
- `src/Starter/Generation/StaticResourceValidator.php`: validates staged PHP, YAML, JSON, Twig, and SVG resources.
- `src/Starter/Generation/StagedFileWriter.php`: stages, validates, and then copies application files without rollback or manifests.
- `resources/baselines/webapp/`: exact replaceable fresh Symfony/Flex defaults; absent bare-skeleton targets are ordinary creates.
- `resources/application/`: complete application-owned output using its final paths and `App\` namespace.

### Process pipeline

- `src/Starter/Process/ProcessResult.php`: command, exit code, stdout, and stderr value object.
- `src/Starter/Process/ProcessRunnerInterface.php`: validation-process seam.
- `src/Starter/Process/SymfonyProcessRunner.php`: bounded Symfony Process implementation.
- `src/Starter/Installation/StarterInstaller.php`: dependency install, import-map install, file generation, and validation orchestration.
- `src/Starter/Installation/ApplicationValidator.php`: exact post-copy Symfony/Tailwind/AssetMapper checks.

### Generated application

- `resources/application/src/Entity/` and `src/Repository/`: `User` and reset-request persistence.
- `resources/application/src/Account/`: registration, verification, login-link, password, profile, and deletion services.
- `resources/application/src/Controller/`: public, dashboard, authentication, and account controllers.
- `resources/application/src/Form/`: form types and input DTOs.
- `resources/application/src/Mailer/AccountMailer.php`: application mail composition.
- `resources/application/src/Security/MagicLinkAuthenticationSuccessHandler.php`: verifies the login-link destination through Symfony's native success-handler extension point.
- `resources/application/src/Security/VerifiedAccountSubscriber.php`: redirects authenticated unverified users away from all routes except verification, resend, login-link confirmation, and logout.
- `resources/application/config/packages/`: security, reset, rate-limit, Twig, and Tailwind configuration.
- `resources/application/templates/`: layouts, pages, account email, form theme, and Twig Components.
- `resources/application/assets/`: app stylesheet, minimal Stimulus controllers, and offline icons.
- `resources/application/translations/messages.en.xlf`: application-owned English copy.
- `resources/application/tests/Functional/`: generated positive and negative account workflow tests.

### Package verification and cleanup

- `tests/Starter/`: standalone generator unit, integration, and command tests.
- `tests/Fixtures/Starter/`: bare-skeleton, webapp, customized-security, and existing-account filesystem fixtures.
- `scripts/smoke-test.sh`: creates real skeleton/webapp projects, proves database-independent installation and package removal, then runs migrations and the generated test suite.
- `README.md`, `docs/index.md`, `docs/security.md`, `docs/customization.md`: one-shot starter documentation.
- `Makefile`: focused and complete generator quality gates.

The companion `symfony-template` Make target is deliberately handled in `2026-08-06-symfony-template-starter-integration.md` after this plan passes.

---

### Task 1: Add the Standalone Package Shell Without Breaking the Legacy Bundle

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `bin/te4g-starter`
- Create: `src/Starter/Console/StarterApplication.php`
- Create: `src/Starter/Console/InstallCommand.php`
- Create: `tests/Starter/Console/StarterApplicationTest.php`
- Modify: `phpunit.xml.dist`

**Interfaces:**
- Produces: `StarterApplication::create(): Symfony\Component\Console\Application`.
- Produces: command `install`, invoked by `vendor/bin/te4g-starter install`.
- Preserves: legacy `Te4g\UserBundle\` autoload and tests until Task 11 removes them.

- [ ] **Step 1: Write a failing standalone application test**

```php
public function testApplicationExposesInstallCommand(): void
{
    $application = StarterApplication::create();

    self::assertTrue($application->has('install'));
    self::assertSame('te4g:starter', $application->getName());
}
```

- [ ] **Step 2: Run the focused test and verify the missing class failure**

Run: `php vendor/bin/phpunit tests/Starter/Console/StarterApplicationTest.php`

Expected: FAIL because `Te4g\Starter\Console\StarterApplication` does not exist.

- [ ] **Step 3: Add the temporary dual-autoload package identity**

Change the relevant Composer keys to:

```json
{
    "name": "te4g/starter",
    "description": "One-shot Symfony application and user account starter",
    "type": "library",
    "bin": ["bin/te4g-starter"],
    "autoload": {
        "psr-4": {
            "Te4g\\Starter\\": "src/Starter/",
            "Te4g\\UserBundle\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Te4g\\Starter\\Tests\\": "tests/Starter/",
            "Te4g\\UserBundle\\Tests\\": "tests/"
        }
    }
}
```

Keep the existing dependency set temporarily so the legacy suite remains green during migration.

- [ ] **Step 4: Implement the executable and minimal command**

`bin/te4g-starter`:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

use Te4g\Starter\Console\StarterApplication;

require $_composer_autoload_path ?? dirname(__DIR__).'/vendor/autoload.php';

exit(StarterApplication::create()->run());
```

`StarterApplication::create()` must instantiate `Application('te4g:starter')`, add one `InstallCommand`, and expose it under the `install` name. The initial command returns success with `Starter installer pipeline is not configured yet.` so this task changes no host files.

- [ ] **Step 5: Refresh autoloading and prove both shells work**

Run: `composer update --lock --no-interaction`

Run: `php vendor/bin/phpunit tests/Starter/Console/StarterApplicationTest.php tests/Te4gUserBundleTest.php`

Run: `php bin/te4g-starter list`

Expected: both PHPUnit files PASS and the list contains `install`.

- [ ] **Step 6: Commit the standalone shell**

```bash
git add composer.json composer.lock phpunit.xml.dist bin/te4g-starter src/Starter/Console tests/Starter/Console
git commit -m "feat: add standalone starter command"
```

---

### Task 2: Model Fresh Projects and Exact Preflight Conflicts

**Files:**
- Create: `src/Starter/Project/ProjectLayout.php`
- Create: `src/Starter/Project/ProjectInspector.php`
- Create: `src/Starter/Project/Conflict.php`
- Create: `src/Starter/Project/PreflightReport.php`
- Create: `src/Starter/Exception/InvalidProject.php`
- Create: `tests/Starter/Project/ProjectLayoutTest.php`
- Create: `tests/Starter/Project/ProjectInspectorTest.php`
- Create: `tests/Fixtures/Starter/bare-skeleton/composer.json`
- Create: `tests/Fixtures/Starter/bare-skeleton/bin/console`
- Create: `tests/Fixtures/Starter/webapp/composer.json`
- Create: `tests/Fixtures/Starter/webapp/bin/console`
- Create: `tests/Fixtures/Starter/existing-account/composer.json`
- Create: `tests/Fixtures/Starter/existing-account/bin/console`
- Create: `tests/Fixtures/Starter/existing-account/src/Entity/User.php`
- Create: `tests/Fixtures/Starter/customized-security/composer.json`
- Create: `tests/Fixtures/Starter/customized-security/bin/console`
- Create: `tests/Fixtures/Starter/customized-security/config/packages/security.yaml`

**Interfaces:**
- Produces: `ProjectLayout::discover(string $workingDirectory): ProjectLayout`.
- Produces: `ProjectLayout::root(): string` and `ProjectLayout::path(string $relativePath): string` with traversal and symlink escape rejection.
- Produces: `ProjectInspector::inspect(ProjectLayout $layout): PreflightReport`.
- Produces: `Conflict::__construct(?string $path, string $reason)` and `Conflict::message(): string`.
- Produces: `PreflightReport::isCompatible(): bool`, `PreflightReport::conflicts(): list<Conflict>`, and `PreflightReport::throwIfIncompatible(): void`.

- [ ] **Step 1: Write failing discovery and traversal tests**

```php
public function testDiscoversProjectRootFromNestedDirectory(): void
{
    $layout = ProjectLayout::discover($this->fixture('bare-skeleton/bin'));

    self::assertSame($this->fixture('bare-skeleton'), $layout->root());
}

public function testRejectsPathTraversal(): void
{
    $this->expectException(InvalidProject::class);

    ProjectLayout::discover($this->fixture('bare-skeleton'))->path('../outside');
}
```

- [ ] **Step 2: Run the layout tests and verify they fail**

Run: `php vendor/bin/phpunit tests/Starter/Project/ProjectLayoutTest.php`

Expected: FAIL because `ProjectLayout` and `InvalidProject` are undefined.

- [ ] **Step 3: Implement bounded root discovery**

Walk upward until both `composer.json` and `bin/console` exist. Normalize separators, reject NUL bytes, absolute relative-path inputs, `..` segments, symlinked target parents escaping the root, and a search that reaches the filesystem root without a match.

```php
public function path(string $relativePath): string
{
    $normalized = $this->normalizeRelativePath($relativePath);
    $candidate = $this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized);

    if (!$this->isInsideRoot($candidate)) {
        throw InvalidProject::unsafePath($relativePath);
    }

    return $candidate;
}
```

- [ ] **Step 4: Write failing compatibility and conflict tests**

```php
public function testFreshSkeletonAndWebappAreCompatible(): void
{
    $inspector = new ProjectInspector('8.4.12', '8.1.2');

    self::assertTrue($inspector->inspect($this->layout('bare-skeleton'))->isCompatible());
    self::assertTrue($inspector->inspect($this->layout('webapp'))->isCompatible());
}

public function testExistingUserReportsItsExactPath(): void
{
    $report = (new ProjectInspector('8.4.12', '8.1.2'))->inspect($this->layout('existing-account'));

    self::assertSame(
        'src/Entity/User.php: an account system already exists',
        $report->conflicts()[0]->message(),
    );
}
```

- [ ] **Step 5: Implement project compatibility inspection**

Accept PHP `>=8.4.0` and Symfony `>=8.1.0,<8.2.0`. Parse `composer.json` with `JSON_THROW_ON_ERROR`; require `symfony/framework-bundle`; reject an existing `src/Entity/User.php`, `src/Repository/UserRepository.php`, account controllers, or a non-default security provider/firewall. Missing `security.yaml` is valid for a bare skeleton.

Return all conflicts in path-sorted order rather than stopping at the first one.

- [ ] **Step 6: Run the focused project tests**

Run: `php vendor/bin/phpunit tests/Starter/Project`

Expected: PASS for both supported baselines and exact negative cases.

- [ ] **Step 7: Commit project inspection**

```bash
git add src/Starter/Project src/Starter/Exception tests/Starter/Project tests/Fixtures/Starter
git commit -m "feat: validate fresh Symfony starter projects"
```

---

### Task 3: Promote Generated Runtime and Test Dependencies into the Host Root

**Files:**
- Create: `src/Starter/Composer/RuntimeDependencySet.php`
- Create: `src/Starter/Composer/ComposerRunnerInterface.php`
- Create: `src/Starter/Composer/ProcessComposerRunner.php`
- Create: `src/Starter/Composer/RuntimeDependencyInstaller.php`
- Create: `src/Starter/Process/ProcessResult.php`
- Create: `src/Starter/Exception/SubprocessFailed.php`
- Create: `tests/Starter/Composer/RuntimeDependencySetTest.php`
- Create: `tests/Starter/Composer/RuntimeDependencyInstallerTest.php`
- Create: `tests/Starter/Fixtures/RecordingComposerRunner.php`

**Interfaces:**
- Produces: `RuntimeDependencySet::runtime(): list<string>` and `RuntimeDependencySet::development(): list<string>`.
- Produces: `ComposerRunnerInterface::run(list<string> $arguments, string $cwd): ProcessResult`.
- Produces: `RuntimeDependencyInstaller::install(ProjectLayout $layout): void`.
- Produces: `ProcessResult::__construct(list<string> $command, int $exitCode, string $stdout, string $stderr)`, `ProcessResult::success(): ProcessResult`, and `isSuccessful(): bool`.

- [ ] **Step 1: Write the failing dependency authority test**

```php
public function testDependencySetsPinOrmAndKeepTestsInRequireDev(): void
{
    self::assertContains('doctrine/orm:3.6.7', RuntimeDependencySet::runtime());
    self::assertContains('symfony/ux-twig-component:^3.3', RuntimeDependencySet::runtime());
    self::assertContains('symfony/test-pack:^2.0', RuntimeDependencySet::development());
    self::assertNotContains('te4g/starter', RuntimeDependencySet::runtime());
}
```

- [ ] **Step 2: Run the dependency-set test and verify it fails**

Run: `php vendor/bin/phpunit tests/Starter/Composer/RuntimeDependencySetTest.php`

Expected: FAIL because `RuntimeDependencySet` is undefined.

- [ ] **Step 3: Implement the exact dependency sets**

The runtime list is:

```php
[
    'doctrine/doctrine-bundle:^3.3',
    'doctrine/doctrine-migrations-bundle:^3.4 || ^4.0',
    'doctrine/orm:3.6.7',
    'ext-mbstring:*',
    'symfony/asset:^8.1',
    'symfony/asset-mapper:^8.1',
    'symfony/clock:^8.1',
    'symfony/form:^8.1',
    'symfony/mailer:^8.1',
    'symfony/password-hasher:^8.1',
    'symfony/rate-limiter:^8.1',
    'symfony/security-bundle:^8.1',
    'symfony/security-csrf:^8.1',
    'symfony/stimulus-bundle:^3.3',
    'symfony/translation:^8.1',
    'symfony/twig-bundle:^8.1',
    'symfony/ux-icons:^3.3',
    'symfony/ux-turbo:^3.3',
    'symfony/ux-twig-component:^3.3',
    'symfony/validator:^8.1',
    'symfonycasts/reset-password-bundle:^1.25',
    'symfonycasts/tailwind-bundle:^1.0',
    'symfonycasts/verify-email-bundle:^1.18',
    'tales-from-a-dev/twig-tailwind-extra:^1.0',
    'twig/extra-bundle:^3.24',
    'twig/html-extra:^3.24',
]
```

The development list is:

```php
[
    'symfony/maker-bundle:^1.64',
    'symfony/test-pack:^2.0',
]
```

- [ ] **Step 4: Write failing orchestration and error-preservation tests**

```php
public function testInstallsRuntimeThenDevelopmentRequirements(): void
{
    $runner = new RecordingComposerRunner(ProcessResult::success());
    (new RuntimeDependencyInstaller($runner))->install($this->layout());

    self::assertSame('require', $runner->calls[0][0]);
    self::assertNotContains('--dev', $runner->calls[0]);
    self::assertContains('--dev', $runner->calls[1]);
}

public function testFailurePreservesCommandAndStderr(): void
{
    $runner = new RecordingComposerRunner(new ProcessResult(
        ['composer', 'require'],
        2,
        'resolution output',
        'dependency conflict',
    ));

    $this->expectExceptionMessage('dependency conflict');
    (new RuntimeDependencyInstaller($runner))->install($this->layout());
}
```

- [ ] **Step 5: Implement shell-free Composer execution**

`RuntimeDependencyInstaller` invokes runtime requirements first and development requirements second, each with `--no-interaction`, `--no-progress`, and `--with-all-dependencies`. `ProcessComposerRunner` uses `new Process(['composer', ...$arguments], $cwd)` with a 600-second timeout and returns both output streams unchanged.

Throw `SubprocessFailed` with the phase, exact command array, exit code, stdout, and stderr on the first nonzero result.

- [ ] **Step 6: Run the Composer-focused tests**

Run: `php vendor/bin/phpunit tests/Starter/Composer`

Expected: PASS, including the exact ORM pin and preserved error text.

- [ ] **Step 7: Commit dependency promotion**

```bash
git add src/Starter/Composer src/Starter/Process src/Starter/Exception/SubprocessFailed.php tests/Starter/Composer tests/Starter/Fixtures
git commit -m "feat: promote starter runtime dependencies"
```

---

### Task 4: Replace Managed Installation State with a Raw Resource Catalog

**Files:**
- Create: `src/Starter/Generation/ResourceCatalog.php`
- Create: `src/Starter/Generation/ReplacementPolicy.php`
- Create: `src/Starter/Generation/GenerationPlan.php`
- Create: `src/Starter/Generation/PlannedResource.php`
- Create: `src/Starter/Generation/StaticResourceValidator.php`
- Create: `src/Starter/Generation/StagedFileWriter.php`
- Create: `src/Starter/Exception/GenerationFailed.php`
- Create: `resources/baselines/webapp/assets/app.js`
- Create: `resources/baselines/webapp/assets/styles/app.css`
- Create: `resources/baselines/webapp/config/packages/security.yaml`
- Create: `resources/baselines/webapp/templates/base.html.twig`
- Create: `tests/Fixtures/Starter/resource-tree/src/Entity/User.php`
- Create: `tests/Starter/Generation/ResourceCatalogTest.php`
- Create: `tests/Starter/Generation/ReplacementPolicyTest.php`
- Create: `tests/Starter/Generation/StagedFileWriterTest.php`

**Interfaces:**
- Produces: `ResourceCatalog::files(): array<string,string>` mapping host-relative path to source absolute path.
- Produces: `ReplacementPolicy::plan(ProjectLayout $layout, array $resources): GenerationPlan`.
- Produces: `GenerationPlan::writes(): list<PlannedResource>`, `conflicts(): list<Conflict>`, and `throwIfConflicted(): void`.
- Produces: `StagedFileWriter::write(ProjectLayout $layout, GenerationPlan $plan): void`.

- [ ] **Step 1: Write failing raw-catalog tests**

```php
public function testCatalogMapsApplicationTreeWithoutTemplateSuffixes(): void
{
    $files = (new ResourceCatalog($this->fixture('resource-tree')))->files();

    self::assertSame(
        $this->fixture('resource-tree/src/Entity/User.php'),
        $files['src/Entity/User.php'],
    );
    self::assertArrayNotHasKey('src/Entity/User.php.tpl', $files);
}
```

- [ ] **Step 2: Run the generation tests and verify the missing-class failure**

Run: `php vendor/bin/phpunit tests/Starter/Generation/ResourceCatalogTest.php`

Expected: FAIL because `ResourceCatalog` does not exist.

- [ ] **Step 3: Implement deterministic resource discovery**

Recursively enumerate regular non-symlink files beneath `resources/application`, reject any escaped path, map them to the identical relative path, and return a path-sorted array. Do not perform token replacement; generated resources already contain their final `App\` namespace and values.

- [ ] **Step 4: Write failing create, baseline replacement, and conflict tests**

```php
public function testUnknownExistingFileIsAnExactConflict(): void
{
    $plan = $this->policy()->plan($this->customizedLayout(), [
        'config/packages/security.yaml' => $this->generatedSecurityPath(),
    ]);

    self::assertSame(
        'config/packages/security.yaml: existing content is not a supported fresh Symfony baseline',
        $plan->conflicts()[0]->message(),
    );
}
```

- [ ] **Step 5: Implement one-shot replacement policy**

An absent target becomes `PlannedResource::create()`. An existing target is replaceable only when its bytes equal one of the packaged baseline files for the same relative path. Directories, symlinks, unreadable files, and all unknown existing content become exact conflicts. If `src/Entity/User.php` exists, report the existing-account reason instead of an overwrite proposal.

- [ ] **Step 6: Write failing staged validation and no-rollback tests**

```php
public function testInvalidPhpNeverReachesTheApplication(): void
{
    $plan = $this->planForResource('src/Broken.php', '<?php syntax error');

    $this->expectException(GenerationFailed::class);
    $this->writer()->write($this->layout(), $plan);

    self::assertFileDoesNotExist($this->layout()->path('src/Broken.php'));
}

public function testCopiedFilesRemainWhenPostCopyWorkFailsElsewhere(): void
{
    $this->writer()->write($this->layout(), $this->validPlan());

    self::assertFileExists($this->layout()->path('src/Entity/User.php'));
}
```

- [ ] **Step 7: Implement staging and static validation**

Create a unique directory under `sys_get_temp_dir()`, copy planned sources into it, run `PHP_BINARY -l` on PHP, parse YAML and JSON, parse Twig with `Twig\Environment`, parse every SVG as XML, then copy to the host. Remove only the validated temporary directory in `finally`; never remove host files after copying.

- [ ] **Step 8: Run generation tests and commit**

Run: `php vendor/bin/phpunit tests/Starter/Generation`

Expected: PASS with no manifest, hash comparison, rerun merge, or rollback class involved.

```bash
git add src/Starter/Generation src/Starter/Exception/GenerationFailed.php resources/baselines tests/Starter/Generation tests/Fixtures/Starter
git commit -m "feat: add one-shot application file generation"
```

---

### Task 5: Generate Application-Owned Persistence and Security Configuration

**Files:**
- Create: `resources/application/src/Entity/User.php`
- Create: `resources/application/src/Entity/ResetPasswordRequest.php`
- Create: `resources/application/src/Repository/UserRepository.php`
- Create: `resources/application/src/Repository/ResetPasswordRequestRepository.php`
- Create: `resources/application/src/Account/EmailNormalizer.php`
- Create: `resources/application/src/Security/VerifiedAccountSubscriber.php`
- Create: `resources/application/config/packages/security.yaml`
- Create: `resources/application/config/packages/reset_password.yaml`
- Create: `resources/application/config/packages/verify_email.yaml`
- Create: `resources/application/config/packages/account_rate_limiters.yaml`
- Create: `resources/application/config/packages/account_twig.yaml`
- Create: `resources/application/config/packages/tailwind.yaml`
- Create: `resources/application/config/packages/test/doctrine.yaml`
- Create: `tests/Starter/Resources/PersistenceResourcesTest.php`

**Interfaces:**
- Produces: `User::setName(string): self`, `setEmail(string): self`, `setPendingEmail(?string): self`, `setPassword(string): self`, `setVerified(bool): self`.
- Produces: `UserRepository::findOneByNormalizedEmail(string): ?User`, `save(User, bool $flush = true): void`, and `remove(User, bool $flush = true): void`.
- Produces: `VerifiedAccountSubscriber` enforcing the unverified-account route allowlist after routing and authentication.
- Produces: firewall `main`, entity provider `app_user_provider`, form-login route `app_login`, login-link check route `app_login_link_check`, and authenticated access to `/dashboard` and `/account` guarded by the subscriber's verified-state check.

- [ ] **Step 1: Write failing resource contract tests**

```php
public function testUserResourceIsApplicationOwnedAndSubscriberChecksVerifiedState(): void
{
    $source = $this->resource('src/Entity/User.php');

    self::assertStringContainsString('namespace App\\Entity;', $source);
    self::assertStringContainsString("\$roles[] = 'ROLE_USER';", $source);
    self::assertStringNotContainsString('Te4g', $source);
    self::assertStringContainsString(
        'if (!$user instanceof User || $user->isVerified())',
        $this->resource('src/Security/VerifiedAccountSubscriber.php'),
    );
}

public function testSecurityUsesApplicationEntityProvider(): void
{
    $config = Yaml::parse($this->resource('config/packages/security.yaml'));

    self::assertSame('App\\Entity\\User', $config['security']['providers']['app_user_provider']['entity']['class']);
}
```

- [ ] **Step 2: Run the persistence resource tests and verify missing resources**

Run: `php vendor/bin/phpunit tests/Starter/Resources/PersistenceResourcesTest.php`

Expected: FAIL because the final application resources do not exist.

- [ ] **Step 3: Port the persistence resources to direct application ownership**

Start from the proven entity/repository skeletons in `resources/skeleton`, remove every bundle contract, make `User` implement Symfony `UserInterface` and `PasswordAuthenticatedUserInterface` directly, and add `name` plus direct repository methods. Roles remain conventional and verified state stays a separate account property:

```php
public function getRoles(): array
{
    $roles = $this->roles;
    $roles[] = 'ROLE_USER';

    return array_values(array_unique($roles));
}
```

Keep normalized email uniqueness in Doctrine metadata and Symfony validation. Keep plain passwords only in form DTOs.

- [ ] **Step 4: Add exact application security configuration**

Use this access model:

```yaml
security:
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: auto
    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: email
    firewalls:
        dev:
            pattern: ^/(_(profiler|wdt)|css|images|js)/
            security: false
        main:
            lazy: true
            provider: app_user_provider
            form_login:
                login_path: app_login
                check_path: app_login
                enable_csrf: true
                default_target_path: app_dashboard
            login_link:
                check_route: app_login_link_check
                signature_properties: ['id', 'email', 'password']
                lifetime: 600
                max_uses: 1
                check_post_only: true
                used_link_cache: cache.account_login_links
                success_handler: App\Security\MagicLinkAuthenticationSuccessHandler
            logout:
                path: app_logout
                target: app_home
                enable_csrf: true
    access_control:
        - { path: ^/dashboard, roles: ROLE_USER }
        - { path: ^/account, roles: ROLE_USER }
```

Configure `cache.account_login_links` plus named limiters for login, registration, magic link, password reset, verification resend, and email change. `VerifiedAccountSubscriber` permits only `app_check_email`, `app_verify_email`, `app_verify_resend`, `app_login_link_check`, and `app_logout` for an authenticated unverified user; every other route redirects to `app_check_email`. Set VerifyEmailBundle lifetime to 3600 seconds; set ResetPasswordBundle lifetime and throttle to 3600 seconds and alias its persistence interface to `App\Repository\ResetPasswordRequestRepository`. Configure test Doctrine to use `sqlite:///%kernel.project_dir%/var/test.db`.

- [ ] **Step 5: Validate resource syntax and inventory**

Run: `php vendor/bin/phpunit tests/Starter/Resources/PersistenceResourcesTest.php tests/Starter/Generation`

Expected: PASS and every PHP resource lints under PHP 8.4.

- [ ] **Step 6: Commit persistence and security resources**

```bash
git add resources/application/src/Entity resources/application/src/Repository resources/application/src/Account/EmailNormalizer.php resources/application/src/Security/VerifiedAccountSubscriber.php resources/application/config tests/Starter/Resources/PersistenceResourcesTest.php
git commit -m "feat: generate application-owned user persistence"
```

---

### Task 6: Generate Registration, Verification, Login-Link, and Password-Recovery Flows

**Files:**
- Create: `resources/application/src/Account/RegistrationService.php`
- Create: `resources/application/src/Account/EmailVerificationService.php`
- Create: `resources/application/src/Account/MagicLinkService.php`
- Create: `resources/application/src/Account/PasswordResetService.php`
- Create: `resources/application/src/Account/AccountRequestLimiter.php`
- Create: `resources/application/src/Mailer/AccountMailer.php`
- Create: `resources/application/src/Security/MagicLinkAuthenticationSuccessHandler.php`
- Create: `resources/application/src/Form/Model/RegistrationInput.php`
- Create: `resources/application/src/Form/Model/ResetPasswordInput.php`
- Create: `resources/application/src/Form/RegistrationType.php`
- Create: `resources/application/src/Form/ResetPasswordType.php`
- Create: `resources/application/src/Controller/RegistrationController.php`
- Create: `resources/application/src/Controller/VerificationController.php`
- Create: `resources/application/src/Controller/LoginController.php`
- Create: `resources/application/src/Controller/MagicLinkController.php`
- Create: `resources/application/src/Controller/PasswordResetController.php`
- Create: `tests/Starter/Resources/PublicAccountResourcesTest.php`

**Interfaces:**
- Produces: `RegistrationService::register(string $name, string $email, string $plainPassword): ?User`.
- Produces: `EmailVerificationService::send(User $user): void` and `verify(string $signedUri, int $userId): User`.
- Produces: `MagicLinkService::send(string $email): void` with neutral unknown-account behavior.
- Produces: `PasswordResetService::request(string $email): void` and `reset(string $token, string $plainPassword): void`.
- Produces: route names `app_register`, `app_check_email`, `app_verify_email`, `app_verify_resend`, `app_login`, `app_login_link_request`, `app_login_link_check`, `app_logout`, `app_forgot_password_request`, `app_forgot_password_check_email`, and `app_forgot_password_reset`.

- [ ] **Step 1: Write failing public-flow resource assertions**

```php
public function testRegistrationReturnsNewUserForControllerLogin(): void
{
    $source = $this->resource('src/Account/RegistrationService.php');

    self::assertStringContainsString('function register(string $name, string $email,', $source);
    self::assertStringContainsString(': ?User', $source);
    self::assertStringContainsString('UniqueConstraintViolationException', $source);
}

public function testMagicLinkSuccessHandlerVerifiesDestination(): void
{
    $source = $this->resource('src/Security/MagicLinkAuthenticationSuccessHandler.php');

    self::assertStringContainsString('AuthenticationSuccessHandlerInterface', $source);
    self::assertStringContainsString('setVerified(true)', $source);
}
```

- [ ] **Step 2: Run the public resource test and verify missing files**

Run: `php vendor/bin/phpunit tests/Starter/Resources/PublicAccountResourcesTest.php`

Expected: FAIL because the application-owned public workflow classes are absent.

- [ ] **Step 3: Port registration and verification with direct repositories**

Adapt the existing manager logic to `App\Repository\UserRepository` and return the newly persisted user:

```php
public function register(string $name, string $email, #[\SensitiveParameter] string $plainPassword): ?User
{
    $normalized = $this->normalizer->normalize($email);
    $existing = $this->users->findOneByNormalizedEmail($normalized);
    if ($existing instanceof User) {
        if (!$existing->isVerified()) {
            $this->verification->send($existing);
        }
        return null;
    }

    $user = (new User())->setName(trim($name))->setEmail($normalized);
    $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
    try {
        $this->users->save($user);
    } catch (UniqueConstraintViolationException) {
        return null;
    }
    $this->verification->send($user);

    return $user;
}
```

The controller calls Symfony `Security::login($user, 'form_login', 'main')` only when a new user is returned, then redirects to the verification-required page.

- [ ] **Step 4: Port neutral magic-link and reset workflows**

Unknown emails, throttled requests, and duplicate registrations all render the same check-email response. Use native `LoginLinkHandlerInterface`, VerifyEmailBundle, and ResetPasswordBundle; do not copy their signing or token algorithms.

The configured `MagicLinkAuthenticationSuccessHandler` must require the authenticated token user to be `App\Entity\User`, mark it verified only when needed, flush once, and return a redirect to `app_dashboard`. It does not authenticate the request itself; Symfony's native login-link authenticator remains the authority. Follow Symfony's documented `success_handler`, `check_post_only`, `max_uses`, and `used_link_cache` extension points: <https://symfony.com/doc/current/security/login_link.html>.

- [ ] **Step 5: Add forms, controllers, rate-limit calls, and mail composition**

Controllers use route attributes, Symfony forms, 422 responses for invalid submitted forms, generic success redirects for email-address submissions, and no Te4g configuration object. `AccountMailer` uses editable defaults:

```php
public function __construct(
    private MailerInterface $mailer,
    private string $fromEmail = 'no-reply@example.com',
    private string $fromName = 'Application',
) {
}
```

- [ ] **Step 6: Validate all public workflow resources**

Run: `php vendor/bin/phpunit tests/Starter/Resources/PublicAccountResourcesTest.php tests/Starter/Generation`

Expected: PASS; resource PHP lints and no source contains `Te4g\UserBundle`.

- [ ] **Step 7: Commit public account workflows**

```bash
git add resources/application/src/Account resources/application/src/Mailer resources/application/src/Security resources/application/src/Form resources/application/src/Controller tests/Starter/Resources/PublicAccountResourcesTest.php
git commit -m "feat: generate public account workflows"
```

---

### Task 7: Generate Profile, Password, Email-Change, and Deletion Workflows

**Files:**
- Create: `resources/application/src/Account/ProfileService.php`
- Create: `resources/application/src/Account/PasswordService.php`
- Create: `resources/application/src/Account/AccountDeletionService.php`
- Create: `resources/application/src/Form/Model/ProfileInput.php`
- Create: `resources/application/src/Form/Model/PasswordChangeInput.php`
- Create: `resources/application/src/Form/Model/DeleteAccountInput.php`
- Create: `resources/application/src/Form/ProfileType.php`
- Create: `resources/application/src/Form/PasswordChangeType.php`
- Create: `resources/application/src/Form/DeleteAccountType.php`
- Create: `resources/application/src/Controller/AccountController.php`
- Create: `resources/application/src/Controller/ProfileController.php`
- Create: `resources/application/src/Controller/PasswordController.php`
- Create: `resources/application/src/Controller/DeleteAccountController.php`
- Create: `tests/Starter/Resources/SelfAdministrationResourcesTest.php`

**Interfaces:**
- Produces: `ProfileService::updateName(User $user, string $name): void`.
- Produces: `ProfileService::requestEmailChange(User $user, string $newEmail): void` and `confirmEmailChange(string $signedUri, int $userId): User`.
- Produces: `PasswordService::change(User $user, string $currentPassword, string $newPassword): void`.
- Produces: `AccountDeletionService::delete(User $user, string $currentPassword): void`.
- Produces: route names `app_account`, `app_account_profile`, `app_account_password`, `app_account_email_verify`, and `app_account_delete`.

- [ ] **Step 1: Write failing state-transition resource tests**

```php
public function testEmailChangeKeepsActiveAddressUntilConfirmation(): void
{
    $source = $this->resource('src/Account/ProfileService.php');

    self::assertStringContainsString('setPendingEmail($normalized)', $source);
    self::assertStringContainsString('setEmail($pendingEmail)', $source);
    self::assertStringContainsString('setPendingEmail(null)', $source);
}

public function testDeletionRequiresPasswordAndInvalidatesSessionInController(): void
{
    self::assertStringContainsString('isPasswordValid', $this->resource('src/Account/AccountDeletionService.php'));
    self::assertStringContainsString('invalidate()', $this->resource('src/Controller/DeleteAccountController.php'));
}
```

- [ ] **Step 2: Run the self-administration test and verify missing resources**

Run: `php vendor/bin/phpunit tests/Starter/Resources/SelfAdministrationResourcesTest.php`

Expected: FAIL because the settings workflow resources are absent.

- [ ] **Step 3: Port direct application services**

Use the existing manager behavior but replace bundle contracts/events/options with direct `User`, `UserRepository`, and route names. Email-change confirmation must recheck uniqueness immediately before promotion and catch `UniqueConstraintViolationException` so a race cannot replace the active identifier.

Password change and reset remove outstanding reset requests. Deletion checks the current password, removes reset requests and the user in one Doctrine transaction, then lets the controller invalidate the session and redirect to `app_home`.

- [ ] **Step 4: Add DTO-backed forms and thin controllers**

Every plain password property is marked `#[\SensitiveParameter]` at service boundaries and exists only in form DTOs. Invalid current-password and duplicate-email failures attach a generic form error and return HTTP 422; they never include the submitted password or account identifier in logs or exception messages.

- [ ] **Step 5: Validate self-administration resources**

Run: `php vendor/bin/phpunit tests/Starter/Resources/SelfAdministrationResourcesTest.php tests/Starter/Generation`

Expected: PASS with direct application dependencies and no runtime extension interfaces.

- [ ] **Step 6: Commit account self-administration**

```bash
git add resources/application/src/Account resources/application/src/Form resources/application/src/Controller tests/Starter/Resources/SelfAdministrationResourcesTest.php
git commit -m "feat: generate account self-administration"
```

---

### Task 8: Generate the Application Shell and Restrained Twig UI Kit

**Files:**
- Create: `resources/application/src/Controller/HomeController.php`
- Create: `resources/application/src/Controller/DashboardController.php`
- Create: `resources/application/templates/base.html.twig`
- Create: `resources/application/templates/layout/public.html.twig`
- Create: `resources/application/templates/layout/auth.html.twig`
- Create: `resources/application/templates/layout/app.html.twig`
- Create: `resources/application/templates/home/index.html.twig`
- Create: `resources/application/templates/dashboard/index.html.twig`
- Create: `resources/application/templates/registration/register.html.twig`
- Create: `resources/application/templates/security/login.html.twig`
- Create: `resources/application/templates/security/check_email.html.twig`
- Create: `resources/application/templates/security/login_link_check.html.twig`
- Create: `resources/application/templates/password/request.html.twig`
- Create: `resources/application/templates/password/reset.html.twig`
- Create: `resources/application/templates/account/index.html.twig`
- Create: `resources/application/templates/account/profile.html.twig`
- Create: `resources/application/templates/account/password.html.twig`
- Create: `resources/application/templates/account/delete.html.twig`
- Create: `resources/application/templates/email/verify.html.twig`
- Create: `resources/application/templates/email/magic_link.html.twig`
- Create: `resources/application/templates/email/password_reset.html.twig`
- Create: `resources/application/templates/email/email_change.html.twig`
- Create: `resources/application/templates/components/Alert.html.twig`
- Create: `resources/application/templates/components/Alert/Action.html.twig`
- Create: `resources/application/templates/components/Alert/Description.html.twig`
- Create: `resources/application/templates/components/Alert/Title.html.twig`
- Create: `resources/application/templates/components/AlertDialog.html.twig`
- Create: `resources/application/templates/components/AlertDialog/Action.html.twig`
- Create: `resources/application/templates/components/AlertDialog/Cancel.html.twig`
- Create: `resources/application/templates/components/AlertDialog/Content.html.twig`
- Create: `resources/application/templates/components/AlertDialog/Description.html.twig`
- Create: `resources/application/templates/components/AlertDialog/Footer.html.twig`
- Create: `resources/application/templates/components/AlertDialog/Header.html.twig`
- Create: `resources/application/templates/components/AlertDialog/Media.html.twig`
- Create: `resources/application/templates/components/AlertDialog/Title.html.twig`
- Create: `resources/application/templates/components/AlertDialog/Trigger.html.twig`
- Create: `resources/application/templates/components/Button.html.twig`
- Create: `resources/application/templates/components/Card.html.twig`
- Create: `resources/application/templates/components/Card/Action.html.twig`
- Create: `resources/application/templates/components/Card/Content.html.twig`
- Create: `resources/application/templates/components/Card/Description.html.twig`
- Create: `resources/application/templates/components/Card/Footer.html.twig`
- Create: `resources/application/templates/components/Card/Header.html.twig`
- Create: `resources/application/templates/components/Card/Title.html.twig`
- Create: `resources/application/templates/components/Field.html.twig`
- Create: `resources/application/templates/components/Field/Content.html.twig`
- Create: `resources/application/templates/components/Field/Description.html.twig`
- Create: `resources/application/templates/components/Field/Error.html.twig`
- Create: `resources/application/templates/components/Field/Group.html.twig`
- Create: `resources/application/templates/components/Field/Label.html.twig`
- Create: `resources/application/templates/components/Field/Legend.html.twig`
- Create: `resources/application/templates/components/Field/Separator.html.twig`
- Create: `resources/application/templates/components/Field/Set.html.twig`
- Create: `resources/application/templates/components/Field/Title.html.twig`
- Create: `resources/application/templates/components/Input.html.twig`
- Create: `resources/application/templates/components/Label.html.twig`
- Create: `resources/application/templates/components/Separator.html.twig`
- Create: `resources/application/templates/components/Spinner.html.twig`
- Create: `resources/application/templates/form/account_theme.html.twig`
- Create: `resources/application/assets/app.js`
- Create: `resources/application/assets/styles/app.css`
- Create: `resources/application/assets/controllers/password_visibility_controller.js`
- Create: `resources/application/assets/controllers/alert_dialog_controller.js`
- Create: `resources/application/assets/icons/lucide/circle-check.svg`
- Create: `resources/application/assets/icons/lucide/eye-off.svg`
- Create: `resources/application/assets/icons/lucide/eye.svg`
- Create: `resources/application/assets/icons/lucide/key-round.svg`
- Create: `resources/application/assets/icons/lucide/mail.svg`
- Create: `resources/application/assets/icons/lucide/trash-2.svg`
- Create: `resources/application/assets/icons/lucide/triangle-alert.svg`
- Create: `resources/application/translations/messages.en.xlf`
- Modify: `THIRD_PARTY_NOTICES.md`
- Create: `tests/Starter/Resources/UserInterfaceResourcesTest.php`

**Interfaces:**
- Produces: public route `app_home` at `/` and verified route `app_dashboard` at `/dashboard`.
- Produces: public, authentication, and authenticated layouts with responsive navigation and flash rendering.
- Produces: Twig Components `Button`, `Card/*`, `Field/*`, `Input`, `Label`, `Alert/*`, `AlertDialog/*`, `Separator`, and `Spinner`.
- Produces: only `password-visibility` and `alert-dialog` custom Stimulus controllers.

- [ ] **Step 1: Write failing layout and asset inventory tests**

```php
public function testApplicationShellAndOnlyMinimalControllersAreGenerated(): void
{
    $files = (new ResourceCatalog($this->applicationResources()))->files();

    self::assertArrayHasKey('templates/layout/app.html.twig', $files);
    self::assertArrayHasKey('templates/dashboard/index.html.twig', $files);
    self::assertSame([
        'assets/controllers/alert_dialog_controller.js',
        'assets/controllers/password_visibility_controller.js',
    ], $this->customControllerPaths($files));
}
```

- [ ] **Step 2: Run the UI resource test and verify it fails**

Run: `php vendor/bin/phpunit tests/Starter/Resources/UserInterfaceResourcesTest.php`

Expected: FAIL because the final layouts and application shell are missing.

- [ ] **Step 3: Port existing shadcn resources into final application paths**

Reuse the existing audited component templates and Lucide SVGs, remove `bundles/Te4gUserBundle` paths and the `Te4gUserBundle` translation domain, and reference final `app_*` routes directly. Keep the component set limited to files used by generated pages.

`base.html.twig` loads `{{ importmap('app') }}` and the layouts provide public/authenticated navigation. The dashboard contains a heading, concise starter copy, and one empty-state card without product-specific metrics or demo data.

- [ ] **Step 4: Add Tailwind, form theme, and browser-only behavior**

Replace the recognized fresh `assets/styles/app.css` baseline with the Tailwind/shadcn token source, import `shadcn/dist/tailwind.css` and `tw-animate-css/dist/tw-animate.css`, and configure the application-owned form theme in `config/packages/account_twig.yaml`.

The two Stimulus controllers manipulate only local DOM state. They make no HTTP requests and do not duplicate Turbo form behavior.

- [ ] **Step 5: Verify Twig, translation, SVG, and third-party notice resources**

Run: `php vendor/bin/phpunit tests/Starter/Resources/UserInterfaceResourcesTest.php tests/Starter/Generation`

Expected: PASS; all Twig parses, XLIFF is valid XML, icons match the locked inventory, and copied upstream assets remain attributed.

- [ ] **Step 6: Commit the generated UI**

```bash
git add resources/application/src/Controller resources/application/templates resources/application/assets resources/application/translations resources/application/config/packages/account_twig.yaml THIRD_PARTY_NOTICES.md tests/Starter/Resources/UserInterfaceResourcesTest.php
git commit -m "feat: generate starter application shell"
```

---

### Task 9: Assemble the Linear Installer and Expose Complete Failures

**Files:**
- Modify: `src/Starter/Console/InstallCommand.php`
- Create: `src/Starter/Installation/StarterInstaller.php`
- Create: `src/Starter/Installation/ApplicationValidator.php`
- Create: `src/Starter/Process/ProcessRunnerInterface.php`
- Create: `src/Starter/Process/SymfonyProcessRunner.php`
- Create: `tests/Starter/Installation/StarterInstallerTest.php`
- Create: `tests/Starter/Installation/ApplicationValidatorTest.php`
- Create: `tests/Starter/Console/InstallCommandTest.php`
- Create: `tests/Starter/Fixtures/RecordingProcessRunner.php`
- Create: `tests/Starter/Fixtures/OperationTrace.php`

**Interfaces:**
- Produces: `StarterInstaller::install(ProjectLayout $layout, callable $progress): void`.
- Produces: `ApplicationValidator::validate(ProjectLayout $layout): void`.
- Produces: `ProcessRunnerInterface::run(list<string> $command, string $cwd, float $timeout): ProcessResult`.
- Produces: success output with migration and package-removal commands, without running either operation.

- [ ] **Step 1: Write a failing ordering test**

```php
public function testPipelineOrdersPreflightDependenciesGenerationAndValidation(): void
{
    $trace = new OperationTrace();
    $installer = $this->installer($trace);

    $installer->install($this->layout(), static function (string $phase) use ($trace): void {
        $trace->record($phase);
    });

    self::assertSame([
        'preflight',
        'dependencies',
        'importmap',
        'generation',
        'validation',
    ], $trace->operations());
}
```

- [ ] **Step 2: Run installer tests and verify the missing pipeline failure**

Run: `php vendor/bin/phpunit tests/Starter/Installation/StarterInstallerTest.php`

Expected: FAIL because `StarterInstaller` does not exist.

- [ ] **Step 3: Implement the exact pipeline**

`StarterInstaller` performs:

```php
$report = $this->inspector->inspect($layout);
$report->throwIfIncompatible();
$resources = $this->resources->files();
$this->replacementPolicy->plan($layout, $resources)->throwIfConflicted();
$this->dependencies->install($layout);
$this->runImportMapRequirements($layout);
$plan = $this->replacementPolicy->plan($layout, $resources);
$plan->throwIfConflicted();
$this->writer->write($layout, $plan);
$this->validator->validate($layout);
```

The first resource plan is conflict-only and runs before Composer changes the host. The second plan accepts only the pinned fresh Flex recipe outputs created by dependency installation and supplies the actual writes.

Import-map installation runs:

```php
['php', 'bin/console', 'importmap:require',
 'shadcn/dist/tailwind.css', 'tw-animate-css/dist/tw-animate.css',
 '--no-interaction']
```

No migration or database command is present in this class.

- [ ] **Step 4: Write failing validation-command and error-output tests**

```php
public function testValidationNeverRunsADatabaseOrMigrationCommand(): void
{
    $runner = new RecordingProcessRunner();
    (new ApplicationValidator($runner))->validate($this->layout());

    $commands = implode("\n", array_map(static fn (array $call): string => implode(' ', $call), $runner->commands));
    self::assertStringNotContainsString('doctrine:', $commands);
    self::assertStringNotContainsString('make:migration', $commands);
}

public function testCommandPrintsCompleteSubprocessFailure(): void
{
    $tester = $this->testerWithFailure(new ProcessResult(['php', 'bin/console', 'lint:twig'], 3, 'out', 'twig broke'));
    self::assertSame(Command::FAILURE, $tester->execute([]));
    self::assertStringContainsString('php bin/console lint:twig', $tester->getDisplay());
    self::assertStringContainsString('exit code 3', $tester->getDisplay());
    self::assertStringContainsString('twig broke', $tester->getDisplay());
}
```

- [ ] **Step 5: Implement post-copy validation**

Run these commands in order with exact failure capture:

```text
php bin/console lint:container --no-interaction
php bin/console lint:yaml config --no-interaction
php bin/console lint:twig templates --no-interaction
php bin/console tailwind:build --no-interaction
php bin/console debug:asset-map --no-interaction
```

Then verify each locked Lucide SVG exists. Do not catch and replace `SubprocessFailed`; the Console command formats its phase, command, exit code, stdout, and stderr.

- [ ] **Step 6: Implement concise CLI success and preflight output**

On success print the five completed phases followed by:

```text
Starter installed. The generated code now belongs to this application.

Next:
  php bin/console make:migration
  php bin/console doctrine:migrations:migrate
  composer remove te4g/starter --dev
```

On conflict print one line per `Conflict::message()` and return failure without a confirmation prompt or generic trailing error.

- [ ] **Step 7: Run command and installation tests**

Run: `php vendor/bin/phpunit tests/Starter/Installation tests/Starter/Console`

Expected: PASS with complete error evidence and no database operation.

- [ ] **Step 8: Commit the installer pipeline**

```bash
git add src/Starter/Console src/Starter/Installation src/Starter/Process tests/Starter/Installation tests/Starter/Console tests/Starter/Fixtures
git commit -m "feat: assemble one-shot starter installation"
```

---

### Task 10: Generate Functional Account Tests

**Files:**
- Create: `resources/application/tests/Functional/AccountWebTestCase.php`
- Create: `resources/application/tests/Functional/RegistrationVerificationTest.php`
- Create: `resources/application/tests/Functional/AuthenticationTest.php`
- Create: `resources/application/tests/Functional/PasswordResetTest.php`
- Create: `resources/application/tests/Functional/AccountSettingsTest.php`
- Create: `resources/application/tests/Functional/SecurityBoundaryTest.php`
- Create: `tests/Starter/Resources/GeneratedTestsResourcesTest.php`

**Interfaces:**
- Produces: generated test helper `createUser(string $email, bool $verified = true, string $password = 'password'): User`.
- Produces: generated tests that run after the smoke fixture applies its migration.
- Consumes: final `app_*` routes and services from Tasks 5-8.

- [ ] **Step 1: Write the failing generated-test inventory assertion**

```php
public function testEveryApprovedWorkflowHasAGeneratedFunctionalTest(): void
{
    $files = (new ResourceCatalog($this->applicationResources()))->files();

    foreach ([
        'RegistrationVerificationTest.php',
        'AuthenticationTest.php',
        'PasswordResetTest.php',
        'AccountSettingsTest.php',
        'SecurityBoundaryTest.php',
    ] as $name) {
        self::assertArrayHasKey('tests/Functional/'.$name, $files);
    }
}
```

- [ ] **Step 2: Run the inventory test and verify missing generated tests**

Run: `php vendor/bin/phpunit tests/Starter/Resources/GeneratedTestsResourcesTest.php`

Expected: FAIL listing the first missing functional test.

- [ ] **Step 3: Add registration, verification, and authentication tests**

Use `WebTestCase`, Doctrine cleanup between tests, and Symfony Mailer assertions. Include successful registration, duplicate normalized email, signed verification, unverified dashboard denial, password login/logout, neutral unknown magic-link request, valid magic-link verification, and invalid/expired link rejection.

Representative assertion:

```php
$client->request('POST', '/login-link', ['email' => 'missing@example.com']);
self::assertResponseRedirects('/check-email');
self::assertEmailCount(0);
```

- [ ] **Step 4: Add reset and settings tests**

Cover reset throttling/expiry/success, current-password rejection, password change, pending email without active-email mutation, uniqueness recheck at confirmation, name update, deletion, and invalidated session.

Representative pending-email assertion:

```php
self::assertSame('old@example.com', $user->getEmail());
self::assertSame('new@example.com', $user->getPendingEmail());
```

- [ ] **Step 5: Add security boundary tests**

Cover CSRF rejection, all configured rate limiters, neutral responses for email-address submission endpoints, and absence of submitted secrets in response bodies and application logs.

- [ ] **Step 6: Validate generated test syntax and commit**

Run: `php vendor/bin/phpunit tests/Starter/Resources/GeneratedTestsResourcesTest.php tests/Starter/Generation`

Expected: PASS; full functional execution begins in Task 11 after migration creation.

```bash
git add resources/application/tests tests/Starter/Resources/GeneratedTestsResourcesTest.php
git commit -m "test: generate account lifecycle coverage"
```

---

### Task 11: Prove Fresh Skeleton and Webapp Installation End to End

**Files:**
- Replace: `scripts/smoke-test.sh`
- Modify: `Makefile`
- Create: `tests/Starter/Smoke/SmokeScriptTest.php`

**Interfaces:**
- Produces: `./scripts/smoke-test.sh skeleton` and `./scripts/smoke-test.sh webapp`.
- Produces: `make smoke` running both variants.
- Consumes: local Composer path repository for `te4g/starter` and the real standalone executable.

- [ ] **Step 1: Write a failing smoke-script policy test**

```php
public function testSmokeScriptProvesDatabaseIndependenceAndPackageRemoval(): void
{
    $script = file_get_contents(__DIR__.'/../../../scripts/smoke-test.sh');

    self::assertStringContainsString('127.0.0.1:1', $script);
    self::assertStringContainsString('composer remove te4g/starter', $script);
    self::assertStringContainsString('make:migration', $script);
    self::assertLessThan(
        strpos($script, 'make:migration'),
        strpos($script, 'te4g-starter install'),
    );
}
```

- [ ] **Step 2: Run the smoke policy test and verify the old workflow failure**

Run: `php vendor/bin/phpunit tests/Starter/Smoke/SmokeScriptTest.php`

Expected: FAIL because the current script installs the runtime bundle and runs `te4g:user:install`.

- [ ] **Step 3: Rewrite the smoke fixture creation**

For `skeleton`, run `composer create-project 'symfony/skeleton:8.1.*'`. For `webapp`, run the same command followed by `composer require webapp`. Configure a local non-symlink path repository with package version `0.1.0`, then require `te4g/starter:0.1.0 --dev`.

Before generation export:

```bash
export DATABASE_URL='postgresql://user:password@127.0.0.1:1/unreachable?serverVersion=17'
export MAILER_DSN='null://null'
```

Run `vendor/bin/te4g-starter install --no-interaction` and assert success without any database file or connection attempt in output.

- [ ] **Step 4: Prove independent ownership before using a database**

Run `composer remove te4g/starter --dev --no-interaction --no-progress`, then assert:

```bash
! composer show te4g/starter >/dev/null 2>&1
! rg -n 'Te4g\\|te4g/starter|te4g_user' src config templates assets tests composer.json
php bin/console lint:container --no-interaction
php bin/console lint:yaml config --no-interaction
php bin/console lint:twig templates --no-interaction
php bin/console tailwind:build --no-interaction
php bin/console debug:asset-map --no-interaction
```

- [ ] **Step 5: Generate and apply a migration only after installation**

Switch `DATABASE_URL` to `sqlite:///%kernel.project_dir%/var/smoke.db`, run `php bin/console make:migration --no-interaction`, assert exactly one new migration contains both user and reset-request schema changes, then run `php bin/console doctrine:migrations:migrate --no-interaction` and `php bin/console doctrine:schema:validate`.

- [ ] **Step 6: Run the generated functional suite**

Run: `php bin/phpunit`

Expected: all generated positive and negative account tests PASS with the null mail transport.

- [ ] **Step 7: Wire both variants into the Makefile and run them**

```make
smoke: ## Install into fresh skeleton and webapp projects
	@./scripts/smoke-test.sh skeleton
	@./scripts/smoke-test.sh webapp
```

Run: `php vendor/bin/phpunit tests/Starter/Smoke/SmokeScriptTest.php`

Run: `make smoke`

Expected: both variants PASS. If dependency downloads are blocked by sandbox networking, rerun this exact gate with approved network access rather than weakening it.

- [ ] **Step 8: Commit the end-to-end smoke workflow**

```bash
git add scripts/smoke-test.sh Makefile tests/Starter/Smoke
git commit -m "test: smoke test independent starter generation"
```

---

### Task 12: Remove the Runtime Bundle and Publish the One-Shot Package Surface

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `phpunit.xml.dist`
- Modify: `phpstan.neon.dist`
- Modify: `Makefile`
- Replace: `README.md`
- Replace: `docs/index.md`
- Replace: `docs/security.md`
- Replace: `docs/customization.md`
- Delete: `src/Command/InstallCommand.php`
- Delete: `src/Configuration/`
- Delete: `src/Contract/`
- Delete: `src/Controller/`
- Delete: `src/DependencyInjection/`
- Delete: `src/Event/`
- Delete: `src/Exception/` except `src/Starter/Exception/`
- Delete: `src/Factory/`
- Delete: `src/Form/`
- Delete: `src/Installer/`
- Delete: `src/Mailer/`
- Delete: `src/Manager/`
- Delete: `src/Security/`
- Delete: `src/Util/`
- Delete: `src/Te4gUserBundle.php`
- Delete: `config/`
- Delete: `templates/`
- Delete: `translations/`
- Delete: `flex/`
- Delete: `recipes-contrib/`
- Delete: `resources/skeleton/`
- Delete: legacy `tests/DependencyInjection/`, `tests/Functional/`, `tests/Integration/`, `tests/Unit/`, `tests/Fixtures/App/`, `tests/Fixtures/Domain/`, `tests/Fixtures/Installer/`, `tests/Fixtures/Persistence/`, and `tests/Fixtures/Shadcn330BeforePatch/`
- Delete: `docs/configuration.md`
- Delete: `docs/recipes-contrib.md`
- Modify: `tools/ShadcnSnapshotSynchronizer.php`
- Create: `tests/Starter/Tools/ShadcnSnapshotSynchronizerTest.php`
- Create: `tests/Starter/Package/PackageSurfaceTest.php`

**Interfaces:**
- Produces: final package with only `Te4g\Starter\ => src/Starter/` production autoload.
- Produces: minimal package dependencies for the generator itself; generated runtime dependencies remain only in `RuntimeDependencySet`.
- Removes: every runtime bundle class, Flex recipe, installer manifest, migration generator, rollback transaction, and idempotent rerun path.

- [ ] **Step 1: Write a failing final package-surface test**

```php
public function testPackageHasNoRuntimeBundleSurface(): void
{
    $composer = json_decode(file_get_contents(__DIR__.'/../../../composer.json'), true, 512, JSON_THROW_ON_ERROR);

    self::assertSame('te4g/starter', $composer['name']);
    self::assertSame(['Te4g\\Starter\\' => 'src/Starter/'], $composer['autoload']['psr-4']);
    self::assertDirectoryDoesNotExist(__DIR__.'/../../../flex');
    self::assertFileDoesNotExist(__DIR__.'/../../../src/Te4gUserBundle.php');
}
```

- [ ] **Step 2: Run the surface test and verify legacy files keep it red**

Run: `php vendor/bin/phpunit tests/Starter/Package/PackageSurfaceTest.php`

Expected: FAIL on legacy autoload and runtime bundle files.

- [ ] **Step 3: Delete the legacy runtime and managed-installer implementation**

Remove only the paths listed in this task. Preserve `src/Starter`, `tests/Starter`, `resources/application`, `resources/baselines`, `resources/shadcn/manifest.json`, `tools/ShadcnSnapshotSynchronizer.php`, `THIRD_PARTY_NOTICES.md`, and historical design/plan documents.

- [ ] **Step 4: Reduce Composer to generator dependencies**

Final production requirements are:

```json
{
    "php": "^8.4",
    "ext-dom": "*",
    "symfony/console": "^8.1",
    "symfony/filesystem": "^8.1",
    "symfony/process": "^8.1",
    "symfony/yaml": "^8.1",
    "twig/twig": "^3.24"
}
```

Final development requirements keep PHPUnit 12, PHPStan 2, PHP-CS-Fixer, Symfony 8.1 test components needed by generator tests, and `symfony/ux-toolkit:^3.3` only for the maintainer snapshot synchronizer. Final development autoload maps `Te4g\Starter\Tests\` to `tests/Starter/` and `Te4g\Starter\Tools\` to `tools/`; update the synchronizer and its ported test to those namespaces. Remove `type: symfony-bundle`, all Flex metadata, and the legacy test namespace.

- [ ] **Step 5: Rewrite documentation around one-shot ownership**

README quick start must show:

```console
composer require te4g/starter --dev
vendor/bin/te4g-starter install
php bin/console make:migration
php bin/console doctrine:migrations:migrate
composer remove te4g/starter --dev
```

Document supported fresh baselines, generated features, no database access during installation, exact failure reporting, app ownership, production Mailer/router setup, UI customization, and the separate `symfony-template` integration plan. Remove bundle configuration and recipes-contrib instructions.

- [ ] **Step 6: Refresh the lock and run focused final checks**

Run: `composer update --with-all-dependencies --no-interaction`

Run: `composer validate --strict`

Run: `php vendor/bin/phpunit tests/Starter`

Run: `php vendor/bin/phpstan analyse --configuration phpstan.neon.dist --memory-limit 512M --debug`

Run: `php vendor/bin/php-cs-fixer check --diff --verbose`

Expected: all PASS and `rg -n 'Te4g\\UserBundle|te4g/user-bundle|te4g:user:install|InstallationManifest|DoctrineMigrationGenerator' --glob '!docs/superpowers/**' .` returns no matches.

- [ ] **Step 7: Run the full acceptance gate**

Run: `make test`

Run: `make smoke`

Expected: generator quality checks and both real fresh-project variants PASS after the starter package removes itself from each host.

- [ ] **Step 8: Commit the final one-shot package**

```bash
git add -A composer.json composer.lock phpunit.xml.dist phpstan.neon.dist Makefile README.md docs src config templates translations flex recipes-contrib resources tests tools THIRD_PARTY_NOTICES.md
git commit -m "refactor: replace user bundle with one-shot starter"
```

---

## Completion Gate

Before starting the companion template plan, verify:

```bash
git status --short
composer validate --strict
make test
make smoke
```

Expected: clean working tree; all commands PASS; both generated applications boot, migrate, build assets, pass functional tests, and contain no runtime Te4g reference after `composer remove te4g/starter --dev`.
