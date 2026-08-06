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
