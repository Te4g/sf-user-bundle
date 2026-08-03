# Third-party notices

## Symfony UX Toolkit Shadcn kit

- Package: `symfony/ux-toolkit` 3.3.0
- Source reference: `f01228fe7d0e3bd26b1c27eb34b0cc1012a9b208`
- Source: https://github.com/symfony/ux-toolkit/tree/v3.3.0/kits/shadcn
- License: MIT
- Recipes: `card`, `button`, `input`, `field`, `alert`, `separator`, `alert-dialog`, `spinner`
- Copied paths: `resources/skeleton/templates/components/` and `resources/skeleton/assets/controllers/alert_dialog_controller.js`

UX Toolkit 3.3.0 accepts one recipe per `ux:install` invocation. The snapshot was generated in Symfony 8.1.2 by running each fixed recipe sequentially with `--kit shadcn --no-interaction`. `Spinner.html.twig` was changed to use an inline CSS spinner so the fixed icon allowlist remains offline. The alert dialog controller and components were adapted to use an accessible native dialog with the account-deletion behavior, existing form submission, focus restoration, and Turbo disconnect cleanup required by this package.

## Lucide icons through Iconify

- Package snapshot: `@iconify-json/lucide` 1.2.121
- Source: https://github.com/lucide-icons/lucide
- Distribution source: https://www.npmjs.com/package/@iconify-json/lucide/v/1.2.121
- License: ISC
- Copied icons: `circle-check`, `eye`, `eye-off`, `key-round`, `mail`, `trash-2`, `triangle-alert`
- Copied path: `resources/skeleton/assets/icons/lucide/`

The SVGs were fetched once with Symfony UX Icons 3.3.0 and are stored locally. Generated applications can run `ux:icons:lock` without downloading these icons on demand. Exact copied paths and SHA-256 hashes are recorded in `resources/shadcn/manifest.json`.
