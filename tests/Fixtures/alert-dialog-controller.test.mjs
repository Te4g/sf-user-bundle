import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(process.argv[2], 'utf8')
    .replace(/^import .*;\n/u, '')
    .replace('export default class extends Controller', 'return class extends Controller');
const Controller = class {};
const AlertDialogController = new Function('Controller', source)(Controller);
const events = [];
const controller = new AlertDialogController();

controller.dialogTarget = {
    open: true,
    close() {
        events.push('close');
        this.open = false;
    },
};
controller.triggerTarget = {
    setAttribute(name, value) {
        events.push(`${name}:${value}`);
    },
    focus() {
        events.push('focus');
    },
};
controller.formTarget = {
    requestSubmit() {
        assert.equal(controller.dialogTarget.open, false, 'the dialog must not keep invalid external controls inert');
        events.push('requestSubmit');
    },
};

controller.submit();

assert.deepEqual(events, ['close', 'aria-expanded:false', 'focus', 'requestSubmit']);
