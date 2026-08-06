# Third-party notices

## Symfony UX Toolkit Shadcn kit

- Package: `symfony/ux-toolkit` 3.3.0
- Source reference: `f01228fe7d0e3bd26b1c27eb34b0cc1012a9b208`
- Source: https://github.com/symfony/ux-toolkit/tree/v3.3.0/kits/shadcn
- License: MIT
- Recipes: `card`, `button`, `input`, `field`, `alert`, `separator`, `alert-dialog`, `spinner`
- Copied paths: `resources/application/templates/components/` and `resources/application/assets/controllers/alert_dialog_controller.js`

UX Toolkit 3.3.0 accepts one recipe per `ux:install` invocation. The snapshot was generated in Symfony 8.1.2 by running each fixed recipe sequentially with `--kit shadcn --no-interaction`. `Spinner.html.twig` was changed to use an inline CSS spinner so the fixed icon allowlist remains offline. The alert dialog controller and components were adapted to use an accessible native dialog with the account-deletion behavior, existing form submission, focus restoration, and Turbo disconnect cleanup required by this package.

### MIT license notice

Copyright (c) 2025-present Fabien Potencier

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is furnished
to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

## Lucide icons through Iconify

- Package snapshot: `@iconify-json/lucide` 1.2.121
- Source: https://github.com/lucide-icons/lucide
- Distribution source: https://www.npmjs.com/package/@iconify-json/lucide/v/1.2.121
- License: ISC
- Copied icons: `circle-check`, `eye`, `eye-off`, `key-round`, `mail`, `trash-2`, `triangle-alert`
- Copied paths: `resources/application/assets/icons/lucide/`

The SVGs were fetched once with Symfony UX Icons 3.3.0 and are stored locally. Generated applications can run `ux:icons:lock` without downloading these icons on demand. Exact copied paths and SHA-256 hashes are recorded in `resources/shadcn/manifest.json`.

The pinned Iconify package metadata identifies Lucide Contributors as the
author and points to the Lucide license below.

### ISC license notice

ISC License

Copyright (c) 2026 Lucide Icons and Contributors

Permission to use, copy, modify, and/or distribute this software for any
purpose with or without fee is hereby granted, provided that the above
copyright notice and this permission notice appear in all copies.

THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.

### Feather-derived `trash-2` icon

The upstream Lucide license identifies `trash-2` as derived from Feather and
applies the following notice to that icon.

The MIT License (MIT) (for the icons listed above)

Copyright (c) 2013-present Cole Bemis

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
