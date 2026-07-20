# Te4g User Bundle Design

## Goal

Build `te4g/user-bundle`, an opinionated Symfony 8.1 bundle that removes the
repeated work of adding a complete user account lifecycle to Te4g's new Symfony
applications.

After installing the Composer package, a developer runs one safe command to
generate the application-owned persistence and user interface. The runtime
bundle continues to own and update the security-sensitive workflows.

The first release is optimized for Te4g's own new applications. It is not a
general-purpose replacement for every possible Symfony user system.

## Success Criteria

In a fresh Symfony web application, the complete setup is:

1. Run `composer require te4g/user-bundle`.
2. Run `php bin/console te4g:user:install`.
3. Review the generated Doctrine migration and run it explicitly.
4. Configure `MAILER_DSN` and the production router base URL.
5. Use registration, email verification, password login, magic-link login,
   password recovery, account self-administration, and account deletion without
   writing account code manually.

The installation command must be safe to run again, must never silently replace
application-owned code, and must not execute a database migration.

## Package Identity and Compatibility

- Composer package: `te4g/user-bundle`
- PHP namespace: `Te4g\UserBundle`
- Bundle class: `Te4g\UserBundle\Te4gUserBundle`
- Configuration alias: `te4g_user`
- Installation command: `te4g:user:install`
- PHP: `^8.4`
- Symfony: `^8.1`
- Persistence: Doctrine ORM
- Templates and forms: Twig and Symfony Form
- Assets: AssetMapper and SymfonyCasts TailwindBundle
- UX: Symfony UX TwigComponent, Turbo, Stimulus, and UX Icons
- UI kit: Symfony UX Toolkit Shadcn kit

The package keeps the repository's `GPL-3.0-only` license. Copied Symfony UX
Toolkit recipes retain their upstream license notices in a third-party notices
file.

## Architectural Decision

The implementation is a contract-first runtime bundle plus an application
installer.

The runtime bundle owns:

- Account controllers and stable route names.
- Symfony forms for the standard account fields.
- Registration, verification, password, magic-link, profile, and deletion
  workflow services.
- Mail construction and delivery.
- Rate-limit integration and generic security responses.
- Public contracts, events, guards, and service decoration points.
- English fallback translations and fallback email templates.

The application owns:

- `App\Entity\User`.
- `App\Repository\UserRepository`.
- The reset-password request entity and repository.
- Doctrine migrations.
- Security, route, Twig, reset-password, and verification configuration files.
- Installed shadcn components, account pages, form theme, Stimulus controllers,
  Tailwind source, and translations.

The bundle must not map or own a user table. It must not require an application
entity to extend a bundle entity or mapped superclass.

## Main Runtime Components

Controllers remain thin and delegate to the following focused services:

- `RegistrationManager`: creates, hashes, persists, and starts verification for
  a new account.
- `EmailVerificationManager`: creates and validates verification links and
  marks an account verified.
- `MagicLinkManager`: accepts neutral login-link requests and sends native
  Symfony login links for eligible users.
- `PasswordResetManager`: wraps reset request creation, validation, cleanup, and
  password replacement.
- `ProfileManager`: manages pending email changes and their confirmation.
- `PasswordManager`: verifies the current password, writes a new hash, and
  invalidates outstanding credentials.
- `AccountDeletionManager`: runs deletion guards, removes the user, dispatches
  lifecycle events, and clears authentication.
- `AccountMailer`: creates and sends all account emails through Symfony Mailer.

Each unit has one public responsibility. Controllers perform HTTP/form work;
managers enforce lifecycle rules; repositories persist; mail services deliver;
events and guards let the application add domain behavior.

## Application Contracts

### Account User

The generated `App\Entity\User` implements
`Te4g\UserBundle\Contract\AccountUserInterface`. The contract extends
Symfony's `UserInterface` and `PasswordAuthenticatedUserInterface` and exposes
only the properties required by the workflows:

- stable identifier access for signed links;
- email read/write access;
- pending-email read/write access;
- hashed-password read/write access;
- roles;
- verified-state read/write access.

The contract does not require a specific Doctrine identifier type. The default
generated entity uses an auto-increment integer identifier, and an application
may change that mapping before creating its first migration.

The generated entity contains:

- `id`;
- unique, normalized `email`;
- nullable `pendingEmail`;
- `roles`;
- `password`;
- `isVerified`.

Email input is trimmed and canonicalized to lowercase before lookup or
persistence. Plain-text passwords never live on the entity. The entity's
session serialization includes a safe derivative of the password hash so that
a password change invalidates existing sessions without serializing the real
hash.

### Repository

The generated repository implements
`Te4g\UserBundle\Contract\AccountRepositoryInterface` with operations to:

- find an account by normalized email;
- find an account by stable identifier;
- save an account;
- remove an account.

The repository also implements Symfony's password-upgrader contract. Runtime
services depend on the bundle contract, never on an `App\...` class.

### User Factory

The default bundle factory instantiates the configured `user_class` through a
public no-argument constructor and verifies the resulting contract. An
application with different construction rules replaces
`AccountUserFactoryInterface` with its own service.

### Reset Request Persistence

The installer generates the entity and repository expected by
SymfonyCasts ResetPasswordBundle. These files belong to the application because
the request entity references its `User` entity. The bundle does not add a
second user store or a bundle-owned token table.

## Dependencies and Reused Security Mechanisms

The package depends on the Symfony 8.1 components and bundles needed for
Security, Form, Validator, Twig, Mailer, RateLimiter, AssetMapper, and the chosen
Symfony UX stack. It also composes:

- Symfony's native password hasher and form-login authenticator;
- Symfony's native login-link authenticator;
- SymfonyCasts VerifyEmailBundle for stateless signed verification links;
- SymfonyCasts ResetPasswordBundle for expiring, throttled reset requests;
- SymfonyCasts TailwindBundle for Node-free Tailwind builds with AssetMapper.

The bundle must not implement custom password hashing, signed-URL
cryptography, session authentication, or reset-token cryptography.

## Flex Recipe and Command Availability

`te4g:user:install` can only run after the bundle is enabled. A Symfony Flex
recipe is therefore part of v1 and registers `Te4gUserBundle` for all
environments when Composer installs the package. The recipe is prepared for the
Symfony recipes-contrib repository so a normal `composer require` provides the
promised installation flow.

The Flex recipe performs only the minimal bootstrap required to expose the
command. Application-specific files remain the responsibility of
`te4g:user:install`, where they can be preflighted and reported safely.

Local path-repository development may register the bundle manually in the
fixture application until the recipe is available.

## Installer Design

### Command Interface

The command supports:

- `te4g:user:install`: apply an installation after an interactive summary and
  confirmation;
- `te4g:user:install --dry-run`: show all planned changes without writing;
- `te4g:user:install --no-interaction`: apply only when every preflight passes
  and no overwrite decision is required.

There is no broad `--force` option in v1.

### Preflight

Before writing, the installer verifies:

- the host uses Symfony 8.1 and PHP 8.4 or newer;
- required bundles and components are available;
- Doctrine ORM, Twig, AssetMapper, ImportMap, and TailwindBundle are configured;
- a `main` firewall exists and has no incompatible provider or authenticator;
- target route names are unused;
- the target entity, repository, reset-request files, templates, components,
  and Stimulus controller names do not conflict;
- Tailwind and import-map files can be changed through recognized structures or
  generated marker regions;
- the container compiles before application-specific configuration is added.

If an application already has a user system, v1 stops and reports the conflict;
adopting arbitrary existing user models is outside the first release.

### Planned Writes

The installer generates or updates:

- `src/Entity/User.php`;
- `src/Repository/UserRepository.php`;
- the reset-password request entity and repository;
- `config/packages/te4g_user.yaml`;
- `config/packages/te4g_user_security.yaml`;
- `config/packages/te4g_user_twig.yaml`;
- ResetPasswordBundle and VerifyEmailBundle configuration;
- `config/routes/te4g_user.yaml`;
- application-owned bundle template overrides;
- required shadcn components and their transitive component files;
- a shadcn-compatible Symfony form theme;
- password-visibility and alert-dialog Stimulus controllers;
- Tailwind/shadcn imports and design tokens in `assets/styles/app.css`;
- required import-map packages;
- English translation overrides;
- `config/te4g_user.install.json`, containing the bundle version, generated
  paths, and original generated hashes.

The installer carries a versioned snapshot of only the official Symfony UX
Toolkit Shadcn recipes used by these pages. The exact upstream version or commit
and license are recorded when the snapshot is refreshed. UX Toolkit is not left
as a production dependency merely to support one-time generation.

### Write Safety and Rollback

All generated files are rendered to a temporary staging directory first. The
installer validates staged PHP, YAML, JSON, Twig, and container configuration
before moving files into place.

Existing files are changed only through a recognized, uniquely marked generated
region. Their original contents are retained until the installation succeeds.
If any write, import-map operation, Tailwind step, container check, or migration
generation fails, the command restores every file it changed and removes every
file it created.

On a repeat run:

- files matching their recorded generated hash are treated as installed;
- application-modified files are reported and left untouched;
- missing files are proposed for regeneration;
- no operation is performed when the installation is complete.

After the application files pass validation, the command invokes Doctrine's
migration diff generator. It records the created migration in its summary and
never executes it. The developer must inspect and run the migration explicitly.

## Configuration Authorities

Configuration is deliberately not mirrored between files.

`te4g_user.yaml` is authoritative for:

- `user_class`;
- `account_repository` service;
- mail sender address and display name;
- application route names used after successful login, verification, account
  updates, and deletion;
- account page and email template names;
- whether account deletion is enabled.

`te4g_user_security.yaml` is authoritative for the `main` firewall provider,
form login, logout, CSRF, login throttling, access control, and native magic-link
configuration.

The SymfonyCasts bundle configuration files are authoritative for verification
and reset lifetimes and the reset-request repository.

The route import is authoritative for a single optional prefix applied to all
bundle routes. Its default is an empty prefix.

## Routes and Pages

Stable route names use the `te4g_user_` prefix. Default paths are:

- `GET|POST /register` for registration;
- `GET /verify-email` for verification;
- `POST /verify-email/resend` for verification resend;
- `GET|POST /login` for password login and the magic-link request form;
- `POST /logout` for logout;
- `GET /login-link/confirm` for the human confirmation landing page;
- `POST /login-link/check` for the native login-link authenticator;
- `GET|POST /forgot-password` for reset requests;
- `GET|POST /reset-password/{token}` for password replacement;
- `GET /account` for the account overview;
- `GET|POST /account/profile` for profile and pending-email management;
- `GET|POST /account/password` for password changes;
- `POST /account/delete` for destructive deletion.

Public routes remain accessible anonymously. Account self-administration routes
require full authentication and a verified account.

## Account Lifecycle

### Registration and Verification

Registration accepts a normalized email and confirmed password. The manager
hashes the password, creates an unverified account, persists it, and sends a
verification link with a one-hour lifetime. The account is not authenticated
until verification succeeds.

The verification link supports anonymous confirmation across devices. It is
signed for the user identifier and current email. A valid link marks the user
verified and redirects to the configured login route.

A registration request for an existing email returns the same browser response
as a new registration. Database unique-constraint races are translated to that
same response.

### Password Login

Symfony form login authenticates by normalized email and password. CSRF and
login throttling are enabled. A user checker rejects unverified accounts while
Symfony's enumeration-resistant error setting keeps the public error generic.

### Magic-Link Login

The request endpoint always returns the same response regardless of whether an
account exists.

- A verified account receives a native Symfony login link.
- An unverified account receives a fresh verification message instead.
- An unknown address triggers no email.

Magic links expire after ten minutes, are single-use, and are signed against the
user identifier, email, and password hash so email or password changes
invalidate outstanding links. The authenticator accepts only POST requests.
The emailed GET URL first renders a confirmation page, preventing email-preview
scanners from consuming the credential; the user then submits the POST check.

### Forgotten Password and Password Change

Forgotten-password requests always return a neutral response. Reset requests
expire after one hour, are throttled for one hour per account, are stored using
the SymfonyCasts reset-request model, and are removed after successful use.

An authenticated password change requires the current password and confirmation
of the new password. A reset or change removes outstanding reset requests and
invalidates magic links and existing sessions.

### Profile and Email Change

The generic profile manages only email. Applications may replace or extend the
form for additional fields.

Changing email requires the current password. The new normalized value is saved
to `pendingEmail`; the existing verified email remains the login identifier. A
signed confirmation is sent to the pending address. Confirmation succeeds only
when the signed value still matches the latest pending address, then atomically
replaces `email`, clears `pendingEmail`, and invalidates reset and magic links.

### Account Deletion

Deletion is enabled by default, requires the current password and explicit
confirmation, and runs registered `AccountDeletionGuardInterface` services
before persistence changes. Guards may reject deletion with a safe user-facing
reason.

On success, the manager dispatches before/after lifecycle events, removes the
user through the application repository, invalidates authentication, and
redirects to the configured route. On persistence or guard failure, the user
remains authenticated and unchanged.

## User Interface

The installed account UI uses application-owned Symfony UX Toolkit Shadcn
components and Tailwind design tokens.

The initial primitive set is:

- Card;
- Button;
- Input and Field;
- Alert;
- Separator;
- Alert Dialog;
- Spinner;
- UX Icons with locked Lucide icons.

The installed pages are:

- combined password and magic-link login;
- registration;
- check-your-email and invalid/expired-link states;
- forgotten-password request and reset;
- account overview;
- email/profile editing;
- password change;
- destructive account deletion.

Twig Components render reusable, static UI. Turbo Drive handles normal
navigation and form submissions; invalid forms return HTTP 422. Stimulus is
limited to password visibility and the accessible account-deletion dialog. Live
Components are excluded because no v1 interaction needs server-reactive state.

All controls have explicit labels, keyboard focus styles, accessible error
association, and an `aria-live` status region. Pages are responsive and support
the shadcn light/dark CSS variables. All visible copy uses translation keys; the
bundle ships English fallback catalogues and applications may override or add
locales.

## Customization and Extension Points

Applications can customize without forking the runtime bundle by:

- editing any installed page, component, form theme, translation, or Tailwind
  token;
- overriding configured page and email template names;
- extending or replacing the registration and profile form types;
- replacing `AccountUserFactoryInterface`;
- implementing the repository contract with different internals;
- registering deletion guards;
- subscribing to registration, verification, email-change, password-change,
  and deletion lifecycle events;
- decorating workflow and mail services;
- changing route prefix and configured redirect route names.

Application-owned UI files are never automatically updated after local edits.
A future explicit UI update command may provide three-way comparisons, but it
is outside v1.

## Error Handling and Privacy

- Registration, reset, and magic-link endpoints do not reveal whether an email
  is registered.
- Invalid, expired, and consumed links render a generic recovery page with a
  safe resend action.
- Synchronous mail failures are logged. Persisted registrations and pending
  email changes remain recoverable through resend actions.
- When Symfony Mailer is routed through Messenger by the application, the same
  mail service works asynchronously without bundle-specific queue code.
- Logs never contain plain passwords, reset tokens, full signed URLs, or full
  magic links.
- Failed deletion leaves the account and session intact.
- Public forms have CSRF protection where applicable and request endpoints use
  Symfony RateLimiter.
- Token comparisons use the underlying Symfony and SymfonyCasts constant-time
  implementations.

## Testing Strategy

### Unit Tests

Cover each workflow manager, normalization policy, form rule, event, guard, and
error translation in isolation. Clocks, repositories, password hashing, mail,
and token helpers are replaced with deterministic fakes.

### Container and Persistence Integration Tests

A fixture Symfony application boots the real bundle and verifies configuration,
service aliases, route loading, forms, security listeners, Doctrine mappings,
unique email enforcement, reset cleanup, pending-email transitions, and
deletion rollback.

### Functional Tests

Symfony WebTestCase scenarios cover every page and complete lifecycle, including:

- registration and anonymous verification;
- unverified password and magic-link rejection;
- password and magic-link login;
- neutral responses for unknown and existing email addresses;
- CSRF and throttling;
- expired and single-use links;
- reset and password-change invalidation;
- pending email confirmation;
- current-password checks;
- deletion guards and successful logout after deletion.

Rendered-page assertions verify labels, error relationships, focusable controls,
HTTP 422 responses for invalid Turbo submissions, and expected shadcn component
markup.

### Installer Tests

Temporary fresh-app fixtures verify dry runs, successful installation, staged
validation, migration generation without execution, repeat-run idempotency,
name and firewall conflicts, modified application files, and complete rollback
after injected failures.

### Quality Gate

`make test` remains the single local gate and runs code style checks, PHPStan,
PHPUnit, Twig/YAML/container validation, Tailwind compilation, import-map audit,
and UX icon locking checks. CI runs the gate on supported PHP 8.4 and 8.5
environments with Symfony 8.1.

## Explicit Non-Goals for Version 1

- Supporting Symfony versions before 8.1.
- Supporting Webpack Encore, Vite, or a Node-based Tailwind pipeline.
- Adopting an arbitrary pre-existing application user entity or firewall.
- Bundle-owned user persistence or mapped-superclass inheritance.
- Usernames, social login, passkeys, MFA, teams, impersonation, admin user
  management, avatars, or profile fields beyond email.
- A public compatibility promise for unrelated third-party applications.
- Automatic execution of database migrations.
- Automatic replacement or merging of application-modified UI files.
- Live Components or a JavaScript SPA.

## Reference Implementations

- Symfony bundle guidance: <https://symfony.com/doc/current/bundles.html>
- Symfony Security: <https://symfony.com/doc/current/security.html>
- Symfony login links: <https://symfony.com/doc/current/security/login_link.html>
- SymfonyCasts ResetPasswordBundle:
  <https://github.com/SymfonyCasts/reset-password-bundle>
- SymfonyCasts VerifyEmailBundle:
  <https://github.com/SymfonyCasts/verify-email-bundle>
- Symfony UX Toolkit Shadcn kit:
  <https://ux.symfony.com/toolkit/kits/shadcn>
- SymfonyCasts TailwindBundle:
  <https://symfony.com/bundles/TailwindBundle/current/index.html>

