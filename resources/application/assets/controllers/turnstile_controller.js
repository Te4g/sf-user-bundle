import { Controller } from '@hotwired/stimulus';

let loading;

function loadApi() {
    if (window.turnstile) return Promise.resolve(window.turnstile);
    if (loading) return loading;

    loading = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        script.async = true;
        const timer = setTimeout(() => fail(), 10000);
        const fail = () => {
            clearTimeout(timer);
            script.remove();
            loading = undefined;
            reject(new Error('Security check unavailable'));
        };
        script.addEventListener('error', fail, { once: true });
        script.addEventListener('load', () => {
            clearTimeout(timer);
            if (window.turnstile) resolve(window.turnstile);
            else fail();
        }, { once: true });
        document.head.append(script);
    });

    return loading;
}

export default class extends Controller {
    static targets = ['widget', 'response', 'error'];
    static values = { siteKey: String };

    connect() {
        const connection = this.connection = (this.connection || 0) + 1;
        this.responseTarget.value = '';
        loadApi().then(api => {
            if (connection !== this.connection || !this.element.isConnected) return;
            this.widgetId = api.render(this.widgetTarget, {
                sitekey: this.siteKeyValue,
                action: 'register',
                size: 'compact',
                'response-field': false,
                callback: token => {
                    if (connection !== this.connection) return;
                    this.responseTarget.value = token;
                    this.errorTarget.hidden = true;
                },
                'expired-callback': () => { if (connection === this.connection) this.invalidate(); },
                'timeout-callback': () => { if (connection === this.connection) this.invalidate(); },
                'error-callback': () => { if (connection === this.connection) this.invalidate(); return true; },
            });
        }).catch(() => {
            if (connection === this.connection && this.element.isConnected) this.invalidate();
        });
    }

    invalidate() {
        this.responseTarget.value = '';
        this.errorTarget.hidden = false;
    }

    beforeCache() {
        this.disconnect();
    }

    disconnect() {
        this.connection = (this.connection || 0) + 1;
        if (this.widgetId !== undefined) window.turnstile?.remove(this.widgetId);
        this.widgetId = undefined;
        this.responseTarget.value = '';
    }
}
