# Theme Selection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a browser-persisted System, Light, or Dark appearance selector to every generated application header, with System as the device-following default.

**Architecture:** A standalone application-owned head script owns theme preference, pre-paint root styling, device preference observation, storage resilience, and Turbo synchronization. A reusable Twig component renders the native selector in the public, authentication, and authenticated layouts; no server-side state or new dependency is introduced.

**Tech Stack:** JavaScript browser APIs, Node.js behavior tests, Twig Components, Turbo Drive events, Tailwind CSS 4 and shadcn-inspired CSS tokens, PHPUnit 12.

## Global Constraints

- System is the initial default and follows `prefers-color-scheme: dark` whenever `matchMedia` is available.
- The three allowed preferences are exactly `system`, `light`, and `dark`.
- Preference persistence is browser-local only; add no entity field, migration, server request, runtime dependency, or starter configuration option.
- Render the selector in the public, authentication, and authenticated headers.
- Apply the effective theme before the main stylesheet to avoid a light-theme flash.
- Use the existing root `.dark` class and existing light/dark CSS tokens; do not redesign the palette.
- Storage failures and invalid saved values fall back to System; missing `matchMedia` falls back to light.
- Turbo navigation must synchronize newly rendered selectors without reloading the document head.

## File Structure

- `resources/application/assets/theme.js`: owns preference normalization, in-memory state, local-storage access, root theme application, device-change handling, delegated selector changes, and Turbo synchronization.
- `tests/Starter/Fixtures/theme.test.mjs`: executes the real theme asset against deterministic browser API fakes.
- `resources/application/templates/components/ThemeSelector.html.twig`: owns accessible native selector markup and styling.
- `resources/application/templates/base.html.twig`: loads the theme asset synchronously before the main stylesheet.
- `resources/application/templates/layout/{public,auth,app}.html.twig`: places the shared selector in every header.
- `tests/Starter/Resources/UserInterfaceResourcesTest.php`: launches the JavaScript behavior test and verifies the generated Twig integration contract.

---

### Task 1: System-aware theme runtime

**Files:**
- Create: `resources/application/assets/theme.js`
- Create: `tests/Starter/Fixtures/theme.test.mjs`
- Modify: `tests/Starter/Resources/UserInterfaceResourcesTest.php`

**Interfaces:**
- Consumes: `window.localStorage`, `window.matchMedia('(prefers-color-scheme: dark)')`, `document.documentElement`, `DOMContentLoaded`, `turbo:load`, and delegated `change` events.
- Produces: browser behavior for `[data-theme-selector]`; persisted key `theme`; root `.dark` class; root `style.colorScheme` value of `light` or `dark`.

- [ ] **Step 1: Add the failing PHPUnit entry point**

Add this method after `testAlertDialogClosesBeforeNativeFormSubmission()` in `tests/Starter/Resources/UserInterfaceResourcesTest.php`:

```php
public function testThemeBehaviorFollowsTheDeviceAndPersistsExplicitChoices(): void
{
    $test = dirname(__DIR__).'/Fixtures/theme.test.mjs';
    $theme = $this->applicationResources().'/assets/theme.js';
    $process = new Process(['node', $test, $theme]);
    $process->run();

    self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
}
```

Create `tests/Starter/Fixtures/theme.test.mjs` with the complete browser harness and assertions:

```js
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(process.argv[2], 'utf8');

function boot({ saved = null, deviceDark = false, storageThrows = false, exposeMatchMedia = true } = {}) {
    const documentListeners = new Map();
    const mediaListeners = [];
    const selectors = [{ value: '' }];
    let storedPreference = saved;
    let dark = false;

    const root = {
        classList: {
            toggle(name, enabled) {
                assert.equal(name, 'dark');
                dark = enabled;
            },
            contains(name) {
                assert.equal(name, 'dark');

                return dark;
            },
        },
        style: {},
    };
    const mediaQuery = {
        matches: deviceDark,
        addEventListener(name, listener) {
            assert.equal(name, 'change');
            mediaListeners.push(listener);
        },
    };
    const document = {
        documentElement: root,
        addEventListener(name, listener) {
            documentListeners.set(name, listener);
        },
        querySelectorAll(selector) {
            assert.equal(selector, '[data-theme-selector]');

            return selectors;
        },
    };
    const window = {
        localStorage: {
            getItem(key) {
                assert.equal(key, 'theme');
                if (storageThrows) {
                    throw new Error('storage blocked');
                }

                return storedPreference;
            },
            setItem(key, value) {
                assert.equal(key, 'theme');
                if (storageThrows) {
                    throw new Error('storage blocked');
                }

                storedPreference = value;
            },
        },
    };
    if (exposeMatchMedia) {
        window.matchMedia = (query) => {
            assert.equal(query, '(prefers-color-scheme: dark)');

            return mediaQuery;
        };
    }

    new Function('window', 'document', source)(window, document);

    return {
        root,
        selectors,
        get storedPreference() {
            return storedPreference;
        },
        dispatch(name) {
            documentListeners.get(name)?.();
        },
        select(value) {
            documentListeners.get('change')?.({
                target: {
                    value,
                    matches: (selector) => selector === '[data-theme-selector]',
                },
            });
        },
        setDeviceDark(matches) {
            mediaQuery.matches = matches;
            for (const listener of mediaListeners) {
                listener({ matches });
            }
        },
    };
}

const firstVisit = boot({ deviceDark: true });
assert.equal(firstVisit.root.classList.contains('dark'), true);
assert.equal(firstVisit.root.style.colorScheme, 'dark');
firstVisit.dispatch('DOMContentLoaded');
assert.equal(firstVisit.selectors[0].value, 'system');

const savedLight = boot({ saved: 'light', deviceDark: true });
assert.equal(savedLight.root.classList.contains('dark'), false);
assert.equal(savedLight.root.style.colorScheme, 'light');
savedLight.dispatch('turbo:load');
assert.equal(savedLight.selectors[0].value, 'light');

const explicitChoice = boot({ deviceDark: false });
explicitChoice.select('dark');
assert.equal(explicitChoice.storedPreference, 'dark');
assert.equal(explicitChoice.root.classList.contains('dark'), true);
explicitChoice.setDeviceDark(false);
assert.equal(explicitChoice.root.classList.contains('dark'), true);
explicitChoice.select('system');
assert.equal(explicitChoice.storedPreference, 'system');
assert.equal(explicitChoice.root.classList.contains('dark'), false);
explicitChoice.setDeviceDark(true);
assert.equal(explicitChoice.root.classList.contains('dark'), true);

const invalidPreference = boot({ saved: 'sepia', deviceDark: true });
invalidPreference.dispatch('DOMContentLoaded');
assert.equal(invalidPreference.selectors[0].value, 'system');
assert.equal(invalidPreference.root.classList.contains('dark'), true);

const blockedStorage = boot({ deviceDark: true, storageThrows: true });
assert.equal(blockedStorage.root.classList.contains('dark'), true);
blockedStorage.select('light');
assert.equal(blockedStorage.root.classList.contains('dark'), false);
blockedStorage.setDeviceDark(true);
assert.equal(blockedStorage.root.classList.contains('dark'), false);

const missingMatchMedia = boot({ exposeMatchMedia: false });
assert.equal(missingMatchMedia.root.classList.contains('dark'), false);
assert.equal(missingMatchMedia.root.style.colorScheme, 'light');
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php -d memory_limit=512M vendor/bin/phpunit --configuration phpunit.xml.dist --filter testThemeBehaviorFollowsTheDeviceAndPersistsExplicitChoices
```

Expected: FAIL because `resources/application/assets/theme.js` does not exist and the Node process cannot read it.

- [ ] **Step 3: Implement the minimal theme runtime**

Create `resources/application/assets/theme.js`:

```js
(() => {
    const storageKey = 'theme';
    const selector = '[data-theme-selector]';
    const system = 'system';
    const preferences = new Set([system, 'light', 'dark']);
    const mediaQuery = typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;

    const normalize = (value) => preferences.has(value) ? value : system;
    const read = () => {
        try {
            return normalize(window.localStorage.getItem(storageKey));
        } catch {
            return system;
        }
    };
    const save = (value) => {
        try {
            window.localStorage.setItem(storageKey, value);
        } catch {
            // The in-memory preference still applies for this page.
        }
    };

    let preference = read();

    const apply = () => {
        const dark = preference === 'dark' || (preference === system && mediaQuery?.matches === true);
        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
    };
    const synchronize = () => {
        for (const themeSelector of document.querySelectorAll(selector)) {
            themeSelector.value = preference;
        }
    };
    const refresh = () => {
        apply();
        synchronize();
    };

    apply();
    document.addEventListener('DOMContentLoaded', refresh);
    document.addEventListener('turbo:load', refresh);
    document.addEventListener('change', (event) => {
        if (!event.target || typeof event.target.matches !== 'function' || !event.target.matches(selector)) {
            return;
        }

        preference = normalize(event.target.value);
        save(preference);
        refresh();
    });
    mediaQuery?.addEventListener('change', () => {
        if (preference === system) {
            refresh();
        }
    });
})();
```

- [ ] **Step 4: Run the focused test and verify GREEN**

Run:

```bash
php -d memory_limit=512M vendor/bin/phpunit --configuration phpunit.xml.dist --filter testThemeBehaviorFollowsTheDeviceAndPersistsExplicitChoices
```

Expected: PASS with one test and no warnings.

- [ ] **Step 5: Run syntax and diff checks**

Run:

```bash
node --check resources/application/assets/theme.js
node --check tests/Starter/Fixtures/theme.test.mjs
git diff --check
```

Expected: all three commands exit 0 with no errors.

- [ ] **Step 6: Commit the runtime slice**

```bash
git add resources/application/assets/theme.js tests/Starter/Fixtures/theme.test.mjs tests/Starter/Resources/UserInterfaceResourcesTest.php
git commit -m "feat: add system-aware theme runtime"
```

### Task 2: Selector and header integration

**Files:**
- Create: `resources/application/templates/components/ThemeSelector.html.twig`
- Modify: `resources/application/templates/base.html.twig`
- Modify: `resources/application/templates/layout/public.html.twig`
- Modify: `resources/application/templates/layout/auth.html.twig`
- Modify: `resources/application/templates/layout/app.html.twig`
- Modify: `tests/Starter/Resources/UserInterfaceResourcesTest.php`

**Interfaces:**
- Consumes: the Task 1 `[data-theme-selector]` contract and `assets/theme.js` entry point.
- Produces: one native selector in each generated header and a synchronous pre-stylesheet script load through `asset('theme.js')`.

- [ ] **Step 1: Add the failing generated-resource contract**

Add this method after the Task 1 theme behavior test in `tests/Starter/Resources/UserInterfaceResourcesTest.php`:

```php
public function testEveryHeaderRendersTheThemeSelectorBeforeStylesLoad(): void
{
    $base = $this->resource('templates/base.html.twig');
    $themePosition = strpos($base, "asset('theme.js')");
    $stylePosition = strpos($base, "asset('styles/app.css')");

    self::assertIsInt($themePosition);
    self::assertIsInt($stylePosition);
    self::assertLessThan($stylePosition, $themePosition);

    foreach (['public', 'auth', 'app'] as $layout) {
        self::assertSame(
            1,
            substr_count($this->resource('templates/layout/'.$layout.'.html.twig'), '<twig:ThemeSelector />'),
            $layout,
        );
    }

    $selector = $this->resource('templates/components/ThemeSelector.html.twig');
    self::assertStringContainsString('<label', $selector);
    self::assertStringContainsString('>Theme</span>', $selector);
    self::assertStringContainsString('data-theme-selector', $selector);
    self::assertSame(1, substr_count($selector, 'value="system"'));
    self::assertSame(1, substr_count($selector, 'value="light"'));
    self::assertSame(1, substr_count($selector, 'value="dark"'));
}
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php -d memory_limit=512M vendor/bin/phpunit --configuration phpunit.xml.dist --filter testEveryHeaderRendersTheThemeSelectorBeforeStylesLoad
```

Expected: FAIL because `asset('theme.js')` and the selector component are absent.

- [ ] **Step 3: Add the accessible selector component**

Create `resources/application/templates/components/ThemeSelector.html.twig`:

```twig
<label class="inline-flex shrink-0 items-center gap-2 text-sm text-muted-foreground">
    <span class="sr-only sm:not-sr-only">Theme</span>
    <select
        data-theme-selector
        class="h-8 rounded-lg border border-input bg-background px-2 text-sm text-foreground outline-none transition-colors focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
    >
        <option value="system">System</option>
        <option value="light">Light</option>
        <option value="dark">Dark</option>
    </select>
</label>
```

- [ ] **Step 4: Load the pre-paint asset before CSS**

In `resources/application/templates/base.html.twig`, place this immediately before the stylesheet link:

```twig
<script src="{{ asset('theme.js') }}" data-turbo-track="reload"></script>
<link rel="stylesheet" href="{{ asset('styles/app.css') }}" data-turbo-track="reload">
```

Keep `importmap('app')` after the stylesheet; do not import `theme.js` from `app.js`, because a deferred module would apply the initial theme after CSS begins rendering.

- [ ] **Step 5: Render one selector in every header**

In `resources/application/templates/layout/public.html.twig`, keep the selector inside the primary navigation before the authentication links and allow wrapping:

```twig
<nav aria-label="Primary navigation" class="flex flex-wrap items-center justify-end gap-2 text-sm">
    <twig:ThemeSelector />
```

In `resources/application/templates/layout/auth.html.twig`, replace the standalone Back home link with this page navigation group:

```twig
<nav aria-label="Page navigation" class="flex items-center gap-2">
    <twig:ThemeSelector />
    <a href="{{ path('app_home') }}" class="rounded-md text-sm text-muted-foreground outline-none transition-colors hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50">Back home</a>
</nav>
```

In `resources/application/templates/layout/app.html.twig`, place the selector first inside the existing account navigation:

```twig
<nav aria-label="Account navigation" class="flex flex-wrap items-center gap-1 text-sm">
    <twig:ThemeSelector />
```

- [ ] **Step 6: Run the focused integration tests and verify GREEN**

Run:

```bash
php -d memory_limit=512M vendor/bin/phpunit --configuration phpunit.xml.dist tests/Starter/Resources/UserInterfaceResourcesTest.php
```

Expected: all `UserInterfaceResourcesTest` tests pass with no warnings.

- [ ] **Step 7: Run the full quality gate**

Run:

```bash
make test
git diff --check
```

Expected: PHP CS Fixer, PHPStan, and the complete PHPUnit suite pass; `git diff --check` exits 0.

- [ ] **Step 8: Commit the UI integration**

```bash
git add resources/application/templates/base.html.twig resources/application/templates/components/ThemeSelector.html.twig resources/application/templates/layout/public.html.twig resources/application/templates/layout/auth.html.twig resources/application/templates/layout/app.html.twig tests/Starter/Resources/UserInterfaceResourcesTest.php
git commit -m "feat: add theme selector to application headers"
```
