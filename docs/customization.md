# Customization

The starter copies final application code rather than installing extension points in a vendor bundle. Customize the generated `App\` classes, Symfony configuration, Twig templates, assets, translations, and tests directly, and keep the tests that enforce the security behavior you still require.

## Account data and workflows

- Add application fields to `src/Entity/User.php`, update the corresponding form and service, then generate a normal Doctrine migration.
- Keep email normalization centralized in `src/Account/EmailNormalizer.php` and use `src/Repository/UserRepository.php` for normalized lookups.
- Adapt registration, verification, password recovery, profile changes, and deletion in the matching classes under `src/Account/` and `src/Controller/`.
- Add product-specific account deletion checks in `src/Account/AccountDeletionService.php` before the transactional removal step.
- Customize message subjects, templates, sender policy, or queueing in `src/Mailer/AccountMailer.php` and the templates under `templates/email/`.

When adding side effects, decide explicitly whether they belong inside the Doctrine transition or after commit. External calls inside a transaction can succeed even if the database later rolls back; post-commit work needs retry and deduplication behavior.

## Routes and navigation

Generated routes use application names such as `app_register`, `app_login`, `app_dashboard`, and `app_account`. Change route attributes and every matching Twig link together. Preserve POST-only logout, login-link confirmation, and account deletion unless you replace them with an equivalent CSRF-protected design.

The public, authentication, and authenticated shells live in:

- `templates/layout/public.html.twig`
- `templates/layout/auth.html.twig`
- `templates/layout/app.html.twig`

Edit these as normal Twig templates. Turbo Drive provides navigation by default; no vendor runtime is involved.

## UI components and styles

Reusable Twig components are under `templates/components/`. The generated stylesheet is `assets/styles/app.css`, built by SymfonyCasts TailwindBundle, and includes the local Shadcn/Tailwind animation imports installed into the application's import map.

The starter deliberately generates only two custom Stimulus controllers:

- `assets/controllers/password_visibility_controller.js`
- `assets/controllers/alert_dialog_controller.js`

Keep their label, focus, native validation, and dialog semantics when changing markup. Add new Stimulus controllers only for browser-local behavior; use ordinary Symfony requests and Turbo for server work.

Offline icons live in `assets/icons/lucide/`. If you add or replace icons, keep them local, review their license, and update application tests and accessibility labels. The generated application-owned `STARTER_LICENSES.md` scopes the license for copied starter resources and preserves the full Symfony UX Toolkit, Lucide, and Feather notices after the starter package is removed. The maintainer snapshot tool and hash manifest remain in the starter repository and are not runtime dependencies.

## Forms, translations, and tests

Form types and input objects live under `src/Form/`; the application form theme is `templates/form/account_theme.html.twig`. Preserve explicit labels, associated error IDs, CSRF token IDs, neutral account-request responses, and HTTP 422 for invalid submissions.

English strings are in `translations/messages.en.xlf`. Add normal Symfony translation catalogues for other locales.

The generated `tests/Functional/` suite is application-owned. Extend it alongside each workflow change, especially for unknown-account neutrality, expired or reused signed links, invalid CSRF tokens, password/session invalidation, concurrent email changes, transactional rollback, and sensitive log content.
