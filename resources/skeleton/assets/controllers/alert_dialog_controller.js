import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['trigger', 'dialog', 'form'];

    static values = {
        open: Boolean,
    };

    connect() {
        if (this.openValue) {
            this.open();
        }
    }

    disconnect() {
        if (this.hasDialogTarget && this.dialogTarget.open) {
            this.dialogTarget.close();
        }

        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', 'false');
        }
    }

    open() {
        this.dialogTarget.showModal();
        this.triggerTarget.setAttribute('aria-expanded', 'true');
    }

    close() {
        this.dialogTarget.close();
        this.triggerTarget.setAttribute('aria-expanded', 'false');
        this.triggerTarget.focus();
    }

    submit() {
        this.close();
        this.formTarget.requestSubmit();
    }
}
