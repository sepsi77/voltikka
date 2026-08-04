import assert from 'node:assert/strict';
import test from 'node:test';
import {
    ATTRIBUTION_STORAGE_KEY,
    ATTRIBUTION_TIMEOUT_MS,
    createAttributionManager,
    resolveAttribution,
} from '../../resources/js/attribution.js';
import { createContractOrderClickTracker } from '../../resources/js/first-party-analytics.js';

function memoryStorage(values = new Map()) {
    return {
        getItem(key) {
            return values.has(key) ? values.get(key) : null;
        },
        setItem(key, value) {
            values.set(key, value);
        },
        removeItem(key) {
            values.delete(key);
        },
        values,
    };
}

function browserWindow(url, referrer = '', storage = memoryStorage()) {
    return {
        location: new URL(url),
        document: { referrer },
        localStorage: storage,
    };
}

test('UTM values have precedence and are normalized', () => {
    const windowObject = browserWindow(
        'https://voltikka.fi/sahkosopimus?utm_source=%20NEWSLETTER%20&utm_medium=%20Paid%20%20Social%20&utm_campaign=KES%C3%84%202026#offer',
        'https://www.google.fi/search?q=sahko',
    );

    assert.deepEqual(resolveAttribution(windowObject), {
        source: 'newsletter',
        medium: 'paid social',
        campaign: 'kesä 2026',
        landing_path: '/sahkosopimus',
    });
});

test('organic, referral, direct, and same-origin traffic are classified safely', () => {
    assert.deepEqual(
        resolveAttribution(browserWindow('https://voltikka.fi/google', 'https://www.google.co.uk/search?q=sahko')),
        { source: 'google', medium: 'organic', campaign: null, landing_path: '/google' },
    );
    assert.deepEqual(
        resolveAttribution(browserWindow('https://voltikka.fi/a', 'https://search.brave.com/search?q=sahko')),
        { source: 'brave', medium: 'organic', campaign: null, landing_path: '/a' },
    );
    assert.deepEqual(
        resolveAttribution(browserWindow('https://voltikka.fi/b', 'https://Blog.Example.com/post?secret=yes')),
        { source: 'blog.example.com', medium: 'referral', campaign: null, landing_path: '/b' },
    );
    assert.deepEqual(
        resolveAttribution(browserWindow('https://voltikka.fi/c')),
        { source: 'direct', medium: '(none)', campaign: null, landing_path: '/c' },
    );
    assert.deepEqual(
        resolveAttribution(browserWindow('https://voltikka.fi/d?private=yes', 'https://voltikka.fi/c?old=yes')),
        { source: 'direct', medium: '(none)', campaign: null, landing_path: '/d' },
    );
});

test('an active session keeps first touch and updates only activity time', () => {
    let time = 1_000;
    const windowObject = browserWindow(
        'https://voltikka.fi/landing',
        'https://www.google.com/search?q=sahko',
    );
    const manager = createAttributionManager(windowObject, { now: () => time });
    const first = manager.refresh();

    windowObject.location = new URL('https://voltikka.fi/next');
    windowObject.document.referrer = 'https://voltikka.fi/landing';
    time += 20 * 60 * 1000;

    const next = manager.refresh();

    assert.equal(next.source, 'google');
    assert.equal(next.medium, 'organic');
    assert.equal(next.landing_path, '/landing');
    assert.equal(next.started_at, first.started_at);
    assert.equal(next.last_activity_at, time);
});

test('the inactivity timeout is strict and a changed UTM campaign restarts the session', () => {
    let time = 2_000;
    const windowObject = browserWindow('https://voltikka.fi/start');
    const manager = createAttributionManager(windowObject, { now: () => time });
    const first = manager.refresh();

    time += ATTRIBUTION_TIMEOUT_MS;
    const boundary = manager.refresh();
    assert.equal(boundary.started_at, first.started_at);

    time += ATTRIBUTION_TIMEOUT_MS + 1;
    windowObject.location = new URL('https://voltikka.fi/expired');
    const expired = manager.refresh();
    assert.equal(expired.started_at, time);
    assert.equal(expired.landing_path, '/expired');

    time += 10;
    windowObject.location = new URL('https://voltikka.fi/campaign?utm_source=Email&utm_campaign=August');
    const campaign = manager.refresh();
    assert.equal(campaign.started_at, time);
    assert.equal(campaign.source, 'email');
    assert.equal(campaign.medium, '(none)');
    assert.equal(campaign.campaign, 'august');
    assert.equal(campaign.landing_path, '/campaign');
});

test('malformed local storage is replaced with a valid direct attribution', () => {
    const storage = memoryStorage(new Map([[ATTRIBUTION_STORAGE_KEY, '{bad json']]));
    const manager = createAttributionManager(browserWindow('https://voltikka.fi/safe', '', storage), {
        now: () => 5_000,
    });

    const attribution = manager.refresh();
    const stored = JSON.parse(storage.getItem(ATTRIBUTION_STORAGE_KEY));

    assert.equal(attribution.source, 'direct');
    assert.equal(stored.version, 1);
    assert.equal(stored.landing_path, '/safe');
});

test('blocked local storage uses current-document memory without losing first touch', () => {
    let time = 10_000;
    const blockedStorage = {
        getItem() { throw new Error('blocked'); },
        setItem() { throw new Error('blocked'); },
        removeItem() { throw new Error('blocked'); },
    };
    const windowObject = browserWindow(
        'https://voltikka.fi/first',
        'https://duckduckgo.com/?q=sahko',
        blockedStorage,
    );
    const manager = createAttributionManager(windowObject, { now: () => time });

    manager.refresh();
    time += 1_000;
    windowObject.location = new URL('https://voltikka.fi/second');
    windowObject.document.referrer = 'https://voltikka.fi/first';

    assert.deepEqual(manager.eventAttribution(), {
        source: 'duckduckgo',
        medium: 'organic',
        campaign: null,
        landing_path: '/first',
    });
});

test('Beacon sends the event envelope and does not use fetch when accepted', async () => {
    const beaconCalls = [];
    const fetchCalls = [];
    const windowObject = {
        Blob,
        crypto: { randomUUID: () => '11111111-1111-4111-8111-111111111111' },
        location: { pathname: '/sahkosopimus/sopimus/example' },
        navigator: {
            sendBeacon(endpoint, blob) {
                beaconCalls.push({ endpoint, blob });
                return true;
            },
        },
        fetch(...args) {
            fetchCalls.push(args);
            return Promise.resolve();
        },
    };
    const tracker = createContractOrderClickTracker({
        windowObject,
        attributionManager: {
            eventAttribution: () => ({
                source: 'google', medium: 'organic', campaign: null, landing_path: '/landing',
            }),
        },
    });

    tracker.trackContractOrderClick({ context: 'signed', placement: 'hero' });

    assert.equal(beaconCalls.length, 1);
    assert.equal(fetchCalls.length, 0);
    assert.equal(beaconCalls[0].endpoint, '/api/analytics/events');
    assert.deepEqual(JSON.parse(await beaconCalls[0].blob.text()), {
        event_name: 'contract_order_click',
        event_uuid: '11111111-1111-4111-8111-111111111111',
        context: 'signed',
        attribution: {
            source: 'google', medium: 'organic', campaign: null, landing_path: '/landing',
        },
        page_path: '/sahkosopimus/sopimus/example',
        placement: 'hero',
    });
});

test('rejected Beacon uses keepalive fetch', () => {
    const fetchCalls = [];
    const windowObject = {
        Blob,
        crypto: { randomUUID: () => '22222222-2222-4222-8222-222222222222' },
        location: { pathname: '/contract' },
        navigator: { sendBeacon: () => false },
        fetch(endpoint, options) {
            fetchCalls.push({ endpoint, options });
            return Promise.resolve();
        },
    };
    const tracker = createContractOrderClickTracker({
        windowObject,
        attributionManager: {
            eventAttribution: () => ({
                source: 'direct', medium: '(none)', campaign: null, landing_path: '/',
            }),
        },
    });

    tracker.trackContractOrderClick({ context: 'signed', placement: 'sticky' });

    assert.equal(fetchCalls.length, 1);
    assert.equal(fetchCalls[0].endpoint, '/api/analytics/events');
    assert.equal(fetchCalls[0].options.method, 'POST');
    assert.equal(fetchCalls[0].options.keepalive, true);
    assert.equal(fetchCalls[0].options.credentials, 'same-origin');
});

test('delivery failure does not cancel normal seller navigation', () => {
    let prevented = false;
    const anchor = { href: 'https://seller.example/order' };
    const windowObject = {
        Blob,
        crypto: {},
        location: { pathname: '/contract' },
        navigator: { sendBeacon: () => { throw new Error('offline'); } },
        fetch() { throw new Error('offline'); },
    };
    const tracker = createContractOrderClickTracker({
        windowObject,
        attributionManager: { eventAttribution: () => { throw new Error('storage blocked'); } },
    });

    assert.doesNotThrow(() => tracker.trackContractOrderClick(
        { context: 'signed', placement: 'hero' },
        { preventDefault() { prevented = true; } },
    ));
    assert.equal(prevented, false);
    assert.equal(anchor.href, 'https://seller.example/order');
});
