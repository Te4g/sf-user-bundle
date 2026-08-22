(() => {
    const storageKey = 'theme';
    const system = 'system';
    const preferences = new Set([system, 'light', 'dark']);
    const labels = new Map([[system, 'System'], ['light', 'Light'], ['dark', 'Dark']]);
    const switcherSelector = '[data-theme-switcher]';
    const triggerSelector = '[data-theme-trigger]';
    const optionSelector = '[data-theme-option]';
    const mediaQuery = typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;

    const normalize = (value) => preferences.has(value) ? value : system;
    const closest = (target, selector) => target && typeof target.closest === 'function'
        ? target.closest(selector)
        : null;
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
    const setOpen = (switcher, open) => {
        switcher.dataset.open = open ? 'true' : 'false';
        switcher.querySelector(triggerSelector)?.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    const closeAll = (except = null) => {
        for (const switcher of document.querySelectorAll(switcherSelector)) {
            if (switcher !== except) {
                setOpen(switcher, false);
            }
        }
    };
    const synchronize = () => {
        for (const switcher of document.querySelectorAll(switcherSelector)) {
            const trigger = switcher.querySelector(triggerSelector);
            trigger?.setAttribute('aria-label', `Theme: ${labels.get(preference)}`);

            for (const icon of switcher.querySelectorAll('[data-theme-current-icon]')) {
                icon.hidden = icon.dataset.themeCurrentIcon !== preference;
            }
            for (const option of switcher.querySelectorAll(optionSelector)) {
                option.hidden = option.dataset.themeOption === preference;
            }
        }
    };
    const refresh = () => {
        apply();
        synchronize();
    };

    apply();
    document.addEventListener('DOMContentLoaded', refresh);
    document.addEventListener('turbo:load', refresh);
    document.addEventListener('turbo:before-cache', () => closeAll());
    document.addEventListener('pointerover', (event) => {
        if (event.pointerType !== 'mouse') {
            return;
        }

        const switcher = closest(event.target, switcherSelector);
        if (switcher && !switcher.contains(event.relatedTarget)) {
            closeAll(switcher);
            setOpen(switcher, true);
        }
    });
    document.addEventListener('pointerout', (event) => {
        if (event.pointerType !== 'mouse') {
            return;
        }

        const switcher = closest(event.target, switcherSelector);
        if (switcher && !switcher.contains(event.relatedTarget)) {
            setOpen(switcher, false);
        }
    });
    document.addEventListener('click', (event) => {
        const option = closest(event.target, optionSelector);
        if (option) {
            preference = normalize(option.dataset.themeOption);
            save(preference);
            refresh();
            closeAll();

            return;
        }

        const trigger = closest(event.target, triggerSelector);
        if (trigger) {
            const switcher = closest(trigger, switcherSelector);
            if (!switcher) {
                return;
            }

            const open = switcher.dataset.open !== 'true';
            closeAll(switcher);
            setOpen(switcher, open);
            if (open) {
                [...switcher.querySelectorAll(optionSelector)].find((item) => !item.hidden)?.focus();
            }

            return;
        }

        if (!closest(event.target, switcherSelector)) {
            closeAll();
        }
    });
    document.addEventListener('focusin', (event) => {
        if (!closest(event.target, switcherSelector)) {
            closeAll();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        for (const switcher of document.querySelectorAll(switcherSelector)) {
            if (switcher.dataset.open === 'true') {
                setOpen(switcher, false);
                switcher.querySelector(triggerSelector)?.focus();
            }
        }
    });
    mediaQuery?.addEventListener('change', () => {
        if (preference === system) {
            refresh();
        }
    });
})();
