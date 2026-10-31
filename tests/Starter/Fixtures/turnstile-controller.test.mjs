import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(process.argv[2], 'utf8')
    .replace(/^import .*;\n/u, '')
    .replace('export default class extends Controller', 'return class extends Controller');
const tick = () => new Promise(resolve => setImmediate(resolve));

function harness(api) {
    const window = { turnstile: api };
    const scripts = [];
    const document = {
        createElement() {
            return {
                listeners: {},
                addEventListener(name, callback) { this.listeners[name] = callback; },
                remove() { this.removed = true; },
            };
        },
        head: { append(script) { scripts.push(script); } },
    };
    const Controller = new Function('Controller', 'window', 'document', source)(class {}, window, document);
    const create = () => Object.assign(new Controller(), {
        responseTarget: { value: 'stale-token' },
        errorTarget: { hidden: true },
        widgetTarget: {},
        siteKeyValue: 'public-site-key',
        element: { isConnected: true },
    });
    return { window, scripts, create };
}

const renders = [];
const removals = [];
const api = {
    render(target, options) { renders.push({ target, options }); return `widget-${renders.length}`; },
    remove(id) { removals.push(id); },
};

const first = harness(api);
const controller = first.create();
controller.connect();
await tick();
assert.equal(controller.responseTarget.value, '');
assert.equal(renders[0].options.action, 'register');
assert.equal(renders[0].options['response-field'], false);
renders[0].options.callback('fresh-token');
assert.equal(controller.responseTarget.value, 'fresh-token');
renders[0].options['expired-callback']();
assert.equal(controller.responseTarget.value, '');
assert.equal(controller.errorTarget.hidden, false);
renders[0].options.callback('new-token');
controller.beforeCache();
assert.equal(controller.responseTarget.value, '');
assert.deepEqual(removals, ['widget-1']);
renders[0].options.callback('late-token');
assert.equal(controller.responseTarget.value, '');
controller.connect();
await tick();
assert.equal(renders.length, 2);
renders[0].options.callback('late-token-after-reconnect');
assert.equal(controller.responseTarget.value, '');
controller.disconnect();

const pending = harness();
const left = pending.create();
const right = pending.create();
left.connect();
right.connect();
assert.equal(pending.scripts.length, 1);
assert.equal(pending.scripts[0].src, 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit');
left.disconnect();
pending.window.turnstile = api;
pending.scripts[0].listeners.load();
await tick();
assert.equal(renders.length, 3, 'Only the still-connected widget may render');
right.disconnect();

const failed = harness();
const retry = failed.create();
retry.connect();
failed.scripts[0].listeners.error();
await tick();
assert.equal(retry.responseTarget.value, '');
assert.equal(retry.errorTarget.hidden, false);
assert.equal(failed.scripts[0].removed, true);
retry.disconnect();
retry.connect();
assert.equal(failed.scripts.length, 2, 'A failed script load must permit a later retry');
failed.window.turnstile = api;
failed.scripts[1].listeners.load();
await tick();
retry.disconnect();
