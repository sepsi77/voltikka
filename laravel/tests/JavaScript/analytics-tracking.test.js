import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const trackingScriptPath = fileURLToPath(
    new URL('../../resources/js/analytics-tracking.js', import.meta.url),
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

test('Livewire payloads become voltikka_event pushes in the GTM data layer', () => {
    const documentListeners = new Map();
    const windowListeners = new Map();
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

    const pushes = () => JSON.parse(JSON.stringify(sandbox.dataLayer));

    assert.deepEqual(pushes(), [
        { event_props: null },
        {
            event: 'voltikka_event',
            event_name: 'Bill Comparison Completed',
            event_props: {
                source: 'contract_listing',
                period_preset: 'custom',
            },
        },
    ]);

    trackListener([{
        eventName: 'Legacy Event',
        props: { source: 'legacy' },
    }]);
    trackListener({ eventName: 'No Props' });

    assert.deepEqual(pushes().slice(2), [
        { event_props: null },
        { event: 'voltikka_event', event_name: 'Legacy Event', event_props: { source: 'legacy' } },
        { event_props: null },
        { event: 'voltikka_event', event_name: 'No Props', event_props: {} },
    ]);
});
