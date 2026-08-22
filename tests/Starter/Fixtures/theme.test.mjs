import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(process.argv[2], 'utf8');
const themes = ['system', 'light', 'dark'];
const switcherSelector = '[data-theme-switcher]';
const triggerSelector = '[data-theme-trigger]';
const optionSelector = '[data-theme-option]';

function boot({ saved = null, deviceDark = false, storageThrows = false, exposeMatchMedia = true } = {}) {
    const documentListeners = new Map();
    const mediaListeners = [];
    let storedPreference = saved;
    let dark = false;
    let focused = null;
    let switcher;

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
    const icons = themes.map((theme) => ({
        dataset: { themeCurrentIcon: theme },
        hidden: theme !== 'system',
    }));
    const trigger = {
        attributes: new Map([
            ['aria-label', 'Theme: System'],
            ['aria-expanded', 'false'],
        ]),
        setAttribute(name, value) {
            this.attributes.set(name, value);
        },
        getAttribute(name) {
            return this.attributes.get(name);
        },
        focus() {
            focused = this;
        },
        closest(selector) {
            if (selector === triggerSelector) {
                return this;
            }

            return selector === switcherSelector ? switcher : null;
        },
    };
    const options = themes.map((theme) => ({
        dataset: { themeOption: theme },
        hidden: theme === 'system',
        focus() {
            focused = this;
        },
        closest(selector) {
            if (selector === optionSelector) {
                return this;
            }

            return selector === switcherSelector ? switcher : null;
        },
    }));
    switcher = {
        dataset: { open: 'false' },
        contains(element) {
            return element === this || element === trigger || options.includes(element);
        },
        querySelector(selector) {
            assert.equal(selector, triggerSelector);

            return trigger;
        },
        querySelectorAll(selector) {
            if (selector === '[data-theme-current-icon]') {
                return icons;
            }
            assert.equal(selector, optionSelector);

            return options;
        },
    };
    const document = {
        documentElement: root,
        addEventListener(name, listener) {
            documentListeners.set(name, listener);
        },
        querySelectorAll(selector) {
            assert.equal(selector, switcherSelector);

            return [switcher];
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
        switcher,
        trigger,
        options,
        get storedPreference() {
            return storedPreference;
        },
        get currentTheme() {
            return icons.find((icon) => !icon.hidden)?.dataset.themeCurrentIcon;
        },
        get visibleOptions() {
            return options.filter((option) => !option.hidden).map((option) => option.dataset.themeOption);
        },
        get focusedTheme() {
            return focused?.dataset?.themeOption ?? (focused === trigger ? 'trigger' : null);
        },
        dispatch(name, event = {}) {
            documentListeners.get(name)?.(event);
        },
        toggle() {
            documentListeners.get('click')?.({ target: trigger });
        },
        hover() {
            documentListeners.get('pointerover')?.({
                target: trigger,
                relatedTarget: null,
                pointerType: 'mouse',
            });
        },
        leave() {
            documentListeners.get('pointerout')?.({
                target: trigger,
                relatedTarget: null,
                pointerType: 'mouse',
            });
        },
        select(value) {
            documentListeners.get('click')?.({
                target: options.find((option) => option.dataset.themeOption === value),
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
assert.equal(firstVisit.currentTheme, 'system');
assert.deepEqual(firstVisit.visibleOptions, ['light', 'dark']);
assert.equal(firstVisit.trigger.getAttribute('aria-label'), 'Theme: System');

firstVisit.toggle();
assert.equal(firstVisit.switcher.dataset.open, 'true');
assert.equal(firstVisit.trigger.getAttribute('aria-expanded'), 'true');
assert.equal(firstVisit.focusedTheme, 'light');
firstVisit.dispatch('keydown', { key: 'Escape' });
assert.equal(firstVisit.switcher.dataset.open, 'false');
assert.equal(firstVisit.trigger.getAttribute('aria-expanded'), 'false');
assert.equal(firstVisit.focusedTheme, 'trigger');

const savedLight = boot({ saved: 'light', deviceDark: true });
assert.equal(savedLight.root.classList.contains('dark'), false);
assert.equal(savedLight.root.style.colorScheme, 'light');
savedLight.dispatch('turbo:load');
assert.equal(savedLight.currentTheme, 'light');
assert.deepEqual(savedLight.visibleOptions, ['system', 'dark']);
assert.equal(savedLight.trigger.getAttribute('aria-label'), 'Theme: Light');

const explicitChoice = boot({ deviceDark: false });
explicitChoice.hover();
assert.equal(explicitChoice.switcher.dataset.open, 'true');
explicitChoice.select('dark');
assert.equal(explicitChoice.switcher.dataset.open, 'false');
assert.equal(explicitChoice.storedPreference, 'dark');
assert.equal(explicitChoice.root.classList.contains('dark'), true);
assert.equal(explicitChoice.currentTheme, 'dark');
assert.deepEqual(explicitChoice.visibleOptions, ['system', 'light']);
explicitChoice.leave();
explicitChoice.hover();
assert.equal(explicitChoice.switcher.dataset.open, 'true');
explicitChoice.setDeviceDark(false);
assert.equal(explicitChoice.root.classList.contains('dark'), true);
explicitChoice.select('system');
assert.equal(explicitChoice.storedPreference, 'system');
assert.equal(explicitChoice.root.classList.contains('dark'), false);
explicitChoice.setDeviceDark(true);
assert.equal(explicitChoice.root.classList.contains('dark'), true);

const invalidPreference = boot({ saved: 'sepia', deviceDark: true });
invalidPreference.dispatch('DOMContentLoaded');
assert.equal(invalidPreference.currentTheme, 'system');
assert.equal(invalidPreference.root.classList.contains('dark'), true);

const blockedStorage = boot({ deviceDark: true, storageThrows: true });
assert.equal(blockedStorage.root.classList.contains('dark'), true);
blockedStorage.select('light');
assert.equal(blockedStorage.root.classList.contains('dark'), false);
assert.equal(blockedStorage.currentTheme, 'light');
blockedStorage.setDeviceDark(true);
assert.equal(blockedStorage.root.classList.contains('dark'), false);

const missingMatchMedia = boot({ exposeMatchMedia: false });
assert.equal(missingMatchMedia.root.classList.contains('dark'), false);
assert.equal(missingMatchMedia.root.style.colorScheme, 'light');
