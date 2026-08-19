import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(process.argv[2], 'utf8')
    .replace(/^import .*;\n/u, '')
    .replace('export default class extends Controller', 'return class extends Controller');
const Controller = class {};
const SidebarController = new Function('Controller', source)(Controller);
const classes = () => {
    const values = new Set();

    return {
        add(...names) {
            names.forEach((name) => values.add(name));
        },
        contains(name) {
            return values.has(name);
        },
        remove(...names) {
            names.forEach((name) => values.delete(name));
        },
    };
};
const storage = new Map([['account-sidebar-collapsed', 'true']]);
globalThis.localStorage = {
    getItem(key) {
        return storage.get(key) ?? null;
    },
    setItem(key, value) {
        storage.set(key, value);
    },
};
globalThis.document = { body: { classList: classes() } };

const controller = new SidebarController();
const focused = [];
controller.storageKeyValue = 'account-sidebar-collapsed';
controller.mobilePanelTarget = {
    inert: true,
    classList: classes(),
    attributes: new Map([['aria-hidden', 'true']]),
    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    },
    querySelector() {
        return { focus: () => focused.push('drawer') };
    },
    setAttribute(name, value) {
        this.attributes.set(name, value);
    },
};
controller.mobilePanelTarget.classList.add('-translate-x-full');
controller.mobileBackdropTarget = { classList: classes() };
controller.mobileBackdropTarget.classList.add('hidden');
controller.mobileTriggerTarget = {
    attributes: new Map([['aria-expanded', 'false']]),
    focus() {
        focused.push('trigger');
    },
    setAttribute(name, value) {
        this.attributes.set(name, value);
    },
};
controller.desktopPanelTarget = { dataset: { collapsed: 'false' } };
controller.desktopTriggerTarget = {
    attributes: new Map([['aria-expanded', 'true']]),
    setAttribute(name, value) {
        this.attributes.set(name, value);
    },
};

controller.connect();
assert.equal(controller.desktopPanelTarget.dataset.collapsed, 'true');
assert.equal(controller.desktopTriggerTarget.attributes.get('aria-expanded'), 'false');

controller.openMobile();
assert.equal(controller.mobilePanelTarget.inert, false);
assert.equal(controller.mobilePanelTarget.classList.contains('-translate-x-full'), false);
assert.equal(controller.mobileBackdropTarget.classList.contains('hidden'), false);
assert.equal(controller.mobilePanelTarget.getAttribute('aria-hidden'), 'false');
assert.equal(controller.mobileTriggerTarget.attributes.get('aria-expanded'), 'true');
assert.equal(document.body.classList.contains('overflow-hidden'), true);
assert.deepEqual(focused, ['drawer']);

controller.closeMobile();
assert.equal(controller.mobilePanelTarget.inert, true);
assert.equal(controller.mobilePanelTarget.classList.contains('-translate-x-full'), true);
assert.equal(controller.mobileBackdropTarget.classList.contains('hidden'), true);
assert.equal(controller.mobilePanelTarget.getAttribute('aria-hidden'), 'true');
assert.equal(document.body.classList.contains('overflow-hidden'), false);
assert.deepEqual(focused, ['drawer', 'trigger']);

controller.toggleDesktop();
assert.equal(controller.desktopPanelTarget.dataset.collapsed, 'false');
assert.equal(controller.desktopTriggerTarget.attributes.get('aria-expanded'), 'true');
assert.equal(storage.get('account-sidebar-collapsed'), 'false');

controller.openMobile();
controller.disconnect();
assert.equal(controller.mobilePanelTarget.inert, true);
assert.equal(document.body.classList.contains('overflow-hidden'), false);
