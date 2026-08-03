# Te4g User Bundle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `te4g/user-bundle`, a Symfony 8.1 bundle that installs a complete, application-owned user account system and keeps security-sensitive workflows reusable in the bundle.

**Architecture:** A disabled-by-default bundle bootstrap exposes a safe installer before any application `User` class exists. The installer generates application-owned Doctrine, security, Twig, Symfony UX Toolkit Shadcn, and asset files; enabling the bundle then activates contract-driven runtime managers and thin HTTP controllers. Native Symfony authentication, SymfonyCasts verification/reset bundles, and application repository adapters remain the authority for security and persistence.

**Tech Stack:** PHP 8.4+, Symfony 8.1, Doctrine ORM 3, PHPUnit 12, PHPStan 2, Twig 3, Symfony Form/Security/Mailer/RateLimiter, SymfonyCasts ResetPasswordBundle and VerifyEmailBundle, AssetMapper, TailwindBundle, Symfony UX 3.3, Turbo, Stimulus, UX Icons, Symfony UX Toolkit Shadcn recipes.

## Global Constraints

- Composer package is exactly `te4g/user-bundle`; PHP namespace is exactly `Te4g\UserBundle`; bundle class is exactly `Te4g\UserBundle\Te4gUserBundle`.
- Configuration alias is `te4g_user`; installation command is `te4g:user:install`; stable route names start with `te4g_user_`.
- Support PHP `^8.4` and Symfony `^8.1` only; CI exercises PHP 8.4 and 8.5.
- The application owns `App\Entity\User`, its repository, reset-request persistence, migrations, security configuration, templates, components, assets, and translations.
- The bundle owns no user table and provides no mapped superclass.
- Use Doctrine ORM, AssetMapper, TailwindBundle, and Symfony UX; do not add Encore, Vite, a Node build, Live Components, usernames, social login, passkeys, MFA, or admin user management.
- Reuse Symfony password hashing, form login, native login links, SymfonyCasts VerifyEmailBundle, and SymfonyCasts ResetPasswordBundle; do not implement authentication or token cryptography.
- Magic links last 600 seconds, use `max_uses: 1`, use `check_post_only: true`, and render the GET confirmation on the same route that the authenticator intercepts on POST.
- Verification and password-reset links last 3600 seconds; password-reset requests use a 3600-second throttle.
- Installer writes are staged, idempotent, manifest-backed, and rolled back on failure; there is no v1 `--force`; migrations are generated for review and never executed.
- UX Toolkit snapshot tooling must use `symfony/ux-toolkit:^3.3` or newer; versions before 3.2 are forbidden because of CVE-2026-55878.
- All implementation changes follow TDD, pass focused tests before each commit, and preserve unrelated working-tree changes.

---

## File and Responsibility Map

### Bundle bootstrap and configuration

- `src/Te4gUserBundle.php`: bundle entry point.
- `src/DependencyInjection/Configuration.php`: validates the `te4g_user` tree.
- `src/DependencyInjection/Te4gUserExtension.php`: always loads installer services and loads runtime services only when `enabled` is true.
- `src/Configuration/UserBundleOptions.php`: typed runtime view of processed configuration.
- `config/services.php`: shared defaults and configuration value object.
- `config/installer.php`: installer-only services that must boot before `App\Entity\User` exists.
- `config/runtime.php`: managers, controllers, forms, mailer, security, and application contract aliases.
- `config/routes.php`: stable bundle route names and paths.

### Contracts and runtime domain

- `src/Contract/AccountUserInterface.php`: minimum user behavior required by the bundle.
- `src/Contract/AccountRepositoryInterface.php`: storage adapter used by managers.
- `src/Contract/AccountUserFactoryInterface.php`: application-user construction boundary.
- `src/Contract/AccountMailerInterface.php`: account email delivery boundary.
- `src/Contract/AccountDeletionGuardInterface.php`: application deletion veto boundary.
- `src/Factory/ConfiguredAccountUserFactory.php`: default public-no-argument-constructor factory.
- `src/Util/EmailNormalizer.php`: one canonical email normalization rule.
- `src/Manager/*.php`: one class for registration, verification, magic links, password reset/change, profile changes, and deletion.
- `src/Event/*.php`: immutable lifecycle events.
- `src/Exception/*.php`: safe domain exceptions without secret values.
- `src/Security/AccountUserProvider.php`: normalized identifier loading and password-hash upgrades through application contracts.
- `src/Security/VerifiedAccountUserChecker.php`: blocks unverified authentication.
- `src/Mailer/AccountMailer.php`: Symfony Mailer implementation.

### HTTP delivery

- `src/Form/Model/*.php`: unmapped form input objects that never place plain passwords on the entity.
- `src/Form/*.php`: standard bundle form types.
- `src/Controller/*.php`: thin public and authenticated account controllers.
- `templates/*.html.twig`: unstyled-but-accessible fallback pages and email templates used before/without application overrides.
- `translations/Te4gUserBundle.en.xlf`: English fallback copy.

### Installer

- `src/Installer/ProjectLayout.php`: resolves and bounds every application path.
- `src/Installer/InstallationPlanner.php`: computes creates/managed updates/conflicts without writing.
- `src/Installer/InstallationPlan.php`: immutable ordered plan.
- `src/Installer/PlannedFile.php`: one target, expected previous hash, and new contents.
- `src/Installer/InstallationManifest.php`: installed version and generated-file hashes.
- `src/Installer/ManifestStore.php`: reads/writes `config/te4g_user.install.json`.
- `src/Installer/MarkerEditor.php`: edits only named generated regions.
- `src/Installer/StagedWriter.php`: validates, applies, and rolls back a plan.
- `src/Installer/PreflightChecker.php`: validates host version, bundles, firewall, routes, and target conflicts.
- `src/Installer/SkeletonRenderer.php`: renders controlled `__TOKEN__` replacements.
- `src/Installer/MigrationGeneratorInterface.php`: test seam for migration generation.
- `src/Installer/DoctrineMigrationGenerator.php`: invokes Doctrine migration diff without migration execution.
- `src/Command/InstallCommand.php`: CLI orchestration and summaries.
- `resources/skeleton/`: generated application PHP/config/Twig/assets/translations.
- `resources/shadcn/manifest.json`: pinned upstream recipe version, files, and hashes.
- `tools/ShadcnSnapshotSynchronizer.php`: maintainer-only, path-safe recipe snapshot updater.
- `THIRD_PARTY_NOTICES.md`: upstream source and license attribution.

### Tests and release assets

- `tests/Unit/`: pure contracts, managers, installer, forms, and policies.
- `tests/Integration/`: container, Doctrine, Mailer, routing, and persistence integration.
- `tests/Functional/`: complete HTTP account lifecycle.
- `tests/Fixtures/App/`: enabled and disabled fixture kernels plus test user persistence.
- `tests/Fixtures/Installer/`: fresh/conflicting application filesystem fixtures.
- `flex/manifest.json` and `flex/config/packages/te4g_user.yaml`: source for the recipes-contrib recipe.
- `.github/workflows/ci.yaml`: PHP 8.4/8.5 quality matrix.

The plan is sequential because lifecycle, installer, and UI all depend on the same contracts and bundle bootstrap. Tasks 4, 10, and 15 are explicit review checkpoints that leave a bootable, testable deliverable.

---

### Task 1: Convert the Template into the Te4g Package Baseline

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `Makefile`
- Modify: `phpunit.xml.dist`
- Modify: `phpstan.neon.dist`
- Create: `src/Te4gUserBundle.php`
- Create: `tests/Te4gUserBundleTest.php`
- Delete: `src/TemplateBundle.php`
- Delete: `src/Service/ExampleService.php`
- Delete: `scripts/rename.php`
- Delete: `tools/Configurator/BundleNames.php`
- Delete: `tools/Configurator/ProjectConfigurator.php`
- Delete: `tests/TemplateBundleTest.php`
- Delete: `tests/Configurator/BundleNamesTest.php`
- Delete: `tests/Configurator/ProjectConfiguratorTest.php`

**Interfaces:**
- Produces: `final class Te4g\UserBundle\Te4gUserBundle extends Symfony\Component\HttpKernel\Bundle\Bundle`.
- Produces: Composer autoload `Te4g\UserBundle\ => src/` and test autoload `Te4g\UserBundle\Tests\ => tests/`.

- [ ] **Step 1: Replace the bundle identity test first**

```php
<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Te4g\UserBundle\Te4gUserBundle;

final class Te4gUserBundleTest extends TestCase
{
    public function testBundleInstantiates(): void
    {
        self::assertInstanceOf(Bundle::class, new Te4gUserBundle());
    }
}
```

- [ ] **Step 2: Run the new test and verify the namespace/class failure**

Run: `php vendor/bin/phpunit tests/Te4gUserBundleTest.php`

Expected: FAIL because `Te4g\UserBundle\Te4gUserBundle` does not exist. If `vendor/` is absent, run `composer install` first and then repeat the test.

- [ ] **Step 3: Replace template package metadata and dependencies**

Set `composer.json` to package name `te4g/user-bundle`, description `Opinionated Symfony user account lifecycle bundle`, PHP `^8.4`, and these direct runtime families:

```json
{
    "require": {
        "php": "^8.4",
        "ext-mbstring": "*",
        "doctrine/doctrine-bundle": "^3.3",
        "doctrine/doctrine-migrations-bundle": "^3.4",
        "doctrine/orm": "^3.4.4",
        "psr/log": "^3.0",
        "symfony/asset-mapper": "^8.1",
        "symfony/cache": "^8.1",
        "symfony/config": "^8.1",
        "symfony/console": "^8.1",
        "symfony/dependency-injection": "^8.1",
        "symfony/event-dispatcher": "^8.1",
        "symfony/filesystem": "^8.1",
        "symfony/form": "^8.1",
        "symfony/framework-bundle": "^8.1",
        "symfony/http-foundation": "^8.1",
        "symfony/mailer": "^8.1",
        "symfony/mime": "^8.1",
        "symfony/password-hasher": "^8.1",
        "symfony/rate-limiter": "^8.1",
        "symfony/routing": "^8.1",
        "symfony/security-bundle": "^8.1",
        "symfony/security-csrf": "^8.1",
        "symfony/stimulus-bundle": "^3.3",
        "symfony/translation": "^8.1",
        "symfony/twig-bundle": "^8.1",
        "symfony/ux-icons": "^3.3",
        "symfony/ux-turbo": "^3.3",
        "symfony/ux-twig-component": "^3.3",
        "symfony/validator": "^8.1",
        "symfony/yaml": "^8.1",
        "symfony/process": "^8.1",
        "symfonycasts/reset-password-bundle": "^1.25",
        "symfonycasts/tailwind-bundle": "^1.0",
        "symfonycasts/verify-email-bundle": "^1.18",
        "tales-from-a-dev/twig-tailwind-extra": "^1.0",
        "twig/extra-bundle": "^3.24",
        "twig/html-extra": "^3.24"
    },
    "require-dev": {
        "friendsofphp/php-cs-fixer": "^3.95",
        "phpstan/phpstan": "^2.2",
        "phpunit/phpunit": "^12.5",
        "symfony/browser-kit": "^8.1",
        "symfony/css-selector": "^8.1",
        "symfony/dom-crawler": "^8.1",
        "symfony/stopwatch": "^8.1",
        "symfony/ux-toolkit": "^3.3"
    }
}
```

Keep `type: symfony-bundle`, `GPL-3.0-only`, and the existing author. Remove the fixed `version`. Remove the configurator autoload namespace.

- [ ] **Step 4: Add the real bundle class and remove template-only behavior**

```php
<?php

declare(strict_types=1);

namespace Te4g\UserBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class Te4gUserBundle extends Bundle
{
}
```

Remove the rename target and template-only files listed above. Remove the `rename` Make target and rename the PHPUnit suite to `Te4g User Bundle`.

- [ ] **Step 5: Resolve dependencies and run the baseline gate**

Run: `composer update --with-all-dependencies`

Run: `composer validate --strict`

Run: `make test`

Expected: all baseline tests pass under the new namespace and Composer reports a valid package.

- [ ] **Step 6: Commit the package conversion**

```bash
git add composer.json composer.lock Makefile phpunit.xml.dist phpstan.neon.dist src tests scripts tools
git commit -m "chore: initialize Te4g user bundle package"
```

---

### Task 2: Add Disabled Bootstrap and Typed Bundle Configuration

**Files:**
- Modify: `src/DependencyInjection/Configuration.php`
- Create: `src/DependencyInjection/Te4gUserExtension.php`
- Create: `src/Configuration/UserBundleOptions.php`
- Modify: `config/services.php`
- Create: `config/installer.php`
- Create: `config/runtime.php`
- Modify: `tests/DependencyInjection/ConfigurationTest.php`
- Modify: `tests/Fixtures/App/TestKernel.php`
- Modify: `tests/Integration/BundleKernelTest.php`

**Interfaces:**
- Produces: `UserBundleOptions::__construct(bool $enabled, string $userClass, string $accountRepository, string $fromEmail, string $fromName, array $redirects, array $templates, bool $deletionEnabled)`.
- Produces: extension alias `te4g_user`.
- Produces: installer services always load; `config/runtime.php` loads only when `enabled === true`.

- [ ] **Step 1: Write configuration tests for disabled defaults and exact overrides**

```php
public function testDefaultsKeepRuntimeDisabledUntilInstallation(): void
{
    $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

    self::assertFalse($config['enabled']);
    self::assertSame('App\\Entity\\User', $config['user_class']);
    self::assertSame('App\\Repository\\UserRepository', $config['account_repository']);
    self::assertSame('app_home', $config['redirects']['login_success']);
    self::assertTrue($config['features']['account_deletion']);
}

public function testEmptyUserClassIsRejected(): void
{
    $this->expectException(InvalidConfigurationException::class);

    (new Processor())->processConfiguration(new Configuration(), [[
        'enabled' => true,
        'user_class' => '',
    ]]);
}
```

- [ ] **Step 2: Run focused configuration tests**

Run: `php vendor/bin/phpunit tests/DependencyInjection/ConfigurationTest.php`

Expected: FAIL because the template `message` configuration still exists.

- [ ] **Step 3: Implement the `te4g_user` configuration tree**

Define exact defaults:

```php
[
    'enabled' => false,
    'user_class' => 'App\\Entity\\User',
    'account_repository' => 'App\\Repository\\UserRepository',
    'mailer' => [
        'from_email' => 'no-reply@example.com',
        'from_name' => 'Application',
    ],
    'redirects' => [
        'login_success' => 'app_home',
        'verified' => 'te4g_user_login',
        'account_updated' => 'te4g_user_account',
        'account_deleted' => 'app_home',
    ],
    'templates' => [
        'registration' => '@Te4gUser/registration/register.html.twig',
        'login' => '@Te4gUser/security/login.html.twig',
        'login_link_check' => '@Te4gUser/security/login_link_check.html.twig',
        'check_email' => '@Te4gUser/security/check_email.html.twig',
        'password_request' => '@Te4gUser/password/request.html.twig',
        'password_reset' => '@Te4gUser/password/reset.html.twig',
        'account' => '@Te4gUser/account/index.html.twig',
        'profile' => '@Te4gUser/account/profile.html.twig',
        'password_change' => '@Te4gUser/account/password.html.twig',
        'verification_email' => '@Te4gUser/email/verify.html.twig',
        'magic_link_email' => '@Te4gUser/email/magic_link.html.twig',
        'password_reset_email' => '@Te4gUser/email/password_reset.html.twig',
        'email_change_email' => '@Te4gUser/email/email_change.html.twig',
    ],
    'features' => ['account_deletion' => true],
]
```

Use `cannotBeEmpty()` for class/service/route/template/email strings and `scalarPrototype()` only where the allowed key set is intentionally open.

- [ ] **Step 4: Implement conditional service loading**

```php
public function load(array $configs, ContainerBuilder $container): void
{
    $config = $this->processConfiguration(new Configuration(), $configs);

    $container->setParameter('te4g_user.config', $config);
    $container->setParameter('te4g_user.redirects.login_success', $config['redirects']['login_success']);

    $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../../config'));
    $loader->load('services.php');
    $loader->load('installer.php');

    if ($config['enabled']) {
        $loader->load('runtime.php');
    }
}

public function getAlias(): string
{
    return 'te4g_user';
}
```

Construct `UserBundleOptions` in `config/services.php` from the processed parameter. Do not alias an `App\...` service while disabled.

- [ ] **Step 5: Prove the pre-install kernel boots without an application user**

Add an integration assertion that the disabled fixture kernel boots, exposes `UserBundleOptions`, and has no runtime manager service.

Run: `php vendor/bin/phpunit tests/DependencyInjection tests/Integration/BundleKernelTest.php`

Expected: PASS.

- [ ] **Step 6: Commit the boot boundary**

```bash
git add src/Configuration src/DependencyInjection config tests/DependencyInjection tests/Fixtures/App tests/Integration
git commit -m "feat: add install-safe bundle configuration"
```

---

### Task 3: Define Application Contracts and Pure Support Types

**Files:**
- Create: `src/Contract/AccountUserInterface.php`
- Create: `src/Contract/AccountRepositoryInterface.php`
- Create: `src/Contract/AccountUserFactoryInterface.php`
- Create: `src/Contract/AccountMailerInterface.php`
- Create: `src/Contract/AccountDeletionGuardInterface.php`
- Create: `src/Factory/ConfiguredAccountUserFactory.php`
- Create: `src/Util/EmailNormalizer.php`
- Create: `src/Exception/AccountDeletionRejected.php`
- Create: `src/Exception/InvalidAccountState.php`
- Create: `tests/Unit/Factory/ConfiguredAccountUserFactoryTest.php`
- Create: `tests/Unit/Util/EmailNormalizerTest.php`
- Create: `tests/Fixtures/Domain/TestAccountUser.php`

**Interfaces:**
- Produces: `AccountUserInterface::getId(): int|string|null`, `getEmail(): string`, `setEmail(string): void`, `getPendingEmail(): ?string`, `setPendingEmail(?string): void`, `setPassword(string): void`, `isVerified(): bool`, `setVerified(bool): void`, `setRoles(array): void`.
- Produces: `AccountRepositoryInterface::findOneByEmail(string): ?AccountUserInterface`, `findById(int|string): ?AccountUserInterface`, `save(AccountUserInterface): void`, `remove(AccountUserInterface): void`.
- Produces: `AccountUserFactoryInterface::create(string $normalizedEmail): AccountUserInterface`.
- Produces: `AccountMailerInterface::sendVerification(AccountUserInterface, string $absoluteUrl): void`, `sendMagicLink(AccountUserInterface, string $absoluteUrl): void`, `sendPasswordReset(AccountUserInterface, string $absoluteUrl): void`, and `sendEmailChange(AccountUserInterface, string $absoluteUrl): void`.
- Produces: `AccountDeletionGuardInterface::assertDeletionAllowed(AccountUserInterface): void`.

- [ ] **Step 1: Write normalization and factory tests**

```php
#[DataProvider('emails')]
public function testEmailNormalization(string $input, string $expected): void
{
    self::assertSame($expected, (new EmailNormalizer())->normalize($input));
}

public static function emails(): iterable
{
    yield [' User@Example.COM ', 'user@example.com'];
    yield ["\tTEST@example.com\n", 'test@example.com'];
}

public function testFactoryCreatesConfiguredContractImplementation(): void
{
    $factory = new ConfiguredAccountUserFactory(TestAccountUser::class);

    $user = $factory->create('user@example.com');

    self::assertSame('user@example.com', $user->getEmail());
}
```

- [ ] **Step 2: Run the focused tests and verify missing-type failures**

Run: `php vendor/bin/phpunit tests/Unit/Util tests/Unit/Factory`

Expected: FAIL because the contracts, normalizer, and factory do not exist.

- [ ] **Step 3: Implement the contracts with exact method types**

```php
interface AccountUserInterface extends UserInterface, PasswordAuthenticatedUserInterface
{
    public function getId(): int|string|null;
    public function getEmail(): string;
    public function setEmail(string $email): void;
    public function getPendingEmail(): ?string;
    public function setPendingEmail(?string $email): void;
    public function setPassword(string $password): void;
    public function isVerified(): bool;
    public function setVerified(bool $verified): void;
    /** @param list<string> $roles */
    public function setRoles(array $roles): void;
}
```

Use the signatures in the Interfaces block for all other contracts. Every mailer URL parameter is already absolute; the mailer never generates or parses a security token.

- [ ] **Step 4: Implement safe normalization and configured construction**

`EmailNormalizer::normalize()` returns `mb_strtolower(trim($email))`. `ConfiguredAccountUserFactory` rejects a missing class, a class without a public no-argument constructor, or an object not implementing `AccountUserInterface` by throwing `InvalidAccountState` without including secrets.

- [ ] **Step 5: Run unit tests and static analysis**

Run: `php vendor/bin/phpunit tests/Unit/Util tests/Unit/Factory`

Run: `php vendor/bin/phpstan analyse src/Contract src/Factory src/Util tests/Fixtures/Domain --configuration phpstan.neon.dist`

Expected: PASS.

- [ ] **Step 6: Commit the application boundary**

```bash
git add src/Contract src/Factory src/Util src/Exception tests/Unit tests/Fixtures/Domain
git commit -m "feat: define account application contracts"
```

---

### Task 4: Build Enabled Runtime Fixture Wiring

**Files:**
- Modify: `config/runtime.php`
- Modify: `tests/Fixtures/App/TestKernel.php`
- Create: `tests/Fixtures/App/WebTestKernel.php`
- Create: `tests/Fixtures/App/bin/console`
- Create: `tests/Fixtures/Domain/InMemoryAccountRepository.php`
- Create: `tests/Fixtures/Domain/RecordingAccountMailer.php`
- Create: `tests/Fixtures/Domain/FakeAccountUserFactory.php`
- Create: `tests/Fixtures/Persistence/TestUser.php`
- Create: `tests/Fixtures/Persistence/TestUserRepository.php`
- Create: `tests/Functional/BundleWebTestCase.php`
- Create: `tests/Integration/RuntimeWiringTest.php`

**Interfaces:**
- Consumes: all Task 3 contracts.
- Produces: enabled fixture aliases for repository, factory, and mailer contracts without `App\...` production classes.
- Produces: a bootable runtime container suitable for manager tests.
- Produces: a `WebTestKernel` with Framework, Security, Twig, Doctrine, Mailer, Validator, Form, Turbo, TwigComponent, and this bundle, backed by temporary SQLite.

- [ ] **Step 1: Write an enabled-container wiring test**

```php
public function testEnabledRuntimeUsesApplicationAdapters(): void
{
    $kernel = TestKernel::enabled();
    $kernel->boot();

    try {
        $container = $kernel->getContainer();
        self::assertInstanceOf(
            InMemoryAccountRepository::class,
            $container->get('test.'.AccountRepositoryInterface::class),
        );
        self::assertInstanceOf(
            FakeAccountUserFactory::class,
            $container->get('test.'.AccountUserFactoryInterface::class),
        );
    } finally {
        $kernel->shutdown();
    }
}
```

- [ ] **Step 2: Run the test and verify unresolved runtime aliases**

Run: `php vendor/bin/phpunit tests/Integration/RuntimeWiringTest.php`

Expected: FAIL because runtime contract aliases and fixture adapters are absent.

- [ ] **Step 3: Implement deterministic in-memory adapters**

`InMemoryAccountRepository` stores users by normalized email and string-cast ID, records save/remove calls, and can be configured to throw on save/remove. `RecordingAccountMailer` stores typed messages without sending. `FakeAccountUserFactory` assigns incrementing integer IDs to `TestAccountUser` instances.

- [ ] **Step 4: Wire runtime defaults and test overrides**

In `config/runtime.php`, alias `AccountRepositoryInterface` to the configured service ID, register `ConfiguredAccountUserFactory` with the configured class, and alias it to `AccountUserFactoryInterface`. Register the concrete `AccountMailer` later but permit the fixture to replace `AccountMailerInterface` now.

In `TestKernel::enabled()`, load `te4g_user.enabled: true` and explicit fixture service IDs, then publish `test.<contract>` aliases.

`WebTestKernel` uses `MicroKernelTrait`, a file-backed SQLite database under its unique temporary directory, Doctrine attribute mapping for `tests/Fixtures/Persistence`, and a real `TestUserRepository`. `BundleWebTestCase::setUp()` creates the schema with Doctrine `SchemaTool`; `tearDown()` shuts down the kernel and removes only that kernel's temporary database/cache directory. Set `KERNEL_CLASS=Te4g\UserBundle\Tests\Fixtures\App\WebTestKernel` in `phpunit.xml.dist`. The fixture console script boots the same kernel so Twig, YAML, route, and container lint commands in later tasks are executable.

- [ ] **Step 5: Run the checkpoint gate**

Run: `php vendor/bin/phpunit tests/Integration/RuntimeWiringTest.php tests/DependencyInjection`

Run: `make stan`

Expected: PASS; both disabled and enabled kernels boot.

- [ ] **Step 6: Commit the runtime fixture**

```bash
git add config/runtime.php tests/Fixtures tests/Integration/RuntimeWiringTest.php
git commit -m "test: add enabled account runtime fixture"
```

---

### Task 5: Implement Registration and Anonymous Email Verification

**Files:**
- Create: `src/Manager/RegistrationManager.php`
- Create: `src/Manager/EmailVerificationManager.php`
- Create: `src/Event/AccountRegisteredEvent.php`
- Create: `src/Event/AccountVerifiedEvent.php`
- Create: `src/Exception/VerificationFailed.php`
- Modify: `config/runtime.php`
- Create: `tests/Unit/Manager/RegistrationManagerTest.php`
- Create: `tests/Unit/Manager/EmailVerificationManagerTest.php`

**Interfaces:**
- Produces: `RegistrationManager::register(string $email, string $plainPassword): void`.
- Produces: `EmailVerificationManager::send(AccountUserInterface $user): void`.
- Produces: `EmailVerificationManager::verify(string $signedUri, int|string $accountId): AccountUserInterface`.
- Consumes: `UserPasswordHasherInterface`, `VerifyEmailHelperInterface`, repository/factory/mailer contracts, `EventDispatcherInterface`.

- [ ] **Step 1: Write failing registration behavior tests**

```php
public function testRegisterPersistsHashedUnverifiedUserAndSendsVerification(): void
{
    $manager = $this->manager();

    $manager->register(' New@Example.com ', 'correct horse battery staple');

    $user = $this->repository->findOneByEmail('new@example.com');
    self::assertNotNull($user);
    self::assertSame('HASHED:correct horse battery staple', $user->getPassword());
    self::assertFalse($user->isVerified());
    self::assertCount(1, $this->mailer->verificationMessages);
    self::assertCount(1, $this->events->eventsOf(AccountRegisteredEvent::class));
}

public function testExistingAddressKeepsNeutralControlFlow(): void
{
    $this->repository->save(TestAccountUser::verified('user@example.com'));

    $this->manager()->register('user@example.com', 'unused password');

    self::assertCount(1, $this->repository->all());
}
```

- [ ] **Step 2: Run manager tests and verify class-not-found failures**

Run: `php vendor/bin/phpunit tests/Unit/Manager/RegistrationManagerTest.php tests/Unit/Manager/EmailVerificationManagerTest.php`

Expected: FAIL because both managers and events are absent.

- [ ] **Step 3: Implement registration with neutral duplicate handling**

Normalize before lookup. For a verified existing account, return without mutation. For an unverified existing account, resend verification and return. For a new account, create via the factory, hash through `UserPasswordHasherInterface`, save, dispatch `AccountRegisteredEvent`, and send verification. Translate a Doctrine `UniqueConstraintViolationException` into the same neutral return path.

- [ ] **Step 4: Implement verification through SymfonyCasts**

```php
$components = $this->verifyEmailHelper->generateSignature(
    'te4g_user_verify_email',
    (string) $user->getId(),
    $user->getEmail(),
    ['id' => (string) $user->getId()],
);
```

On verification, load by ID, call `validateEmailConfirmation($signedUri, (string) $id, $user->getEmail())`, set verified, save, and dispatch `AccountVerifiedEvent`. Wrap helper exceptions in `VerificationFailed` without copying the signed URL into the message.

- [ ] **Step 5: Run manager tests and all contract tests**

Run: `php vendor/bin/phpunit tests/Unit/Manager tests/Unit/Factory tests/Unit/Util`

Expected: PASS for new, duplicate, resend, invalid link, missing account, and successful verification cases.

- [ ] **Step 6: Commit registration and verification**

```bash
git add src/Manager src/Event src/Exception config/runtime.php tests/Unit/Manager
git commit -m "feat: add registration and email verification workflows"
```

---

### Task 6: Implement Verified Authentication and Magic Links

**Files:**
- Create: `src/Security/AccountUserProvider.php`
- Create: `src/Security/VerifiedAccountUserChecker.php`
- Create: `src/Manager/MagicLinkManager.php`
- Create: `src/Exception/UnverifiedAccount.php`
- Modify: `config/runtime.php`
- Create: `tests/Unit/Security/AccountUserProviderTest.php`
- Create: `tests/Unit/Security/VerifiedAccountUserCheckerTest.php`
- Create: `tests/Unit/Manager/MagicLinkManagerTest.php`

**Interfaces:**
- Produces: `MagicLinkManager::request(string $email): void`.
- Produces: `AccountUserProvider implements UserProviderInterface, PasswordUpgraderInterface`.
- Produces: `VerifiedAccountUserChecker implements UserCheckerInterface`.
- Consumes: `Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface` and Task 5 verification behavior.

- [ ] **Step 1: Write tests for verified, unverified, and unknown requests**

```php
#[DataProvider('accountStates')]
public function testMagicRequestNeverReturnsAccountExistence(
    ?TestAccountUser $user,
    int $expectedMagicMails,
    int $expectedVerificationMails,
): void {
    if (null !== $user) {
        $this->repository->save($user);
    }

    $this->manager()->request('user@example.com');

    self::assertCount($expectedMagicMails, $this->mailer->magicLinkMessages);
    self::assertCount($expectedVerificationMails, $this->mailer->verificationMessages);
}
```

Data cases are unknown `(null, 0, 0)`, unverified `(user, 0, 1)`, and verified `(user, 1, 0)`.

Add provider tests proving `loadUserByIdentifier(' User@Example.COM ')` calls the repository with `user@example.com`, refresh uses the non-null account ID, unsupported users/classes are rejected, missing accounts produce a generic `UserNotFoundException`, and password upgrades set/save only the new hash.

- [ ] **Step 2: Run focused tests**

Run: `php vendor/bin/phpunit tests/Unit/Security tests/Unit/Manager/MagicLinkManagerTest.php`

Expected: FAIL because the checker and manager do not exist.

- [ ] **Step 3: Implement the normalized account user provider**

Normalize identifiers before `findOneByEmail()`. Refresh only `AccountUserInterface` instances with a non-null ID through `findById()`. `supportsClass()` accepts the configured user class and its subclasses that implement the account contract. `upgradePassword()` sets the supplied hash and saves through the repository. User-not-found exceptions expose the normalized identifier only through Symfony's standard private exception property, never through logs or public copy.

- [ ] **Step 4: Implement the verified-account checker**

`checkPreAuth()` returns for non-bundle users, throws a generic `CustomUserMessageAccountStatusException` translation key for an unverified bundle user, and exposes no email. `checkPostAuth()` is a no-op.

- [ ] **Step 5: Implement native magic-link creation**

Normalize and look up the account. Unknown returns. Unverified calls `EmailVerificationManager::send()`. Verified calls `LoginLinkHandlerInterface::createLoginLink($user)`, reads `getUrl()`, and passes it to `AccountMailerInterface::sendMagicLink()`.

Do not add token storage or signing code.

- [ ] **Step 6: Run focused and static checks**

Run: `php vendor/bin/phpunit tests/Unit/Security tests/Unit/Manager/MagicLinkManagerTest.php`

Run: `make stan`

Expected: PASS.

- [ ] **Step 7: Commit authentication policy**

```bash
git add src/Security src/Manager/MagicLinkManager.php src/Exception/UnverifiedAccount.php config/runtime.php tests/Unit
git commit -m "feat: add verified authentication and magic links"
```

---

### Task 7: Implement Forgotten-Password Reset and Authenticated Password Change

**Files:**
- Create: `src/Manager/PasswordResetManager.php`
- Create: `src/Manager/PasswordManager.php`
- Create: `src/Event/PasswordChangedEvent.php`
- Create: `src/Event/PasswordChangeOrigin.php`
- Create: `src/Exception/CurrentPasswordInvalid.php`
- Create: `src/Exception/PasswordResetFailed.php`
- Modify: `config/runtime.php`
- Create: `tests/Unit/Manager/PasswordResetManagerTest.php`
- Create: `tests/Unit/Manager/PasswordManagerTest.php`

**Interfaces:**
- Produces: `PasswordResetManager::request(string $email): void`.
- Produces: `PasswordResetManager::reset(string $token, string $plainPassword): AccountUserInterface`.
- Produces: `PasswordManager::change(AccountUserInterface $user, string $currentPassword, string $newPassword): void`.
- Consumes: `ResetPasswordHelperInterface`, `ResetPasswordRequestRepositoryInterface`, `UserPasswordHasherInterface`, and `UrlGeneratorInterface`.

- [ ] **Step 1: Write tests for neutral reset request and credential invalidation**

```php
public function testUnknownResetRequestDoesNotSendAndDoesNotThrow(): void
{
    $this->manager()->request('missing@example.com');

    self::assertSame([], $this->mailer->passwordResetMessages);
}

public function testSuccessfulResetHashesPasswordRemovesRequestAndDispatchesEvent(): void
{
    $user = TestAccountUser::verified('user@example.com');
    $this->resetHelper->willResolve('valid-token', $user);

    $resolved = $this->manager()->reset('valid-token', 'new secure password');

    self::assertSame($user, $resolved);
    self::assertSame('HASHED:new secure password', $user->getPassword());
    self::assertSame(['valid-token'], $this->resetHelper->removedTokens);
}
```

Add password-change tests for wrong current password, successful change, reset-request cleanup, and `PasswordChangeOrigin::Authenticated`.

- [ ] **Step 2: Run focused tests**

Run: `php vendor/bin/phpunit tests/Unit/Manager/PasswordResetManagerTest.php tests/Unit/Manager/PasswordManagerTest.php`

Expected: FAIL because managers/events do not exist.

- [ ] **Step 3: Implement reset request and completion**

For known users, call `generateResetToken($user)`, generate an absolute `te4g_user_password_reset` URL whose `token` route parameter is `ResetPasswordToken::getToken()`, pass that URL to the mailer, and let the upstream helper enforce lifetime/throttle. On reset, call `validateTokenAndFetchUser()`, assert the contract, hash/save, remove the token, remove other requests for that user, and dispatch `PasswordChangedEvent` with origin `Reset`.

- [ ] **Step 4: Implement authenticated change**

Use `isPasswordValid($user, $currentPassword)` before hashing. On success save, call `removeRequests($user)`, and dispatch origin `Authenticated`. Never store either plain password in an event or exception.

- [ ] **Step 5: Run manager suite**

Run: `php vendor/bin/phpunit tests/Unit/Manager`

Expected: PASS, including upstream-helper exception translation without token leakage.

- [ ] **Step 6: Commit password workflows**

```bash
git add src/Manager src/Event src/Exception config/runtime.php tests/Unit/Manager
git commit -m "feat: add password recovery and change workflows"
```

---

### Task 8: Implement Pending Email Changes and Guarded Account Deletion

**Files:**
- Create: `src/Manager/ProfileManager.php`
- Create: `src/Manager/AccountDeletionManager.php`
- Create: `src/Event/EmailChangeRequestedEvent.php`
- Create: `src/Event/EmailChangedEvent.php`
- Create: `src/Event/BeforeAccountDeletedEvent.php`
- Create: `src/Event/AfterAccountDeletedEvent.php`
- Create: `src/Exception/EmailChangeFailed.php`
- Modify: `src/DependencyInjection/Te4gUserExtension.php`
- Modify: `config/runtime.php`
- Create: `tests/Unit/Manager/ProfileManagerTest.php`
- Create: `tests/Unit/Manager/AccountDeletionManagerTest.php`

**Interfaces:**
- Produces: `ProfileManager::requestEmailChange(AccountUserInterface $user, string $newEmail, string $currentPassword): void`.
- Produces: `ProfileManager::confirmEmailChange(string $signedUri, int|string $accountId): AccountUserInterface`.
- Produces: `AccountDeletionManager::delete(AccountUserInterface $user, string $currentPassword): void`.

- [ ] **Step 1: Write failing pending-email and deletion rollback tests**

```php
public function testEmailRemainsActiveUntilPendingAddressIsConfirmed(): void
{
    $user = TestAccountUser::verified('old@example.com');

    $this->profile()->requestEmailChange($user, ' New@Example.com ', 'valid-password');

    self::assertSame('old@example.com', $user->getEmail());
    self::assertSame('new@example.com', $user->getPendingEmail());
    self::assertCount(1, $this->mailer->emailChangeMessages);
}

public function testRejectedDeletionLeavesRepositoryAndSessionCandidateUnchanged(): void
{
    $user = TestAccountUser::verified('user@example.com');
    $this->repository->save($user);
    $guard = new RejectingDeletionGuard('account_has_orders');

    $this->expectException(AccountDeletionRejected::class);

    try {
        $this->deletion([$guard])->delete($user, 'valid-password');
    } finally {
        self::assertSame($user, $this->repository->findOneByEmail('user@example.com'));
    }
}
```

- [ ] **Step 2: Run focused tests**

Run: `php vendor/bin/phpunit tests/Unit/Manager/ProfileManagerTest.php tests/Unit/Manager/AccountDeletionManagerTest.php`

Expected: FAIL because both managers/events are absent.

- [ ] **Step 3: Implement pending email confirmation**

Validate the current password, normalize and uniqueness-check the new address, set only `pendingEmail`, save, sign against the pending address with account ID as an extra parameter, mail it, and dispatch `EmailChangeRequestedEvent`.

Confirmation loads by ID, rejects missing/mismatched pending state, validates the signed URI against the pending address, swaps email, clears pending email, saves once, removes reset requests, and dispatches `EmailChangedEvent($user, $previousEmail)`.

- [ ] **Step 4: Implement guarded deletion**

Validate current password, execute every tagged `AccountDeletionGuardInterface` in deterministic priority order, dispatch `BeforeAccountDeletedEvent`, capture string ID/email, remove through the repository, and dispatch `AfterAccountDeletedEvent($accountId, $email)`. A guard or persistence exception must occur before the controller clears the security token. Catch and safely log exceptions from after-event listeners so an already-completed deletion is not reported as a rollback-capable failure.

Register autoconfiguration for `AccountDeletionGuardInterface` with tag `te4g_user.account_deletion_guard`; inject a priority-aware tagged iterator into the manager.

- [ ] **Step 5: Run all manager tests**

Run: `php vendor/bin/phpunit tests/Unit/Manager`

Expected: PASS for duplicate pending email, superseded confirmation, invalid password, guard rejection, repository failure, and success.

- [ ] **Step 6: Commit self-administration workflows**

```bash
git add src/Manager src/Event src/Exception config/runtime.php tests/Unit/Manager
git commit -m "feat: add profile and account deletion workflows"
```

---

### Task 9: Deliver Public Account HTTP Flows

**Files:**
- Create: `src/Form/Model/RegistrationInput.php`
- Create: `src/Form/Model/EmailInput.php`
- Create: `src/Form/Model/ResetPasswordInput.php`
- Create: `src/Form/RegistrationType.php`
- Create: `src/Form/EmailType.php`
- Create: `src/Form/ResetPasswordType.php`
- Create: `src/Controller/RegistrationController.php`
- Create: `src/Controller/VerificationController.php`
- Create: `src/Controller/LoginController.php`
- Create: `src/Controller/MagicLinkController.php`
- Create: `src/Controller/PasswordResetController.php`
- Create: `src/Mailer/AccountMailer.php`
- Create: `config/routes.php`
- Modify: `config/runtime.php`
- Create: `templates/registration/register.html.twig`
- Create: `templates/security/login.html.twig`
- Create: `templates/security/login_link_check.html.twig`
- Create: `templates/security/check_email.html.twig`
- Create: `templates/password/request.html.twig`
- Create: `templates/password/reset.html.twig`
- Create: `templates/email/verify.html.twig`
- Create: `templates/email/magic_link.html.twig`
- Create: `templates/email/password_reset.html.twig`
- Create: `translations/Te4gUserBundle.en.xlf`
- Create: `tests/Unit/Mailer/AccountMailerTest.php`
- Create: `tests/Functional/PublicAccountFlowTest.php`

**Interfaces:**
- Consumes: Tasks 5-7 managers.
- Consumes: `RateLimiterFactory` service `limiter.te4g_user_account_requests` for registration, verification resend, magic-link, and reset requests.
- Produces routes: `te4g_user_register`, `te4g_user_verify_email`, `te4g_user_verify_resend`, `te4g_user_login`, `te4g_user_logout`, `te4g_user_login_link_request`, `te4g_user_login_link_check`, `te4g_user_password_request`, `te4g_user_password_reset`.
- Produces: invalid Turbo form responses use HTTP 422; success uses PRG redirects.

The exact public route map is `GET|POST /register`, `GET /verify-email`, `POST /verify-email/resend`, `GET|POST /login`, `POST /login-link`, `POST /logout`, `GET|POST /login-link/check`, `GET|POST /forgot-password`, and `GET|POST /reset-password/{token}`.

- [ ] **Step 1: Write route and neutral-response functional tests**

```php
public function testPublicRoutesRenderAndUnknownEmailResponsesMatch(): void
{
    $client = static::createClient();

    $client->request('GET', '/register');
    self::assertResponseIsSuccessful();
    self::assertSelectorExists('form[name="registration"]');

    $client->request('POST', '/login-link', [
        'email' => ['email' => 'missing@example.com'],
    ]);
    self::assertResponseRedirects('/check-email');
}

public function testInvalidRegistrationReturnsUnprocessableEntity(): void
{
    $client = static::createClient();
    $client->request('POST', '/register', ['registration' => [
        'email' => 'not-an-email',
        'plainPassword' => ['first' => 'short', 'second' => 'different'],
    ]]);

    self::assertResponseStatusCodeSame(422);
}

public function testMailTransportFailureIsLoggedWithoutLeakingTheUrl(): void
{
    $mailer = $this->accountMailerWithFailingTransport();

    $mailer->sendMagicLink(
        TestAccountUser::verified('user@example.com'),
        'https://example.test/login-link/check?user=user%40example.com&hash=secret',
    );

    self::assertSame('Account email delivery failed.', $this->logger->lastMessage());
    self::assertStringNotContainsString('secret', json_encode($this->logger->lastContext(), JSON_THROW_ON_ERROR));
    self::assertStringNotContainsString('user@example.com', json_encode($this->logger->lastContext(), JSON_THROW_ON_ERROR));
}
```

- [ ] **Step 2: Run the public functional test**

Run: `php vendor/bin/phpunit tests/Functional/PublicAccountFlowTest.php`

Expected: FAIL with missing routes/controllers.

- [ ] **Step 3: Implement input models, forms, and thin controllers**

Use `Assert\Email`, `Assert\NotBlank`, `PasswordStrength(minScore: PasswordStrength::STRENGTH_MEDIUM)`, and `RepeatedType` for passwords. Controllers call managers only after a valid form, always redirect unknown/existing email requests to the same check-email route, and convert invalid submissions to `Response::HTTP_UNPROCESSABLE_ENTITY`.

For request-style endpoints, consume the rate limiter with a key made from the client IP plus `hash('sha256', $normalizedEmail)`. A rejected limit returns the same redirect and page copy as an accepted request and does not call the manager.

`GET /login-link/check` copies only `expires`, `user`, and `hash` into hidden form fields. The same route accepts POST, which Symfony's login-link authenticator intercepts. Do not create a second confirmation route.

- [ ] **Step 4: Implement the concrete account mailer**

Build `TemplatedEmail` messages from `UserBundleOptions`, pass only required template context, and call `MailerInterface::send()`. The four interface methods use subjects/translation keys for verification, magic link, password reset, and pending-email confirmation. Catch `TransportExceptionInterface`, log only `message_type` with the fixed message `Account email delivery failed.`, and return so persisted registration/pending state remains recoverable. Never log the recipient, URL, exception message, or token-bearing context.

- [ ] **Step 5: Add accessible fallback templates and English translations**

Every form has a heading, explicit labels, an error summary, field errors, CSRF output, and an `aria-live="polite"` status region. Fallback templates use semantic HTML only; the installer adds shadcn overrides later.

- [ ] **Step 6: Run public HTTP and manager suites**

Run: `php vendor/bin/phpunit tests/Functional/PublicAccountFlowTest.php tests/Unit/Mailer tests/Unit/Manager`

Run: `php bin/console lint:twig templates` in the fixture console harness.

Expected: PASS, including registration, verification, generic login errors, magic-link GET/POST behavior, reset expiry, and one-time reset consumption.

- [ ] **Step 7: Commit public account delivery**

```bash
git add src/Form src/Controller src/Mailer config templates translations tests/Functional
git commit -m "feat: add public account HTTP flows"
```

---

### Task 10: Deliver Authenticated Self-Administration HTTP Flows

**Files:**
- Create: `src/Form/Model/ProfileInput.php`
- Create: `src/Form/Model/PasswordChangeInput.php`
- Create: `src/Form/Model/DeleteAccountInput.php`
- Create: `src/Form/ProfileType.php`
- Create: `src/Form/PasswordChangeType.php`
- Create: `src/Form/DeleteAccountType.php`
- Create: `src/Controller/AccountController.php`
- Create: `src/Controller/ProfileController.php`
- Create: `src/Controller/PasswordController.php`
- Create: `src/Controller/DeleteAccountController.php`
- Create: `templates/account/index.html.twig`
- Create: `templates/account/profile.html.twig`
- Create: `templates/account/password.html.twig`
- Create: `templates/email/email_change.html.twig`
- Modify: `config/routes.php`
- Modify: `config/runtime.php`
- Modify: `translations/Te4gUserBundle.en.xlf`
- Create: `tests/Functional/SelfAdministrationFlowTest.php`

**Interfaces:**
- Consumes: Task 8 managers and Task 7 password manager.
- Produces routes: `te4g_user_account`, `te4g_user_profile`, `te4g_user_email_change_confirm`, `te4g_user_password_change`, `te4g_user_account_delete`.
- Produces: deletion clears `TokenStorageInterface` and invalidates the session only after manager success.

The exact authenticated route map is `GET /account`, `GET|POST /account/profile`, `GET /account/email-change`, `GET|POST /account/password`, and `POST /account/delete`.

- [ ] **Step 1: Write authenticated access, pending-email, and deletion tests**

```php
public function testAccountPagesRequireAuthentication(): void
{
    static::createClient()->request('GET', '/account');

    self::assertResponseRedirects('/login');
}

public function testDeleteFailureKeepsUserAuthenticated(): void
{
    $client = $this->loginVerifiedUser();
    $this->repository->failNextRemove();

    $client->request('POST', '/account/delete', ['delete_account' => [
        'currentPassword' => 'valid-password',
        'confirm' => '1',
    ]]);

    self::assertResponseStatusCodeSame(422);
    self::assertNotNull(self::getContainer()->get(TokenStorageInterface::class)->getToken());
}

public function testDeletionEndpointIsNotFoundWhenFeatureIsDisabled(): void
{
    $client = $this->loginVerifiedUser(kernelOptions: ['account_deletion' => false]);

    $client->request('POST', '/account/delete');

    self::assertResponseStatusCodeSame(404);
}
```

- [ ] **Step 2: Run the functional test**

Run: `php vendor/bin/phpunit tests/Functional/SelfAdministrationFlowTest.php`

Expected: FAIL because routes/forms/controllers are missing.

- [ ] **Step 3: Implement self-administration forms and controllers**

Initialize `ProfileInput` from the current account without binding the entity. Require current password for profile/email, password change, and deletion. `DeleteAccountInput` contains a required `confirm: bool` field and current password.

Controllers reject non-`AccountUserInterface` principals, catch only safe domain exceptions, return 422 on invalid/rejected operations, and redirect using `UserBundleOptions` route names after success.

The account overview renders the deletion form only when `UserBundleOptions::$deletionEnabled` is true. The deletion controller also throws `NotFoundHttpException` before processing input when the feature is false.

- [ ] **Step 4: Implement post-deletion authentication clearing**

After `AccountDeletionManager::delete()` returns, call `TokenStorageInterface::setToken(null)`, invalidate the session, and redirect. Do not clear either if a guard or repository throws.

- [ ] **Step 5: Add fallback templates and mail template**

Render pending email separately from active email, render deletion as a POST form on the account overview, and use an ordinary confirmation checkbox in the fallback UI. The later shadcn override adds Alert Dialog while preserving the POST/CSRF contract.

- [ ] **Step 6: Run the runtime checkpoint gate**

Run: `php vendor/bin/phpunit tests/Functional tests/Unit/Manager tests/Integration`

Run: `make test`

Expected: PASS for the complete runtime lifecycle before installer/UI generation work begins.

- [ ] **Step 7: Commit self-administration delivery**

```bash
git add src/Form src/Controller templates translations config tests/Functional
git commit -m "feat: add account self-administration pages"
```

---

### Task 11: Build the Safe Filesystem Installation Engine

**Files:**
- Create: `src/Installer/ProjectLayout.php`
- Create: `src/Installer/PlannedFile.php`
- Create: `src/Installer/InstallationPlan.php`
- Create: `src/Installer/InstallationManifest.php`
- Create: `src/Installer/ManifestStore.php`
- Create: `src/Installer/MarkerEditor.php`
- Create: `src/Installer/InstallationPlanner.php`
- Create: `src/Installer/StagedWriter.php`
- Create: `src/Installer/InstallationResult.php`
- Create: `src/Exception/InstallerConflict.php`
- Create: `src/Exception/InstallerWriteFailed.php`
- Modify: `config/installer.php`
- Create: `tests/Unit/Installer/InstallationPlannerTest.php`
- Create: `tests/Unit/Installer/MarkerEditorTest.php`
- Create: `tests/Unit/Installer/StagedWriterTest.php`
- Create: `tests/Fixtures/Installer/fresh/.gitkeep`

**Interfaces:**
- Produces: `InstallationPlanner::plan(ProjectLayout $layout, array $renderedFiles): InstallationPlan`.
- Produces: `StagedWriter::apply(InstallationPlan $plan): InstallationResult`.
- Produces: `MarkerEditor::upsert(string $contents, string $name, string $generated): string`.
- Produces: SHA-256 manifest entries keyed by project-relative path.

- [ ] **Step 1: Write conflict, idempotency, marker, and rollback tests**

```php
public function testModifiedGeneratedFileIsReportedAndNotOverwritten(): void
{
    $layout = ProjectLayout::at($this->fixtureDir);
    $this->write('templates/components/Button.html.twig', 'application edit');
    $this->manifest(['templates/components/Button.html.twig' => hash('sha256', 'generated original')]);

    $plan = $this->planner->plan($layout, [
        'templates/components/Button.html.twig' => 'generated update',
    ]);

    self::assertTrue($plan->hasConflicts());
    self::assertSame('application edit', $this->read('templates/components/Button.html.twig'));
}

public function testApplyRollsBackEarlierWritesWhenLaterValidationFails(): void
{
    $plan = InstallationPlan::fromFiles([
        PlannedFile::create('src/Entity/User.php', '<?php valid'),
        PlannedFile::create('config/packages/broken.yaml', "broken: [\n"),
    ]);

    $this->expectException(InstallerWriteFailed::class);

    try {
        $this->writer->apply($plan);
    } finally {
        self::assertFileDoesNotExist($this->fixtureDir.'/src/Entity/User.php');
    }
}
```

- [ ] **Step 2: Run installer unit tests**

Run: `php vendor/bin/phpunit tests/Unit/Installer`

Expected: FAIL because the installation engine does not exist.

- [ ] **Step 3: Implement bounded paths and immutable plans**

`ProjectLayout::path($relative)` rejects absolute paths, `..` segments, NUL bytes, and any normalized path outside `kernel.project_dir`. `PlannedFile` stores relative path, prior hash or null, new contents, and mode (`create` or `managed-update`). `InstallationPlan` exposes ordered files/conflicts and never writes.

- [ ] **Step 4: Implement manifest and marker semantics**

Use JSON with this stable shape:

```json
{
    "bundleVersion": "0.1.0",
    "generatedAt": "2026-08-03T12:00:00+00:00",
    "files": {
        "src/Entity/User.php": "sha256-hex"
    }
}
```

Marker regions are exactly `/* <te4g-user name="NAME"> */` and `/* </te4g-user> */`; missing closing, duplicate, or nested markers are conflicts.

- [ ] **Step 5: Implement staged validation and rollback**

Stage under a random directory inside `var/te4g-user-installer/`, validate PHP with `php -l`, YAML with `Yaml::parse()`, JSON with `json_decode(..., JSON_THROW_ON_ERROR)`, and Twig through an injected validator. Preserve original contents in memory until all moves and post-write validators succeed; restore in reverse order on any exception.

- [ ] **Step 6: Run installer tests and static analysis**

Run: `php vendor/bin/phpunit tests/Unit/Installer`

Run: `make stan`

Expected: PASS, including traversal strings such as `../../../.env` and symlink escape fixtures.

- [ ] **Step 7: Commit the installation engine**

```bash
git add src/Installer src/Exception config/installer.php tests/Unit/Installer tests/Fixtures/Installer
git commit -m "feat: add safe application installation engine"
```

---

### Task 12: Add the Generated Doctrine and Symfony Configuration Skeleton

**Files:**
- Create: `src/Installer/SkeletonRenderer.php`
- Create: `resources/skeleton/src/Entity/User.php.tpl`
- Create: `resources/skeleton/src/Repository/UserRepository.php.tpl`
- Create: `resources/skeleton/src/Entity/ResetPasswordRequest.php.tpl`
- Create: `resources/skeleton/src/Repository/ResetPasswordRequestRepository.php.tpl`
- Create: `resources/skeleton/config/packages/te4g_user.yaml.tpl`
- Create: `resources/skeleton/config/packages/te4g_user_security.yaml.tpl`
- Create: `resources/skeleton/config/packages/te4g_user_twig.yaml.tpl`
- Create: `resources/skeleton/config/packages/reset_password.yaml.tpl`
- Create: `resources/skeleton/config/packages/verify_email.yaml.tpl`
- Create: `resources/skeleton/config/routes/te4g_user.yaml.tpl`
- Create: `tests/Unit/Installer/SkeletonRendererTest.php`
- Create: `tests/Integration/GeneratedSkeletonTest.php`

**Interfaces:**
- Produces: `SkeletonRenderer::renderAll(ProjectLayout $layout): array<string,string>`.
- Produces: generated `App\Entity\User implements AccountUserInterface` and `App\Repository\UserRepository implements AccountRepositoryInterface, PasswordUpgraderInterface`.
- Produces: disabled-to-enabled application config and exact native security options.

- [ ] **Step 1: Write exact generation assertions**

```php
public function testGeneratedUserAndSecurityConfigUseApprovedContracts(): void
{
    $files = $this->renderer->renderAll(ProjectLayout::at('/tmp/app'));

    self::assertStringContainsString(
        'final class User implements AccountUserInterface',
        $files['src/Entity/User.php'],
    );
    self::assertStringContainsString('enabled: true', $files['config/packages/te4g_user.yaml']);
    self::assertStringContainsString('check_post_only: true', $files['config/packages/te4g_user_security.yaml']);
    self::assertStringContainsString('max_uses: 1', $files['config/packages/te4g_user_security.yaml']);
    self::assertStringContainsString('lifetime: 600', $files['config/packages/te4g_user_security.yaml']);
}
```

- [ ] **Step 2: Run skeleton tests**

Run: `php vendor/bin/phpunit tests/Unit/Installer/SkeletonRendererTest.php tests/Integration/GeneratedSkeletonTest.php`

Expected: FAIL because renderer/resources are absent.

- [ ] **Step 3: Implement controlled template rendering**

Allow only `__APP_NAMESPACE__`, `__USER_CLASS__`, `__USER_REPOSITORY__`, and `__BUNDLE_VERSION__`. Reject any unreplaced `__[A-Z_]+__` token after `strtr()`. Resource names are an explicit PHP list; never glob an untrusted path.

- [ ] **Step 4: Add the generated Doctrine classes**

The generated user uses attributes, integer ID, unique `email` length 180, nullable `pendingEmail`, JSON roles, password length 255, boolean verified, lowercase identifier, and safe password-hash session serialization. The repository normalizes lookup email, flushes save/remove, and upgrades hashes.

The reset request follows SymfonyCasts contracts exactly:

```php
final class ResetPasswordRequest implements ResetPasswordRequestInterface
{
    public function __construct(
        User $user,
        DateTimeInterface $expiresAt,
        string $selector,
        string $hashedToken,
    ) {
        $this->user = $user;
        $this->expiresAt = $expiresAt;
        $this->selector = $selector;
        $this->hashedToken = $hashedToken;
    }
}
```

Its repository extends `ServiceEntityRepository`, implements `ResetPasswordRequestRepositoryInterface`, and uses `ResetPasswordRequestRepositoryTrait`.

- [ ] **Step 5: Add exact security and bundle configuration**

The generated security file configures:

```yaml
security:
    expose_security_errors: none
    password_hashers:
        Te4g\UserBundle\Contract\AccountUserInterface: 'auto'
    providers:
        te4g_user_provider:
            id: Te4g\UserBundle\Security\AccountUserProvider
    firewalls:
        main:
            lazy: true
            provider: te4g_user_provider
            user_checker: Te4g\UserBundle\Security\VerifiedAccountUserChecker
            form_login:
                login_path: te4g_user_login
                check_path: te4g_user_login
                enable_csrf: true
                default_target_path: '%te4g_user.redirects.login_success%'
            login_throttling:
                max_attempts: 5
                interval: '15 minutes'
            login_link:
                check_route: te4g_user_login_link_check
                signature_properties: [id, email, password]
                lifetime: 600
                max_uses: 1
                check_post_only: true
                used_link_cache: cache.te4g_user_login_links
                default_target_path: '%te4g_user.redirects.login_success%'
            logout:
                path: te4g_user_logout
                enable_csrf: true
```

Add a dedicated cache pool and login throttling. Define ordered, exact route-name `access_control` entries: `PUBLIC_ACCESS` for every Task 9 route and `IS_AUTHENTICATED_FULLY` for every Task 10 route. Do not match literal paths, because the application may edit the route-import prefix.

The same generated file adds the neutral-request limiter:

```yaml
framework:
    cache:
        pools:
            cache.te4g_user_login_links: ~
    rate_limiter:
        te4g_user_account_requests:
            policy: sliding_window
            limit: 5
            interval: '15 minutes'
```

The generated upstream-bundle settings are exact:

```yaml
symfonycasts_verify_email:
    lifetime: 3600

symfonycasts_reset_password:
    request_password_repository: App\Repository\ResetPasswordRequestRepository
    lifetime: 3600
    throttle_limit: 3600
    enable_garbage_collection: true
```

The route import is application-owned and deliberately keeps the prefix editable:

```yaml
te4g_user:
    resource: '@Te4gUserBundle/config/routes.php'
    prefix: ''
```

- [ ] **Step 6: Validate generated artifacts in an isolated fixture kernel**

Run: `php vendor/bin/phpunit tests/Integration/GeneratedSkeletonTest.php`

Expected: generated PHP lints, YAML parses, Doctrine metadata loads, container compiles, and route names resolve.

- [ ] **Step 7: Commit the backend skeleton**

```bash
git add src/Installer/SkeletonRenderer.php resources/skeleton/src resources/skeleton/config tests/Unit/Installer tests/Integration/GeneratedSkeletonTest.php
git commit -m "feat: add generated user and security skeleton"
```

---

### Task 13: Snapshot and Install the Symfony UX Toolkit Shadcn UI

**Files:**
- Create: `tools/ShadcnSnapshotSynchronizer.php`
- Create: `tests/Unit/Tools/ShadcnSnapshotSynchronizerTest.php`
- Create: `resources/shadcn/manifest.json`
- Create: `resources/skeleton/templates/components/` files enumerated and hashed by `resources/shadcn/manifest.json`
- Create: `resources/skeleton/templates/bundles/Te4gUserBundle/registration/register.html.twig`
- Create: `resources/skeleton/templates/bundles/Te4gUserBundle/security/login.html.twig`
- Create: `resources/skeleton/templates/bundles/Te4gUserBundle/security/login_link_check.html.twig`
- Create: `resources/skeleton/templates/bundles/Te4gUserBundle/security/check_email.html.twig`
- Create: `resources/skeleton/templates/bundles/Te4gUserBundle/password/request.html.twig`
- Create: `resources/skeleton/templates/bundles/Te4gUserBundle/password/reset.html.twig`
- Create: `resources/skeleton/templates/bundles/Te4gUserBundle/account/index.html.twig`
- Create: `resources/skeleton/templates/bundles/Te4gUserBundle/account/profile.html.twig`
- Create: `resources/skeleton/templates/bundles/Te4gUserBundle/account/password.html.twig`
- Create: `resources/skeleton/templates/bundles/Te4gUserBundle/email/verify.html.twig`
- Create: `resources/skeleton/templates/bundles/Te4gUserBundle/email/magic_link.html.twig`
- Create: `resources/skeleton/templates/bundles/Te4gUserBundle/email/password_reset.html.twig`
- Create: `resources/skeleton/templates/bundles/Te4gUserBundle/email/email_change.html.twig`
- Create: `resources/skeleton/templates/form/te4g_user_shadcn_theme.html.twig`
- Create: `resources/skeleton/assets/controllers/password_visibility_controller.js`
- Create: `resources/skeleton/assets/controllers/alert_dialog_controller.js`
- Create: `resources/skeleton/assets/icons/lucide/circle-check.svg`
- Create: `resources/skeleton/assets/icons/lucide/eye.svg`
- Create: `resources/skeleton/assets/icons/lucide/eye-off.svg`
- Create: `resources/skeleton/assets/icons/lucide/key-round.svg`
- Create: `resources/skeleton/assets/icons/lucide/mail.svg`
- Create: `resources/skeleton/assets/icons/lucide/trash-2.svg`
- Create: `resources/skeleton/assets/icons/lucide/triangle-alert.svg`
- Create: `resources/skeleton/assets/styles/te4g_user.css.fragment`
- Create: `resources/skeleton/importmap.required.json`
- Create: `resources/skeleton/translations/Te4gUserBundle.en.xlf`
- Create: `THIRD_PARTY_NOTICES.md`
- Modify: `src/Installer/SkeletonRenderer.php`
- Create: `tests/Integration/ShadcnSkeletonTest.php`

**Interfaces:**
- Consumes: Task 12 skeleton rendering.
- Produces: application-owned Card, Button, Input, Field, Alert, Separator, Alert Dialog, Spinner, and Lucide UX Icon resources.
- Produces: shadcn page overrides for every Task 9/10 template.

- [ ] **Step 1: Write snapshot path and rendered-page tests**

```php
public function testSnapshotRejectsTraversalAndRequiresPatchedToolkit(): void
{
    $manifest = ShadcnManifest::fromJson($this->manifestJson);

    self::assertTrue(version_compare($manifest->upstreamVersion(), '3.3.0', '>='));
    foreach ($manifest->files() as $file) {
        self::assertStringNotContainsString('..', $file->path());
        self::assertFalse(str_starts_with($file->path(), '/'));
    }
}

public function testInstalledLoginUsesShadcnComponents(): void
{
    $template = $this->rendered['templates/bundles/Te4gUserBundle/security/login.html.twig'];

    self::assertStringContainsString('<twig:Card>', $template);
    self::assertStringContainsString('<twig:Button', $template);
    self::assertStringContainsString('aria-live="polite"', $template);
}

public function testGeneratedAssetsBuildAndIconsAreLocked(): void
{
    $result = $this->generatedFixture->run([
        ['tailwind:build'],
        ['ux:icons:lock'],
    ]);

    self::assertTrue($result->isSuccessful(), $result->combinedOutput());
    self::assertFileExists($this->generatedFixture->path('var/tailwind/app.built.css'));
    foreach (['circle-check', 'eye', 'eye-off', 'key-round', 'mail', 'trash-2', 'triangle-alert'] as $icon) {
        self::assertFileExists($this->generatedFixture->path(sprintf('assets/icons/lucide/%s.svg', $icon)));
    }
}
```

- [ ] **Step 2: Run snapshot tests**

Run: `php vendor/bin/phpunit tests/Unit/Tools/ShadcnSnapshotSynchronizerTest.php tests/Integration/ShadcnSkeletonTest.php`

Expected: FAIL because the snapshot and pages do not exist.

- [ ] **Step 3: Generate the upstream component workspace from UX Toolkit 3.3**

In a temporary Symfony 8.1 fixture pinned to `symfony/ux-toolkit:3.3.0`, run exactly:

```bash
php bin/console ux:install card button input field alert separator alert-dialog spinner --kit shadcn --no-interaction
```

Copy only files listed by the upstream recipes into `resources/skeleton/templates/components/` and required Stimulus controllers into `resources/skeleton/assets/controllers/`. Snapshot the seven fixed Lucide icons listed above into `resources/skeleton/assets/icons/lucide/` so production rendering never fetches icons on demand. Record upstream package versions, recipe/icon names, relative paths, licenses, and SHA-256 hashes in `resources/shadcn/manifest.json`.

- [ ] **Step 4: Implement the maintainer synchronizer safely**

The synchronizer accepts only the eight fixed recipe names, rejects absolute/parent paths before reading or writing, verifies every source is below the temporary project root, and updates the manifest deterministically. Add fixtures proving `../../../.env` and symlink escapes are rejected.

- [ ] **Step 5: Build all account page overrides**

Compose the Task 9/10 forms with Card, Field, Alert, Button, Separator, and Spinner. Use Alert Dialog only to submit the existing deletion POST form. Preserve Symfony form field names, CSRF tokens, route names, HTTP behavior, explicit labels, `aria-describedby`, keyboard focus, and `aria-live` regions.

Add `password_visibility_controller.js` with one target for the input and one for the accessible button; it toggles only `type`, `aria-pressed`, and translated label text.

- [ ] **Step 6: Add Tailwind/import-map fragments and attribution**

The CSS fragment imports Tailwind, `shadcn/dist/tailwind.css`, and `tw-animate-css/dist/tw-animate.css`, then defines shadcn light/dark variables inside the named installer marker. `THIRD_PARTY_NOTICES.md` records Symfony UX Toolkit and Lucide sources, exact versions, licenses, copied files, and local modifications.

`importmap.required.json` contains exactly `shadcn/dist/tailwind.css` and `tw-animate-css/dist/tw-animate.css`; Task 14 installs them through AssetMapper's `importmap:require` command and includes `importmap.php` in rollback tracking.

- [ ] **Step 7: Validate Twig, components, CSS build, and manifest hashes**

Run: `php vendor/bin/phpunit tests/Unit/Tools tests/Integration/ShadcnSkeletonTest.php`

Run in the generated fixture: `php bin/console lint:twig templates`

Run in the generated fixture: `php bin/console tailwind:build`

Run in the generated fixture: `php bin/console ux:icons:lock`

Expected: PASS with no on-demand icon fetch required in production.

- [ ] **Step 8: Commit the application-owned UI snapshot**

```bash
git add tools resources THIRD_PARTY_NOTICES.md src/Installer/SkeletonRenderer.php tests/Unit/Tools tests/Integration/ShadcnSkeletonTest.php
git commit -m "feat: add application-owned shadcn account UI"
```

---

### Task 14: Orchestrate Preflight, Installation, Validation, and Migration Generation

**Files:**
- Create: `src/Installer/PreflightChecker.php`
- Create: `src/Installer/MigrationGeneratorInterface.php`
- Create: `src/Installer/DoctrineMigrationGenerator.php`
- Create: `src/Command/InstallCommand.php`
- Modify: `config/installer.php`
- Create: `tests/Unit/Installer/PreflightCheckerTest.php`
- Create: `tests/Unit/Installer/DoctrineMigrationGeneratorTest.php`
- Create: `tests/Functional/InstallCommandTest.php`
- Create: `tests/Fixtures/Installer/conflicting-security/config/packages/security.yaml`

**Interfaces:**
- Produces: `PreflightChecker::check(ProjectLayout): list<InstallerConflict>`.
- Produces: `MigrationGeneratorInterface::generate(string $projectDir): ?string` returning the created project-relative migration path.
- Produces: CLI options `--dry-run` and standard `--no-interaction`; no `--force`.

- [ ] **Step 1: Write dry-run, conflict, idempotency, and migration failure command tests**

```php
public function testDryRunPrintsPlanWithoutWritingOrGeneratingMigration(): void
{
    $tester = $this->commandTester($this->freshProject());

    $status = $tester->execute(['--dry-run' => true], ['interactive' => false]);

    self::assertSame(Command::SUCCESS, $status);
    self::assertStringContainsString('CREATE src/Entity/User.php', $tester->getDisplay());
    self::assertFileDoesNotExist($this->projectDir.'/src/Entity/User.php');
    self::assertSame(0, $this->migrationGenerator->calls);
}

public function testMigrationFailureRollsBackEveryGeneratedFile(): void
{
    $this->migrationGenerator->failWith('mapping error');

    $status = $this->commandTester($this->freshProject())->execute([], ['interactive' => false]);

    self::assertSame(Command::FAILURE, $status);
    self::assertFileDoesNotExist($this->projectDir.'/src/Entity/User.php');
    self::assertStringNotContainsString('mapping error', $this->loggedSecrets());
}
```

- [ ] **Step 2: Run command tests**

Run: `php vendor/bin/phpunit tests/Unit/Installer tests/Functional/InstallCommandTest.php`

Expected: FAIL because preflight, migration generator, and command are absent.

- [ ] **Step 3: Implement exact preflight checks**

Check PHP/Symfony versions, required bundles, `main` firewall compatibility, required directories, route-name collisions, existing user/reset classes, component/controller conflicts, writable bounded targets, marker validity, and manifest state. Return all conflicts in deterministic path/code order; do not write during preflight.

- [ ] **Step 4: Implement safe migration generation**

Use Symfony Process with argument array:

```php
[
    PHP_BINARY,
    $projectDir.'/bin/console',
    'doctrine:migrations:diff',
    '--no-interaction',
]
```

Set timeout 120 seconds, working directory to the project root, and capture the migrations directory before/after to identify exactly one new file. More than one new migration or any non-zero exit is failure. Never invoke `doctrine:migrations:migrate`.

- [ ] **Step 5: Implement command orchestration**

Order is preflight, render, plan, print summary, optional confirmation, staged apply, `tailwind:init --no-interaction` when Tailwind is not initialized, `importmap:require shadcn/dist/tailwind.css tw-animate-css/dist/tw-animate.css`, subprocess container validation, Tailwind build, Twig/YAML lint, icon lock, migration diff, and manifest finalization. Track every file changed by Tailwind and ImportMap so the same rollback restores them. On any post-write failure, call rollback and print only a safe concise error. `--no-interaction` proceeds only with zero conflicts; an interactive conflict also fails because v1 has no overwrite choice.

- [ ] **Step 6: Run the installer checkpoint gate**

Run: `php vendor/bin/phpunit tests/Unit/Installer tests/Functional/InstallCommandTest.php tests/Integration/GeneratedSkeletonTest.php tests/Integration/ShadcnSkeletonTest.php`

Run: `make test`

Expected: PASS for fresh, dry-run, repeat no-op, conflict, application edit, traversal, validation failure, migration failure, and successful migration generation without execution.

- [ ] **Step 7: Commit the complete installer**

```bash
git add src/Installer src/Command config/installer.php tests/Unit/Installer tests/Functional/InstallCommandTest.php tests/Fixtures/Installer
git commit -m "feat: add one-command user bundle installer"
```

---

### Task 15: Add Flex Recipe Source, End-to-End Smoke Test, CI, and Documentation

**Files:**
- Create: `flex/manifest.json`
- Create: `flex/config/packages/te4g_user.yaml`
- Create: `scripts/smoke-test.sh`
- Create: `.github/workflows/ci.yaml`
- Modify: `Makefile`
- Rewrite: `README.md`
- Rewrite: `docs/index.md`
- Create: `docs/configuration.md`
- Create: `docs/customization.md`
- Create: `docs/security.md`
- Create: `tests/Integration/FlexRecipeTest.php`

**Interfaces:**
- Produces: Flex registration of `Te4g\UserBundle\Te4gUserBundle` in all environments and `te4g_user.enabled: false` bootstrap config.
- Produces: `make smoke` and CI matrix for PHP 8.4/8.5.
- Produces: operational installation/customization/security documentation.

- [ ] **Step 1: Write a Flex recipe structure test**

```php
public function testRecipeRegistersDisabledBundleBootstrap(): void
{
    $manifest = json_decode(file_get_contents(__DIR__.'/../../flex/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

    self::assertSame(
        ['all'],
        $manifest['bundles']['Te4g\\UserBundle\\Te4gUserBundle'],
    );
    self::assertSame(['config/' => '%CONFIG_DIR%/'], $manifest['copy-from-recipe']);
    self::assertStringContainsString(
        'enabled: false',
        file_get_contents(__DIR__.'/../../flex/config/packages/te4g_user.yaml'),
    );
}
```

- [ ] **Step 2: Run the recipe test**

Run: `php vendor/bin/phpunit tests/Integration/FlexRecipeTest.php`

Expected: FAIL because recipe files do not exist.

- [ ] **Step 3: Add recipe source and smoke workflow**

`flex/manifest.json` contains only bundle registration, config copying, and alias `te4g-user`. The copied config keeps runtime disabled so the command can boot before `App\Entity\User` exists.

`scripts/smoke-test.sh` creates a temporary Symfony 8.1 web app, adds this repository as a Composer path repository, applies the local recipe source, requires `te4g/user-bundle:@dev`, runs `te4g:user:install --no-interaction`, verifies a migration was generated but not recorded as executed, boots the container, lists routes, builds Tailwind, locks icons, and runs functional HTTP smoke assertions. It cleans only its own `mktemp -d` directory through a trap.

- [ ] **Step 4: Add local and CI quality commands**

Extend `Makefile`:

```make
test: cs-check stan phpunit twig-check yaml-check assets-check

twig-check:
	@php vendor/bin/phpunit tests/Integration/ShadcnSkeletonTest.php

yaml-check:
	@php vendor/bin/phpunit tests/Integration/GeneratedSkeletonTest.php

assets-check:
	@php vendor/bin/phpunit tests/Integration/ShadcnSkeletonTest.php --filter testGeneratedAssetsBuildAndIconsAreLocked

smoke:
	@./scripts/smoke-test.sh

audit:
	@composer audit --locked
```

CI runs `composer install`, `composer validate --strict`, `composer audit --locked`, and `make test` for PHP 8.4 and 8.5. Run `make smoke` once on PHP 8.5 to control CI duration.

- [ ] **Step 5: Replace template documentation with operational bundle docs**

README includes exact install commands, required environment variables, migration review, route summary, and a warning that magic links are an alternative rather than the only method for security-critical apps. Configuration docs identify one authority file for each option. Customization docs show a form type extension, template override, repository adapter, event subscriber, and deletion guard with complete code. Security docs explain neutral responses, throttling, link lifetimes, session invalidation, logging exclusions, and the pinned UX Toolkit snapshot process.

- [ ] **Step 6: Run final verification from clean dependencies**

Run: `composer validate --strict`

Run: `composer audit --locked`

Run: `make test`

Run: `make smoke`

Run: `git diff --check`

Expected: all commands pass; smoke proves the Composer/recipe/install/migration/boot/UI path.

- [ ] **Step 7: Commit release readiness**

```bash
git add flex scripts .github Makefile README.md docs tests/Integration/FlexRecipeTest.php
git commit -m "docs: add installation recipe and release guidance"
```

- [ ] **Step 8: Prepare the recipes-contrib handoff without external mutation**

Generate the recipes-contrib directory `te4g/user-bundle/0.1/` from `flex/`, run the Symfony recipes checker locally, and save the proposed PR title/body in `docs/recipes-contrib.md`. Do not push or open the external PR until the user explicitly authorizes that publication action.

---

## Final Acceptance Checklist

- [ ] A fresh Flex application exposes `te4g:user:install` immediately after Composer installation because the recipe registers a disabled bootstrap.
- [ ] The installer writes the app-owned user/reset/config/UI files, generates one migration, and never executes it.
- [ ] Running the installer twice is a no-op; modifying a generated UI file prevents replacement.
- [ ] The bundle owns no Doctrine user mapping and runtime managers reference only bundle contracts.
- [ ] Registration, anonymous verification, password login, one-use magic login, reset, profile/email confirmation, password change, and guarded deletion pass functional tests.
- [ ] Unknown/existing email requests have indistinguishable public responses.
- [ ] Invalid forms return 422 under Turbo; CSRF, throttling, link expiry, session invalidation, and current-password checks have regression coverage.
- [ ] Installed pages render the pinned shadcn components, compile with TailwindBundle, and use locked Lucide icons.
- [ ] PHP 8.4/8.5 CI, Composer validation/audit, PHPUnit, PHPStan, formatting, Twig/YAML lint, and clean-app smoke all pass.
- [ ] Recipe publication remains a separately authorized external action.
