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
