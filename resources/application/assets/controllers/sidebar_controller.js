import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['mobilePanel', 'mobileBackdrop', 'mobileTrigger', 'desktopPanel', 'desktopTrigger'];

    static values = {
        storageKey: { type: String, default: 'account-sidebar-collapsed' },
    };

    connect() {
        this.setDesktopCollapsed(this.readCollapsed());
    }

    disconnect() {
        this.closeMobile({ restoreFocus: false });
    }

    openMobile() {
        this.mobilePanelTarget.inert = false;
        this.mobilePanelTarget.classList.remove('-translate-x-full');
        this.mobilePanelTarget.setAttribute('aria-hidden', 'false');
        this.mobileBackdropTarget.classList.remove('hidden');
        this.mobileTriggerTarget.setAttribute('aria-expanded', 'true');
        document.body.classList.add('overflow-hidden');

        this.mobilePanelTarget.querySelector('[data-sidebar-autofocus], a, button')?.focus();
    }

    closeMobile(options = {}) {
        const restoreFocus = options.restoreFocus !== false;
        const wasOpen = this.mobilePanelTarget.getAttribute('aria-hidden') === 'false';

        this.mobilePanelTarget.inert = true;
        this.mobilePanelTarget.classList.add('-translate-x-full');
        this.mobilePanelTarget.setAttribute('aria-hidden', 'true');
        this.mobileBackdropTarget.classList.add('hidden');
        this.mobileTriggerTarget.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('overflow-hidden');

        if (restoreFocus && wasOpen) {
            this.mobileTriggerTarget.focus();
        }
    }

    beforeCache() {
        this.closeMobile({ restoreFocus: false });
    }

    toggleDesktop() {
        const collapsed = this.desktopPanelTarget.dataset.collapsed !== 'true';

        this.setDesktopCollapsed(collapsed);
        this.writeCollapsed(collapsed);
    }

    setDesktopCollapsed(collapsed) {
        this.desktopPanelTarget.dataset.collapsed = String(collapsed);
        this.desktopTriggerTarget.setAttribute('aria-expanded', String(!collapsed));
    }

    readCollapsed() {
        try {
            return localStorage.getItem(this.storageKeyValue) === 'true';
        } catch {
            return false;
        }
    }

    writeCollapsed(collapsed) {
        try {
            localStorage.setItem(this.storageKeyValue, String(collapsed));
        } catch {
            // Storage may be unavailable in privacy-focused browser contexts.
        }
    }
}
