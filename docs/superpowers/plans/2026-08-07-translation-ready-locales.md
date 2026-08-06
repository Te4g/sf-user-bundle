# Translation-Ready English and French Locales Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate a fully translated English/French Symfony application with browser negotiation, a language selector on every layout, cookie persistence for visitors, and `User.locale` persistence for authenticated users.

**Architecture:** A generated `SupportedLocales` service owns the single `en`/`fr` allowlist and resolves user, cookie, browser, and fallback preferences. A post-firewall request subscriber applies the result with Symfony's `LocaleSwitcher`; a CSRF-protected POST controller persists explicit choices. All generated UI, validation, and mail copy comes from key-identical XLIFF catalogues, and `TemplatedEmail::locale()` makes queued messages recipient-locale aware.

**Tech Stack:** PHP 8.4+, Symfony 8.1 Translation/Intl/HttpFoundation/Security/Twig/Mailer, Doctrine ORM 3.6.7, Twig Components, PHPUnit 12, XLIFF 1.2.

## Global Constraints

- Work only in `/Users/teag/Dev/sf-user-bundle/.worktrees/translation-ready-locales` on `codex/translation-ready-locales`.
- Preserve the one-shot package contract: generated code is application-owned `App\` code and contains no runtime Te4g reference.
- Support exactly `en` and `fr`; English is the default and fallback.
- Resolve locale in this order: authenticated `User.locale`, `app_locale` cookie, `Accept-Language`, English.
- Keep existing URLs unchanged; do not add locale route prefixes.
- Keep exactly two custom Stimulus controllers; locale selection uses a normal POST form.
- Locale changes require CSRF, reject unsupported locales with HTTP 422, and never redirect to an external or protocol-relative target.
- Persist authenticated choices in `User.locale`; always persist explicit choices in an HTTP-only SameSite=Lax cookie for one year.
- Require `ext-intl` in generated applications because French translation cannot rely on English-only polyfills.
- Translate all user-visible titles, text, actions, accessibility labels, form/controller errors, validation messages, and email subjects/bodies. Internal diagnostic exceptions may remain English.
- Run every change test-first and preserve the no-database-access installation boundary.

## File and Responsibility Map

- `resources/application/config/packages/account_locale.yaml`: English fallback, supported locale labels, locale service arguments, Twig globals.
- `resources/application/src/Locale/SupportedLocales.php`: allowlist and deterministic locale resolution.
- `resources/application/src/EventSubscriber/LocaleSubscriber.php`: post-firewall request locale application through `LocaleSwitcher`.
- `resources/application/src/Controller/LocaleController.php`: CSRF validation, supported-locale validation, persistence, cookie, safe redirect.
- `resources/application/templates/components/LanguageSwitcher.html.twig`: no-JavaScript selector shared by every layout.
- `resources/application/src/Entity/User.php`: persisted locale preference.
- `resources/application/translations/messages.{en,fr}.xlf`: UI and mail copy.
- `resources/application/translations/validators.{en,fr}.xlf`: application validation messages.
- `resources/application/src/Mailer/AccountMailer.php`: recipient-locale subjects and template rendering.
- `resources/application/tests/Functional/LocalePreferenceTest.php`: generated end-to-end locale behavior.
- `tests/Starter/Resources/LocaleResourcesTest.php`: package-level configuration, catalogue parity, and hardcoded-copy checks.

---

### Task 1: Generate Locale Configuration, Resolution, and User Persistence

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `src/Starter/Composer/RuntimeDependencySet.php`
- Modify: `tests/Starter/Composer/RuntimeDependencySetTest.php`
- Create: `resources/application/config/packages/account_locale.yaml`
- Create: `resources/application/src/Locale/SupportedLocales.php`
- Create: `resources/application/src/EventSubscriber/LocaleSubscriber.php`
- Modify: `resources/application/src/Entity/User.php`
- Modify: `resources/application/src/Account/RegistrationService.php`
- Modify: `resources/application/src/Controller/RegistrationController.php`
- Create: `tests/Starter/Resources/LocaleResourcesTest.php`
- Modify: `tests/Starter/Resources/PersistenceResourcesTest.php`
- Modify: `tests/Starter/Resources/PublicAccountResourcesTest.php`

**Interfaces:**
- Produces: `SupportedLocales::__construct(array<string,string> $labels, string $defaultLocale)`.
- Produces: `SupportedLocales::supports(string $locale): bool`, `codes(): list<string>`, `labels(): array<string,string>`, `default(): string`, and `resolve(Request $request, ?User $user): string`.
- Produces: `User::getLocale(): string` and `User::setLocale(string $locale): self` backed by `VARCHAR(5) NOT NULL DEFAULT 'en'`.
- Changes: `RegistrationService::register(string $name, string $email, string $plainPassword, string $locale): ?User`.
- Consumes later: `%app.supported_locales%`, `SupportedLocales`, and the persisted user locale.

- [ ] **Step 1: Write failing dependency and locale-resource tests**

Add to `RuntimeDependencySetTest`:

```php
self::assertContains('ext-intl:*', RuntimeDependencySet::runtime());
```

Create `LocaleResourcesTest` with tests that assert:

```php
$config = Yaml::parseFile($this->applicationResources().'/config/packages/account_locale.yaml');
self::assertSame('en', $config['framework']['default_locale']);
self::assertSame(['en'], $config['framework']['translator']['fallbacks']);
self::assertSame(['en' => 'English', 'fr' => 'Français'], $config['parameters']['app.supported_locales']);

$source = $this->resource('src/EventSubscriber/LocaleSubscriber.php');
self::assertStringContainsString("KernelEvents::REQUEST => ['onKernelRequest', 0]", $source);
self::assertStringContainsString('$event->isMainRequest()', $source);
self::assertStringContainsString('$request->setLocale($locale)', $source);
self::assertStringContainsString('$this->localeSwitcher->setLocale($locale)', $source);
```

Add a real resolver test by requiring generated `User.php` and `SupportedLocales.php` under the existing isolated generated-class loader, then assert:

```php
$locales = new $supportedLocalesClass(['en' => 'English', 'fr' => 'Français'], 'en');
$request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9,en;q=0.8']);
self::assertSame('fr', $locales->resolve($request, null));
$request->cookies->set('app_locale', 'en');
self::assertSame('en', $locales->resolve($request, null));
$user = (new $userClass())->setLocale('fr');
self::assertSame('fr', $locales->resolve($request, $user));
$request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE']);
self::assertSame('en', $locales->resolve($request, null));
```

In `PersistenceResourcesTest`, assert Doctrine metadata for `locale` has type `string`, length `5`, and default `en`. In `PublicAccountResourcesTest`, assert registration accepts `$locale`, applies it through `SupportedLocales`, and the controller passes `$request->getLocale()`.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
php vendor/bin/phpunit tests/Starter/Composer/RuntimeDependencySetTest.php tests/Starter/Resources/LocaleResourcesTest.php tests/Starter/Resources/PersistenceResourcesTest.php tests/Starter/Resources/PublicAccountResourcesTest.php
```

Expected: FAIL because `ext-intl`, locale resources, `User.locale`, and the registration argument do not exist.

- [ ] **Step 3: Add the generated dependency and maintainer test dependency**

Add `ext-intl:*` to `RuntimeDependencySet::runtime()`. Add `symfony/translation:^8.1` to root `require-dev` so package-level runtime tests can load `LocaleSwitcher`, then refresh only the necessary lock entries:

```bash
composer update symfony/translation --with-all-dependencies --no-interaction
```

- [ ] **Step 4: Implement locale configuration and resolution**

Create `account_locale.yaml`:

```yaml
parameters:
    app.name: 'Application'
    app.supported_locales:
        en: 'English'
        fr: 'Français'

framework:
    default_locale: en
    translator:
        fallbacks: ['en']

services:
    App\Locale\SupportedLocales:
        arguments:
            $labels: '%app.supported_locales%'
            $defaultLocale: '%kernel.default_locale%'

twig:
    globals:
        app_name: '%app.name%'
        supported_locales: '%app.supported_locales%'
```

Implement `SupportedLocales`:

```php
final readonly class SupportedLocales
{
    /** @param array<string, string> $labels */
    public function __construct(private array $labels, private string $defaultLocale)
    {
        if (!isset($labels[$defaultLocale])) {
            throw new \InvalidArgumentException('The default locale must be supported.');
        }
    }

    public function supports(string $locale): bool
    {
        return isset($this->labels[$locale]);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->labels);
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return $this->labels;
    }

    public function default(): string
    {
        return $this->defaultLocale;
    }

    public function resolve(Request $request, ?User $user): string
    {
        if (null !== $user && $this->supports($user->getLocale())) {
            return $user->getLocale();
        }

        $cookie = $request->cookies->getString('app_locale');
        if ($this->supports($cookie)) {
            return $cookie;
        }

        $preferred = $request->getPreferredLanguage($this->codes());

        return null !== $preferred && $this->supports($preferred) ? $preferred : $this->defaultLocale;
    }
}
```

Implement `LocaleSubscriber` at priority `0`, after the security firewall and
Symfony's earlier `LocaleListener`, but before the existing verified-account
subscriber at `-10`. This ordering is intentional: the persisted user is not
available before the firewall, so setting only `Request::setLocale()` would be
too late. Ignore subrequests, narrow the security user to generated `User`,
resolve the locale, then call both `Request::setLocale()` and
`LocaleSwitcher::setLocale()` so every locale-aware service is updated.

- [ ] **Step 5: Persist locale on User and registration**

Add to `User`:

```php
#[ORM\Column(length: 5, options: ['default' => 'en'])]
private string $locale = 'en';

public function getLocale(): string
{
    return $this->locale;
}

public function setLocale(string $locale): self
{
    $this->locale = $locale;

    return $this;
}
```

Inject `SupportedLocales` into `RegistrationService`, add `$locale` to `register()`, and initialize the new user with:

```php
->setLocale($this->locales->supports($locale) ? $locale : $this->locales->default())
```

Pass `$request->getLocale()` from `RegistrationController`.

- [ ] **Step 6: Run focused and complete package tests**

Run the focused command from Step 2, then:

```bash
make test
```

Expected: PASS; the complete package test count increases and PHPStan/CS remain clean.

- [ ] **Step 7: Commit the locale foundation**

```bash
git add composer.json composer.lock src/Starter/Composer/RuntimeDependencySet.php tests/Starter/Composer/RuntimeDependencySetTest.php tests/Starter/Resources/LocaleResourcesTest.php tests/Starter/Resources/PersistenceResourcesTest.php tests/Starter/Resources/PublicAccountResourcesTest.php resources/application/config/packages/account_locale.yaml resources/application/src/Locale resources/application/src/EventSubscriber/LocaleSubscriber.php resources/application/src/Entity/User.php resources/application/src/Account/RegistrationService.php resources/application/src/Controller/RegistrationController.php
git commit -m "feat: generate persisted locale resolution"
```

---

### Task 2: Generate the Safe Language Selector

**Files:**
- Create: `resources/application/src/Controller/LocaleController.php`
- Create: `resources/application/templates/components/LanguageSwitcher.html.twig`
- Modify: `resources/application/templates/layout/public.html.twig`
- Modify: `resources/application/templates/layout/auth.html.twig`
- Modify: `resources/application/templates/layout/app.html.twig`
- Modify: `resources/application/src/EventSubscriber/VerifiedAccountSubscriber.php`
- Create: `resources/application/tests/Functional/LocalePreferenceTest.php`
- Modify: `resources/application/tests/Functional/AccountWebTestCase.php`
- Modify: `tests/Starter/Resources/LocaleResourcesTest.php`
- Modify: `tests/Starter/Resources/GeneratedTestsResourcesTest.php`
- Modify: `tests/Starter/Resources/PersistenceResourcesTest.php`
- Modify: `tests/Starter/Resources/UserInterfaceResourcesTest.php`

**Interfaces:**
- Produces: POST route `app_locale` at `/locale`.
- Produces: cookie `app_locale`, one-year lifetime, path `/`, HTTP-only, SameSite=Lax, secure on HTTPS.
- Produces: `<twig:LanguageSwitcher />` rendered exactly once by each generated layout.
- Changes: `AccountWebTestCase::createUser(string $email, bool $verified = true, string $password = 'password', string $locale = 'en'): User`.

- [ ] **Step 1: Write failing selector and functional resource tests**

In `LocaleResourcesTest`, assert the controller contains:

```php
self::assertStringContainsString("#[Route('/locale', name: 'app_locale', methods: ['POST'])]", $controller);
self::assertStringContainsString("new CsrfToken('locale_change'", $controller);
self::assertStringContainsString('Response::HTTP_UNPROCESSABLE_ENTITY', $controller);
self::assertStringContainsString("Cookie::create('app_locale')", $controller);
self::assertStringContainsString('Cookie::SAMESITE_LAX', $controller);
self::assertStringContainsString('str_starts_with($target, \'/\')', $controller);
self::assertStringContainsString("str_starts_with($target, '//')", $controller);
self::assertStringContainsString("str_contains($target, '\\\\')", $controller);
```

Assert all three layout files contain exactly one `<twig:LanguageSwitcher />`, and the component posts `locale`, `_token`, and `return_to` without a Stimulus controller.

Add `LocalePreferenceTest.php` to `GeneratedTestsResourcesTest`'s required workflow list. The generated test must cover:

```php
public function testBrowserCookieAndEnglishFallbackResolveInOrder(): void;
public function testAuthenticatedPreferenceOverridesCookieAndPersistsAChange(): void;
public function testLocaleChangeRejectsInvalidCsrfAndUnsupportedLocales(): void;
public function testLocaleChangeRejectsExternalAndBackslashReturnTargets(): void;
public function testRegistrationPersistsTheResolvedLocale(): void;
```

- [ ] **Step 2: Run focused tests and verify RED**

Run:

```bash
php vendor/bin/phpunit tests/Starter/Resources/LocaleResourcesTest.php tests/Starter/Resources/GeneratedTestsResourcesTest.php tests/Starter/Resources/PersistenceResourcesTest.php tests/Starter/Resources/UserInterfaceResourcesTest.php
```

Expected: FAIL because the route, component, layout integration, and generated functional test are missing.

- [ ] **Step 3: Implement the POST controller**

Inject `SupportedLocales`, `CsrfTokenManagerInterface`, `Security`, `UserRepository`, and `UrlGeneratorInterface`. Validate CSRF before reading locale. For unsupported locale, return a plain `Response` with status 422 and no cookie/write. Persist authenticated users with `setLocale()` and `UserRepository::save()`.

Build the redirect target with this exact safety policy:

```php
private function returnTarget(string $target, bool $authenticated): string
{
    if (str_starts_with($target, '/')
        && !str_starts_with($target, '//')
        && !str_contains($target, '\\')
        && 1 !== preg_match('/[\x00-\x1F\x7F]/', $target)) {
        return $target;
    }

    return $this->urlGenerator->generate($authenticated ? 'app_dashboard' : 'app_home');
}
```

Set the response cookie only after validation and persistence:

```php
$response->headers->setCookie(
    Cookie::create('app_locale')
        ->withValue($locale)
        ->withExpires(new \DateTimeImmutable('+1 year'))
        ->withPath('/')
        ->withSecure($request->isSecure())
        ->withHttpOnly(true)
        ->withSameSite(Cookie::SAMESITE_LAX),
);
```

- [ ] **Step 4: Implement the no-JavaScript component and layout placement**

The component renders one labelled `<select name="locale">` from the global `supported_locales`, marks `app.request.locale` selected, includes `return_to="{{ app.request.requestUri }}"`, includes `csrf_token('locale_change')`, and submits through a translated Apply button. Render it in the public, auth, and app headers without adding a controller under `assets/controllers/`.

Add `'app_locale'` to the exact allowlist in `VerifiedAccountSubscriber` so an authenticated unverified user can change language.

- [ ] **Step 5: Implement the generated locale functional test**

Update `createUser()` to accept and apply the fourth `$locale` argument. In `LocalePreferenceTest`, use `KernelBrowser::setServerParameter('HTTP_ACCEPT_LANGUAGE', ...)`, the cookie jar, `loginUser()`, and a fresh Doctrine reload to prove the five method contracts from Step 1. Select the locale form from every layout using `form[action="'.$this->url('app_locale').'"]`.

For mutation failures, snapshot the user locale and cookie before the POST and assert both remain unchanged. For unsafe return targets, submit `https://evil.example`, `//evil.example`, and `/\\evil.example`; assert the response redirects only to the generated home/dashboard route.

- [ ] **Step 6: Run focused tests and `make test`**

Run the focused command from Step 2, then `make test`.

Expected: PASS; exactly two custom Stimulus controllers remain.

- [ ] **Step 7: Commit the selector**

```bash
git add resources/application/src/Controller/LocaleController.php resources/application/src/EventSubscriber/VerifiedAccountSubscriber.php resources/application/templates/components/LanguageSwitcher.html.twig resources/application/templates/layout resources/application/tests/Functional/LocalePreferenceTest.php resources/application/tests/Functional/AccountWebTestCase.php tests/Starter/Resources
git commit -m "feat: generate persistent language selector"
```

---

### Task 3: Replace Every Visible Twig String with English and French Keys

**Files:**
- Modify: `resources/application/translations/messages.en.xlf`
- Create: `resources/application/translations/messages.fr.xlf`
- Create: `resources/application/translations/validators.en.xlf`
- Create: `resources/application/translations/validators.fr.xlf`
- Modify: all files under `resources/application/templates/layout/`, `home/`, `dashboard/`, `account/`, `security/`, `password/`, `registration/`, and `email/`
- Modify: `resources/application/templates/base.html.twig`
- Modify: `src/Starter/Installation/ApplicationValidator.php`
- Modify: `tests/Starter/Installation/ApplicationValidatorTest.php`
- Modify: `tests/Starter/Resources/LocaleResourcesTest.php`
- Modify: `tests/Starter/Resources/UserInterfaceResourcesTest.php`

**Interfaces:**
- Produces: key-identical `messages.en.xlf` and `messages.fr.xlf`.
- Produces: key-identical `validators.en.xlf` and `validators.fr.xlf`.
- Produces: `lint:xliff translations --no-interaction` as an installation validation phase.

- [ ] **Step 1: Write failing catalogue parity and retired-copy tests**

Parse XLIFF with `DOMDocument`/`DOMXPath` using `local-name()` selectors and compare sorted `<source>` IDs:

```php
self::assertSame($this->translationIds('messages.en.xlf'), $this->translationIds('messages.fr.xlf'));
self::assertSame($this->translationIds('validators.en.xlf'), $this->translationIds('validators.fr.xlf'));
```

Scan generated Twig files and assert none contains the retired literal list:

```php
private const array RETIRED_VISIBLE_ENGLISH = [
    'Application · Welcome',
    'Dashboard · Application',
    'Account settings · Application',
    'Profile · Application',
    'Delete account · Application',
    'A thoughtful Symfony starting point',
    'Your account foundation is ready.',
    'Create an account or sign in to continue to the application.',
    'Open dashboard',
    'This is your authenticated application shell.',
    'Your workspace is ready',
    'There is nothing here yet.',
    'Open account settings',
    'Manage your identity, sign-in details, and account access.',
    'Account details',
    '>Name<',
    '>Email address<',
    '>Pending email<',
    '>Dashboard<',
    '>Account<',
    '>Sign in<',
    '>Create account<',
    '>Profile<',
    '>Password<',
    '>Delete account<',
    'Update your name or request an email change.',
    'Edit profile',
    'Choose a new password for your account.',
    'Permanently remove your account and its data.',
    'Permanently remove {{ account.email }} and its account data.',
    'A confirmation link is sent before your email address changes.',
    'Save profile',
    '>Cancel<',
    'Delete your account permanently?',
    'This action cannot be undone.',
    'Keep account',
    'Return to sign in',
    'Back home',
    '>Sign out<',
    'Primary navigation',
    'Account navigation',
];
```

Add an expected `Tailwind build`-adjacent validation entry for:

```php
['php', 'bin/console', 'lint:xliff', 'translations', '--no-interaction']
```

- [ ] **Step 2: Run tests and verify RED**

Run:

```bash
php vendor/bin/phpunit tests/Starter/Resources/LocaleResourcesTest.php tests/Starter/Resources/UserInterfaceResourcesTest.php tests/Starter/Installation/ApplicationValidatorTest.php
```

Expected: FAIL because French/validator catalogues are missing, English literals remain, and XLIFF lint is absent.

- [ ] **Step 3: Build complete English/French message catalogues**

Preserve existing English IDs except `form.password_mismatch` and
`form.delete_confirmation_required`, which move to the `validators` domain.
Add or update targets using this exact bilingual inventory
(`id: English | French`):

```text
navigation.primary: Primary navigation | Navigation principale
navigation.account: Account navigation | Navigation du compte
navigation.dashboard: Dashboard | Tableau de bord
navigation.account_settings: Account | Compte
navigation.sign_in: Sign in | Se connecter
navigation.register: Create account | Créer un compte
navigation.sign_out: Sign out | Se déconnecter
navigation.back_home: Back home | Retour à l'accueil
locale.label: Language | Langue
locale.apply: Apply | Appliquer
home.title: %app_name% · Welcome | %app_name% · Bienvenue
home.eyebrow: A thoughtful Symfony starting point | Un point de départ Symfony bien pensé
home.heading: Your account foundation is ready. | La base de votre compte est prête.
home.copy: Create an account or sign in to continue to the application. | Créez un compte ou connectez-vous pour accéder à l'application.
home.open_dashboard: Open dashboard | Ouvrir le tableau de bord
dashboard.title: Dashboard · %app_name% | Tableau de bord · %app_name%
dashboard.heading: Dashboard | Tableau de bord
dashboard.copy: This is your authenticated application shell. Replace this starting point with the work that matters to your users. | Voici l'espace authentifié de votre application. Remplacez ce point de départ par ce qui compte pour vos utilisateurs.
dashboard.empty_title: Your workspace is ready | Votre espace de travail est prêt
dashboard.empty_copy: There is nothing here yet. Account settings are available whenever you need them. | Il n'y a encore rien ici. Les paramètres du compte restent disponibles à tout moment.
dashboard.account_action: Open account settings | Ouvrir les paramètres du compte
account.page_title: Account settings · %app_name% | Paramètres du compte · %app_name%
account.title: Account settings | Paramètres du compte
account.copy: Manage your identity, sign-in details, and account access. | Gérez votre identité, vos informations de connexion et l'accès à votre compte.
account.details: Account details | Informations du compte
account.active_email: Email address | Adresse e-mail
account.pending_email: Pending email | Adresse e-mail en attente
account.navigation: Account settings | Paramètres du compte
account.profile_title: Profile | Profil
account.profile_copy: Update your name or request an email change. | Modifiez votre nom ou demandez un changement d'adresse e-mail.
account.profile_action: Edit profile | Modifier le profil
account.password_title: Password | Mot de passe
account.password_copy: Choose a new password for your account. | Choisissez un nouveau mot de passe pour votre compte.
account.password_action: Change password | Modifier le mot de passe
account.delete_title: Delete account | Supprimer le compte
account.delete_copy: Permanently remove your account and its data. | Supprimez définitivement votre compte et ses données.
account.delete_page_title: Delete account · %app_name% | Supprimer le compte · %app_name%
account.delete_description: Permanently remove %email% and its account data. | Supprimez définitivement %email% et les données de ce compte.
account.delete_confirm_title: Delete your account permanently? | Supprimer définitivement votre compte ?
account.delete_confirm_copy: This action cannot be undone. Your signed-in session will end immediately. | Cette action est irréversible. Votre session prendra fin immédiatement.
account.delete_cancel: Keep account | Conserver le compte
account.delete_submit: Delete account | Supprimer le compte
account.deletion_failed: Your account could not be deleted. | Votre compte n'a pas pu être supprimé.
profile.page_title: Profile · %app_name% | Profil · %app_name%
profile.title: Profile | Profil
profile.copy: Update your name or request a change to %email%. | Modifiez votre nom ou demandez le changement de %email%.
profile.email_help: A confirmation link is sent before your email address changes. | Un lien de confirmation est envoyé avant la modification de votre adresse e-mail.
profile.change_failed: Your email address could not be changed. | Votre adresse e-mail n'a pas pu être modifiée.
profile.submit: Save profile | Enregistrer le profil
common.cancel: Cancel | Annuler
check_email.return_login: Return to sign in | Retour à la connexion
```

Translate every pre-existing ID as well. Required French targets include:

```text
form.name: Nom
form.email: Adresse e-mail
form.password: Mot de passe
form.password_confirmation: Confirmer le mot de passe
form.new_email: Nouvelle adresse e-mail
form.current_password: Mot de passe actuel
form.new_password: Nouveau mot de passe
form.delete_confirmation: Je comprends que mon compte sera définitivement supprimé.
form.error_summary: Veuillez corriger les erreurs ci-dessous.
form.submitting: Envoi en cours
password.show: Afficher le mot de passe
password.hide: Masquer le mot de passe
registration.title: Créer votre compte
registration.status: Saisissez vos informations pour vous inscrire.
registration.submit: S'inscrire
login.title: Se connecter
login.failed: Connexion impossible avec ces identifiants.
login.submit: Se connecter
login.forgot_password: Mot de passe oublié ?
login.magic_link_title: Recevoir un lien de connexion par e-mail
login.magic_link_submit: Envoyer le lien de connexion
login_link.title: Confirmer la connexion
login_link.status: Continuez pour vous connecter en toute sécurité.
login_link.submit: Continuer
check_email.title: Consultez votre messagerie
check_email.status: Si un compte éligible existe, un e-mail a été envoyé.
check_email.resend: Renvoyer l'e-mail de vérification
password_request.title: Réinitialiser votre mot de passe
password_request.status: Saisissez votre adresse e-mail pour demander un lien de réinitialisation.
password_request.submit: Envoyer le lien de réinitialisation
password_reset.title: Choisir un nouveau mot de passe
password_reset.failed: Ce lien de réinitialisation est invalide ou a expiré.
password_reset.submit: Enregistrer le mot de passe
password_change.title: Modifier votre mot de passe
password_change.failed: Votre mot de passe n'a pas pu être modifié.
password_change.submit: Modifier le mot de passe
email.verification.subject: Vérifiez votre adresse e-mail
email.verification.heading: Vérifiez votre adresse e-mail
email.verification.copy: Utilisez le lien ci-dessous pour vérifier votre compte.
email.verification.action: Vérifier l'adresse e-mail
email.magic_link.subject: Votre lien de connexion
email.magic_link.heading: Connectez-vous à votre compte
email.magic_link.copy: Utilisez le lien sécurisé ci-dessous pour vous connecter.
email.magic_link.action: Se connecter
email.password_reset.subject: Réinitialisez votre mot de passe
email.password_reset.heading: Réinitialisez votre mot de passe
email.password_reset.copy: Utilisez le lien sécurisé ci-dessous pour choisir un nouveau mot de passe.
email.password_reset.action: Réinitialiser le mot de passe
email.email_change.subject: Confirmez votre nouvelle adresse e-mail
email.email_change.heading: Confirmez votre nouvelle adresse e-mail
email.email_change.copy: Utilisez le lien sécurisé ci-dessous pour confirmer cette adresse e-mail.
email.email_change.action: Confirmer l'adresse e-mail
```

Create validator catalogues with identical keys:

```text
password.mismatch: The password fields must match. | Les champs de mot de passe doivent correspondre.
account.delete_confirmation_required: You must confirm account deletion. | Vous devez confirmer la suppression du compte.
```

- [ ] **Step 4: Replace Twig copy with keys**

Use `|trans` for every visible string and ARIA label. Use parameters exactly as follows:

```twig
{% block title %}{{ 'home.title'|trans({'%app_name%': app_name}) }}{% endblock %}
{{ 'profile.copy'|trans({'%email%': account.email}) }}
{{ 'account.delete_description'|trans({'%email%': account.email}) }}
```

Render the brand as `{{ app_name }}` rather than a translation. Replace all literals in the files listed for this task, including the language selector label/action and `aria-label` attributes. Flash messages remain `{{ message }}` because controllers supply translation keys in Task 4; render them as `{{ message|trans }}`.

- [ ] **Step 5: Add XLIFF validation to installation**

Add the `lint:xliff` command to `ApplicationValidator::VALIDATIONS` after YAML and before Twig. Extend `ApplicationValidatorTest` to assert the exact phase and command and to prove stdout/stderr are preserved on failure.

- [ ] **Step 6: Run focused and full package gates**

Run the focused command from Step 2, then `make test`.

Expected: PASS; English/French key sets are identical and no retired visible literal remains in Twig.

- [ ] **Step 7: Commit translated templates and catalogues**

```bash
git add resources/application/translations resources/application/templates src/Starter/Installation/ApplicationValidator.php tests/Starter/Installation/ApplicationValidatorTest.php tests/Starter/Resources/LocaleResourcesTest.php tests/Starter/Resources/UserInterfaceResourcesTest.php
git commit -m "feat: translate generated interface in English and French"
```

---

### Task 4: Translate Validation, Controller Errors, and Recipient-Locale Email

**Files:**
- Modify: `resources/application/src/Mailer/AccountMailer.php`
- Modify: `resources/application/src/Form/RegistrationType.php`
- Modify: `resources/application/src/Form/PasswordChangeType.php`
- Modify: `resources/application/src/Form/ResetPasswordType.php`
- Modify: `resources/application/src/Form/Model/DeleteAccountInput.php`
- Modify: `resources/application/src/Controller/ProfileController.php`
- Modify: `resources/application/src/Controller/PasswordController.php`
- Modify: `resources/application/src/Controller/DeleteAccountController.php`
- Modify: `resources/application/tests/Functional/LocalePreferenceTest.php`
- Create: `tests/Starter/Resources/TranslatedMailerRuntimeTest.php`
- Modify: `tests/Starter/Resources/PublicAccountResourcesTest.php`
- Modify: `tests/Starter/Resources/SelfAdministrationDoctrineTest.php`

**Interfaces:**
- Changes: `AccountMailer` constructor adds `TranslatorInterface $translator` before editable sender defaults.
- Changes: private mail send path accepts a locale and subject key, calls `TranslatorInterface::trans($subjectKey, locale: $locale)`, and calls `TemplatedEmail::locale($locale)`.
- Produces: French form/constraint/controller errors through catalogue keys.

- [ ] **Step 1: Write failing translated-mail and error-key tests**

Create `TranslatedMailerRuntimeTest` that loads generated `User` and `AccountMailer`, uses a real Symfony `Translator('en')` with `XliffFileLoader`, and a recording `MailerInterface`. Send verification for a user whose locale is `fr`, then assert:

```php
self::assertSame('Vérifiez votre adresse e-mail', $email->getSubject());
self::assertSame('fr', $email->getLocale());
self::assertSame('email/verify.html.twig', $email->getHtmlTemplate());
self::assertSame(['absolute_url' => 'https://example.test/verify'], $email->getContext());
```

In `PublicAccountResourcesTest`, assert all three repeated password types use `'invalid_message' => 'password.mismatch'`, `DeleteAccountInput` uses `account.delete_confirmation_required`, and controller-added `FormError` instances use catalogue keys rather than English sentences.

Update constructor helpers in `SelfAdministrationDoctrineTest` to pass a translator stub to `AccountMailer`.

- [ ] **Step 2: Run focused tests and verify RED**

Run:

```bash
php vendor/bin/phpunit tests/Starter/Resources/TranslatedMailerRuntimeTest.php tests/Starter/Resources/PublicAccountResourcesTest.php tests/Starter/Resources/SelfAdministrationDoctrineTest.php
```

Expected: FAIL because subjects are literal English, locale is unset, error strings are literal, and constructor helpers lack the translator.

- [ ] **Step 3: Implement recipient-locale mail**

Refactor the mailer around this private boundary:

```php
private function send(
    string $recipient,
    string $locale,
    string $subjectKey,
    string $template,
    string $absoluteUrl,
): void {
    $email = (new TemplatedEmail())
        ->from(new Address($this->fromEmail, $this->fromName))
        ->to($recipient)
        ->subject($this->translator->trans($subjectKey, locale: $locale))
        ->htmlTemplate($template)
        ->locale($locale)
        ->context(['absolute_url' => $absoluteUrl])
    ;

    $this->mailer->send($email);
}
```

Each public mail method passes `$user->getLocale()` and its existing `email.*.subject` key. Keep entity objects out of template context.

- [ ] **Step 4: Replace rendered PHP error strings with keys**

Use `password.mismatch` in the three repeated-password form types. Use `account.delete_confirmation_required` in the `Assert\IsTrue` attribute. Replace controller `FormError` literals with the existing `profile.change_failed`, `password_change.failed`, and `account.deletion_failed` message keys. Do not translate internal exceptions or security log messages that never render.

- [ ] **Step 5: Extend generated French rendering tests**

In `LocalePreferenceTest`, register with `Accept-Language: fr`, then assert:

```php
self::assertSame('fr', $user->getLocale());
self::assertEmailCount(1);
$email = self::getMailerMessage();
self::assertSame('Vérifiez votre adresse e-mail', $email->getSubject());
self::assertStringContainsString('Vérifiez votre adresse e-mail', (string) $email->getHtmlBody());
```

Submit mismatched registration passwords in French and assert the rendered response contains `Les champs de mot de passe doivent correspondre.` rather than the English message.

- [ ] **Step 6: Run focused tests and `make test`**

Run the focused command from Step 2, then `make test`.

Expected: PASS; mail locale and translated errors are proven with real generated classes/catalogues.

- [ ] **Step 7: Commit translated PHP behavior**

```bash
git add resources/application/src/Mailer/AccountMailer.php resources/application/src/Form resources/application/src/Controller resources/application/tests/Functional/LocalePreferenceTest.php tests/Starter/Resources/TranslatedMailerRuntimeTest.php tests/Starter/Resources/PublicAccountResourcesTest.php tests/Starter/Resources/SelfAdministrationDoctrineTest.php
git commit -m "feat: localize generated mail and validation"
```

---

### Task 5: Document Extensibility and Prove Both Fresh Applications

**Files:**
- Modify: `README.md`
- Modify: `docs/customization.md`
- Modify: `docs/security.md`
- Modify: `scripts/smoke-test.sh`
- Modify: `tests/Starter/Smoke/SmokeScriptTest.php`
- Modify: `tests/Starter/Package/PackageSurfaceTest.php`

**Interfaces:**
- Documents: English/French resolution order, `User.locale`, cookie behavior, and adding another locale.
- Proves: generated package removal precedes database/migration/application tests and no runtime Te4g reference remains.

- [ ] **Step 1: Write failing documentation and smoke-policy assertions**

Add assertions that README/customization mention `ext-intl`, `User.locale`, `app_locale`, `Accept-Language`, `messages.fr.xlf`, `validators.fr.xlf`, and `%app.supported_locales%`/`app.supported_locales`.

Update `SmokeScriptTest` to require post-removal assertions for:

```text
config/packages/account_locale.yaml
src/Locale/SupportedLocales.php
src/EventSubscriber/LocaleSubscriber.php
src/Controller/LocaleController.php
templates/components/LanguageSwitcher.html.twig
translations/messages.fr.xlf
translations/validators.fr.xlf
tests/Functional/LocalePreferenceTest.php
```

- [ ] **Step 2: Run focused tests and verify RED**

Run:

```bash
php vendor/bin/phpunit tests/Starter/Package/PackageSurfaceTest.php tests/Starter/Smoke/SmokeScriptTest.php
```

Expected: FAIL because documentation and smoke inventory do not mention locale support.

- [ ] **Step 3: Document the generated locale contract**

README requirements state `ext-intl`. The ownership section states that generated English/French catalogues and preference code are editable application code.

Customization documents this extension sequence exactly:

1. add the locale code and endonym to `app.supported_locales` in `config/packages/account_locale.yaml`;
2. add matching `messages.<locale>.xlf` and `validators.<locale>.xlf` files;
3. keep every catalogue's source-key set identical;
4. extend generated `LocalePreferenceTest` with one representative page and email assertion;
5. run `php bin/console lint:xliff translations` and `php bin/console debug:translation <locale>`.

Security documents that the locale cookie contains only an allowlisted language code, is HTTP-only/SameSite=Lax, and is lower precedence than the authenticated database preference.

- [ ] **Step 4: Extend smoke inventory and syntax checks**

After package removal, assert all Step 1 paths exist. Keep the existing no-Te4g scan. Add explicit `lint:xliff translations --no-interaction` to the generated-app validation phase even though the installer already ran it, so package removal cannot hide a dependency.

- [ ] **Step 5: Run the complete local quality gate**

Run:

```bash
composer validate --strict
make test
git diff --check
```

Expected: PASS; PHP-CS-Fixer, PHPStan, all starter tests, Composer validation, and whitespace checks are clean.

- [ ] **Step 6: Run the full fresh-project acceptance gate once**

Run:

```bash
TE4G_TAILWIND_BINARY=/private/tmp/te4g-tailwindcss-macos-arm64-v4.3.3 make smoke
```

Expected: PASS for both skeleton and webapp. Each project installs without database access, removes `te4g/starter`, generates one migration containing `User.locale`, migrates SQLite, lints English/French XLIFF, builds assets, and passes the generated functional suite including locale selection and French email.

- [ ] **Step 7: Commit documentation and acceptance coverage**

```bash
git add README.md docs/customization.md docs/security.md scripts/smoke-test.sh tests/Starter/Smoke/SmokeScriptTest.php tests/Starter/Package/PackageSurfaceTest.php
git commit -m "docs: explain generated locale customization"
```

---

## Completion Gate

Before pushing or creating the PR, run from the feature worktree:

```bash
git status --short
composer validate --strict
make test
TE4G_TAILWIND_BINARY=/private/tmp/te4g-tailwindcss-macos-arm64-v4.3.3 make smoke
rg -n "Te4g\\\\|te4g/starter" resources/application --glob '!STARTER_LICENSES.md'
```

Expected: clean worktree; Composer and the complete quality gate pass; both fresh variants pass after package removal; the final scan returns no runtime starter reference outside the required generated notice.

## Authoritative Symfony References

- Translation configuration, locale resolution, `getPreferredLanguage()`, and `LocaleSwitcher`: <https://symfony.com/doc/current/translation.html>
- Per-message and queued template locale through `TemplatedEmail::locale()`: <https://symfony.com/doc/current/mailer.html>
