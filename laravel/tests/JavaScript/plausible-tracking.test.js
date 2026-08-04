import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const trackingScriptPath = fileURLToPath(
    new URL('../../resources/js/plausible-tracking.js', import.meta.url),
);

function addListener(listeners, eventName, callback) {
    const callbacks = listeners.get(eventName) || [];
    callbacks.push(callback);
    listeners.set(eventName, callbacks);
}

function dispatch(listeners, eventName) {
    for (const callback of listeners.get(eventName) || []) {
        callback();
    }
}

test('Livewire object payload forwards its event name and props to Plausible', () => {
    const documentListeners = new Map();
    const windowListeners = new Map();
    const plausibleCalls = [];
    let trackListener = null;

    const sandbox = {
        document: {
            hidden: true,
            addEventListener(eventName, callback) {
                addListener(documentListeners, eventName, callback);
            },
            removeEventListener() {},
        },
        Livewire: {
            on(eventName, callback) {
                assert.equal(eventName, 'track');
                trackListener = callback;
            },
        },
        plausible(eventName, options) {
            plausibleCalls.push({ eventName, options });
        },
        addEventListener(eventName, callback) {
            addListener(windowListeners, eventName, callback);
        },
        removeEventListener() {},
        setTimeout() {
            throw new Error('The hidden document must not start an engagement timer.');
        },
        clearTimeout() {},
    };

    sandbox.window = sandbox;

    const context = vm.createContext(sandbox);
    const source = readFileSync(trackingScriptPath, 'utf8');
    new vm.Script(source, { filename: trackingScriptPath }).runInContext(context);

    dispatch(documentListeners, 'livewire:init');
    assert.equal(typeof trackListener, 'function');

    trackListener({
        eventName: 'Bill Comparison Completed',
        props: {
            source: 'contract_listing',
            period_preset: 'custom',
        },
    });

    assert.equal(plausibleCalls.length, 1);
    assert.equal(plausibleCalls[0].eventName, 'Bill Comparison Completed');
    assert.deepEqual(JSON.parse(JSON.stringify(plausibleCalls[0].options)), {
        props: {
            source: 'contract_listing',
            period_preset: 'custom',
        },
    });

    trackListener([{
        eventName: 'Legacy Event',
        props: { source: 'legacy' },
    }]);

    assert.equal(plausibleCalls[1].eventName, 'Legacy Event');
    assert.deepEqual(JSON.parse(JSON.stringify(plausibleCalls[1].options)), {
        props: { source: 'legacy' },
    });
});
