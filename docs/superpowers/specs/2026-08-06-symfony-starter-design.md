# Te4g Symfony Starter Design

## Status

Approved in conversation on 2026-08-06. This specification supersedes
`2026-07-21-user-bundle-design.md`.

## Goal

Provide Te4g's fresh Symfony applications with the equivalent of an opinionated
Laravel starter kit: a polished application shell and complete user account
lifecycle whose source code belongs entirely to the generated application.

The starter is a one-shot development tool. It must disappear after generation
without leaving a runtime Te4g bundle, an installation manifest, or an upgrade
protocol.

## Product Boundaries

The starter targets Te4g's own fresh Symfony applications rather than arbitrary
existing projects. Its first supported baselines are PHP 8.4 or newer with
Symfony 8.1, created either from `symfony/skeleton` or as a Symfony web
application. The generator installs any missing Doctrine ORM, Twig,
AssetMapper, Form, Mailer, Security, and UX packages required by its output.

The generated frontend uses:

- Symfony UX Twig Components for reusable server-rendered UI;
- Turbo Drive for navigation and conventional form submissions;
- minimal Stimulus controllers for browser-only interactions;
- SymfonyCasts TailwindBundle and shadcn-inspired design tokens;
- AssetMapper and offline Lucide icon assets.

UX Live Components are deliberately excluded. The starter also excludes admin
user management, teams, permissions, social login, API authentication, and
business-domain features.

## Package and Repository Model

### Independent starter

The current package becomes the dev-only Composer package `te4g/starter`. It
exposes a standalone executable at `vendor/bin/te4g-starter`; it is not enabled
as a Symfony bundle and owns no runtime service, controller, route, template, or
configuration.

The standard installation is:

```console
composer require te4g/starter --dev
vendor/bin/te4g-starter install
php bin/console make:migration
php bin/console doctrine:migrations:migrate
composer remove te4g/starter --dev
```

Runtime packages needed by generated code are added directly to the host
application's root Composer requirements. Removing `te4g/starter` therefore
cannot remove a transitive package that the generated application still needs.

### Symfony template integration

`/Users/teag/Dev/symfony-template` remains an infrastructure template. It owns
Docker, FrankenPHP, Caddy, database services, CI, deployment, and developer
commands. It does not duplicate or vendor the starter's generated source.

The template provides a thin `make starter` target after `make start` has
created the Symfony application. The target installs `te4g/starter` as a
development dependency, runs the same independent generator inside the PHP
container, and removes the package after successful generation. It does not
generate or execute a migration. If generation fails, it returns the nonzero
status and leaves the development package installed for diagnosis.

This division keeps one source of truth while supporting both ordinary fresh
Symfony applications and projects created from Te4g's infrastructure template.

## Ownership After Generation

Every generated application class uses the `App\` namespace. The host
application owns and may freely change:

- entities and repositories;
- controllers and forms;
- authentication and account services;
- routes and security configuration;
- Twig layouts, pages, components, and form themes;
- mail templates and translations;
- Tailwind styles, Stimulus controllers, and icons;
- tests and Doctrine migrations.

Generated output must contain no runtime class, service, route, template, or
configuration reference to `Te4g` or `te4g/starter`.

The starter does not support regeneration, upgrades, managed generated regions,
or idempotent reruns. Once generation succeeds, future changes are ordinary
application maintenance.

## Generated Application Surface

### Persistence

The starter generates:

- `App\Entity\User`;
- `App\Repository\UserRepository`;
- `App\Entity\ResetPasswordRequest`;
- `App\Repository\ResetPasswordRequestRepository`.

The `User` entity contains an identifier, name, normalized unique email,
nullable pending email, roles, password hash, and verified state. Plain-text
passwords never live on the entity.

The starter generates entity mappings but no migration. Migration generation
and execution are explicit post-installation application steps so the generator
never depends on a live database or a specific database platform.

### Account features

The generated application includes:

- registration and email verification;
- password login and logout;
- magic-link login;
- forgotten-password and password-reset flows;
- profile-name editing;
- verified email changes;
- authenticated password changes;
- account deletion;
- a public landing page;
- an authenticated dashboard and application shell.

### User interface

The starter creates public, authentication, and authenticated layouts plus
responsive navigation, account navigation, flash messages, and a deliberately
minimal dashboard ready for application content.

The reusable Twig component set is limited to what the generated screens need:
buttons, cards, fields, inputs, labels, alerts, dialogs, separators, and loading
indicators. The components are application code, not a separately versioned UI
library.

Stimulus is limited to local browser behaviour such as password visibility and
confirmation dialogs. Account state changes use ordinary Symfony forms and
redirects enhanced by Turbo Drive.

## Runtime Account Flows

### Registration and verification

Registration accepts a name, email, and password. The application trims and
normalizes email addresses to lowercase before lookup or persistence and
enforces uniqueness at both validation and database levels.

Successful registration creates an unverified user, hashes the password, signs
the user in, sends an expiring signed verification link, and redirects to a
verification-required screen.

Authenticated unverified users may access only verification, resend-
verification, and logout routes. The dashboard and account area require a
verified email.

### Authentication

Password login delegates hashing and verification to Symfony Security. Magic-
link requests return the same response whether or not an account exists. A
valid magic link authenticates the user and marks its destination email as
verified because possession of that link proves control of the address.

Login, magic-link, password-reset, verification, and resend requests are
rate-limited. State-changing forms use CSRF protection.

### Password recovery and changes

Password recovery composes SymfonyCasts ResetPasswordBundle for expiring,
throttled, single-use reset requests. A successful password reset or
authenticated password change invalidates outstanding reset requests. An
authenticated password change requires the current password.

### Email changes

An email change stores the normalized candidate address as `pendingEmail` and
sends a signed link to that address. The active identifier remains unchanged
until the link is accepted. Acceptance verifies uniqueness again, promotes the
pending address to the active email, clears `pendingEmail`, and keeps the
account verified.

### Account deletion

Account deletion requires the current password and explicit confirmation. It
removes related reset requests and the user, invalidates the session, and
redirects to the public home page.

## Generator Workflow

The generator is a short linear pipeline:

1. Locate the host project and verify the supported PHP and Symfony baseline.
2. Verify the fresh-project shape, reject an existing account system, and
   inspect every destination.
3. Stop before any write if a target or configuration conflicts.
4. Add required runtime Composer packages to the host application's root
   requirements.
5. Render the complete generated file set into a temporary directory.
6. Validate staged PHP syntax and statically parse staged YAML and Twig where
   possible.
7. Copy the staged files and apply only the explicitly supported fresh-project
   configuration replacements.
8. Validate the application container, YAML, Twig, Tailwind build, AssetMapper,
   import map, PHP syntax, and offline icon inventory.
9. Print the migration and package-removal commands.

The generator never connects to the database, creates a database, generates a
migration, or executes a migration.

## Conflicts and Errors

The generator supports a known fresh-project baseline. It replaces recognized
fresh Symfony defaults where necessary, including the default in-memory
security provider. If the file does not match a supported baseline or a target
already exists, the preflight fails before writes.

Every conflict message includes the exact path, the conflicting structure, and
the reason generation cannot continue. A generic message such as
`Installation conflicts must be resolved` is never sufficient by itself.

A second installation attempt is refused with a concise explanation. It does
not merge, repair, update, or compare hashes.

If dependency installation fails, no generated application files have been
copied. If validation fails after copying, the generated application-owned
files remain available for inspection. The command prints the failing phase,
the exact command when a subprocess was involved, its exit code, and complete
standard error. It does not hide the original exception behind a generic final
error and does not implement transactional rollback.

## Dependency and Security Authorities

Generated workflows compose maintained Symfony mechanisms instead of custom
cryptography:

- Symfony Security for sessions, password hashing, form login, login links,
  access control, and CSRF;
- Symfony Mailer for account email;
- Symfony RateLimiter for abuse controls;
- SymfonyCasts VerifyEmailBundle for signed email-verification links;
- SymfonyCasts ResetPasswordBundle for password-reset tokens;
- Doctrine ORM and MigrationsBundle for persistence and application-owned
  migrations.

The generated configuration is authoritative in the application. There is no
parallel Te4g configuration layer and no service-extension contract to keep in
sync.

Signed account links are expiring and single-purpose. Secrets are never logged,
stored as plain text on entities, or reused as passwords. Production setup must
provide a real `MAILER_DSN` and a correct public router base URL.

## Testing Strategy

### Generator tests

Focused generator tests cover:

- supported and unsupported host versions;
- each fresh-project configuration replacement;
- exact conflict reporting before writes;
- runtime dependencies becoming root application requirements;
- generated file inventory and namespace ownership;
- refusal to rerun;
- preservation of subprocess error output;
- successful generation while `DATABASE_URL` is unreachable.

### Fresh-application smoke test

Isolated smoke fixtures create both a bare `symfony/skeleton` application and a
fresh supported Symfony web application. Each fixture then:

1. installs `te4g/starter` as a development dependency;
2. runs the generator;
3. removes `te4g/starter`;
4. generates a Doctrine migration and verifies its expected schema intent;
5. creates the test database and applies migrations;
6. boots the container;
7. lints PHP, YAML, and Twig;
8. builds Tailwind and AssetMapper assets;
9. confirms no runtime `Te4g` references remain;
10. runs the generated application's test suite.

The same outcome is exercised through the `symfony-template` container and its
`make starter` wrapper.

### Generated application tests

Generated functional tests cover positive and negative paths for:

- registration and duplicate normalized emails;
- verification and unverified-route restrictions;
- password login and logout;
- neutral magic-link requests, valid links, and invalid or expired links;
- password-reset requests, throttling, expiry, and successful reset;
- current-password checks and password changes;
- pending-email creation, uniqueness recheck, and confirmation;
- account deletion and session invalidation;
- CSRF rejection and rate limits;
- account-enumeration resistance.

Mailer assertions use the test transport; they do not require external email
delivery.

## Acceptance Criteria

The work is complete when both entry paths succeed:

1. a normal fresh Symfony web application using `vendor/bin/te4g-starter`;
2. a project created through `symfony-template` using `make starter`.

For each path:

- generation succeeds without database access;
- conflicts and failures expose their precise cause;
- the application owns all generated code;
- removing `te4g/starter` leaves the container and assets buildable;
- a reviewed migration can be generated and applied;
- the landing page, dashboard, and complete account lifecycle work;
- generated functional tests pass;
- no runtime Te4g package, namespace, configuration, manifest, or upgrade
  mechanism remains.
