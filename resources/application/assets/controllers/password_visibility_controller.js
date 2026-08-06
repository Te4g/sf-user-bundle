import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'button', 'label'];

    static values = {
        showLabel: String,
        hideLabel: String,
    };

    toggle() {
        const visible = this.inputTarget.type === 'text';

        this.inputTarget.type = visible ? 'password' : 'text';
        this.buttonTarget.setAttribute('aria-pressed', visible ? 'false' : 'true');
        this.labelTarget.textContent = visible ? this.showLabelValue : this.hideLabelValue;
    }
}
