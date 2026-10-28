import assert from 'node:assert/strict';
import test from 'node:test';
import {
    ANSWERED_DAYS,
    DISMISSED_DAYS,
    FEEDBACK_STORAGE_KEY,
    feedbackPayload,
    isSuppressed,
    shuffled,
    suppress,
} from '../../resources/js/page-feedback.js';

function memoryStorage() {
    const values = new Map();

    return {
        getItem: (key) => (values.has(key) ? values.get(key) : null),
        setItem: (key, value) => values.set(key, value),
        values,
    };
}

test('shuffled keeps every item and does not change the input', () => {
    const items = ['a', 'b', 'c', 'd', 'e'];
    const result = shuffled(items, () => 0);

    assert.deepEqual([...result].sort(), items);
    assert.deepEqual(items, ['a', 'b', 'c', 'd', 'e']);
    assert.notDeepEqual(result, items);
});

test('an answer hides the question for 30 days and a dismissal for 7 days', () => {
    const now = Date.UTC(2026, 9, 28);
    const storage = memoryStorage();

    assert.equal(isSuppressed(storage, now), false);

    suppress(storage, DISMISSED_DAYS, now);
    assert.equal(isSuppressed(storage, now + 6 * 86_400_000), true);
    assert.equal(isSuppressed(storage, now + 8 * 86_400_000), false);

    suppress(storage, ANSWERED_DAYS, now);
    assert.equal(isSuppressed(storage, now + 29 * 86_400_000), true);
    assert.equal(isSuppressed(storage, now + 31 * 86_400_000), false);
    assert.deepEqual(Object.keys(JSON.parse(storage.values.get(FEEDBACK_STORAGE_KEY))), ['until']);
});

test('blocked or broken storage never throws', () => {
    const blocked = {
        getItem() { throw new Error('blocked'); },
        setItem() { throw new Error('blocked'); },
    };

    assert.equal(isSuppressed(blocked), false);
    assert.doesNotThrow(() => suppress(blocked, ANSWERED_DAYS));
    assert.equal(isSuppressed({ getItem: () => '{not json' }), false);
});

test('the payload carries listing facts only with the vote', () => {
    const list = { dataset: { feedbackFacts: '{"consumption_kwh":5000,"bill_mode":false}' } };
    const root = {
        dataset: { feedbackContext: 'signed.token' },
        closest: (selector) => (selector === '[data-feedback-facts]' ? list : null),
    };
    const base = {
        root,
        state: { uuid: 'uuid-1' },
        attribution: { source: 'direct', medium: '(none)', campaign: null, landing_path: '/' },
        pathname: '/sahkosopimus',
        device: 'mobile',
    };

    const vote = feedbackPayload({ ...base, step: 'vote', answer: { vote: 'down' } });
    assert.equal(vote.event_name, 'page_feedback');
    assert.equal(vote.context, 'signed.token');
    assert.equal(vote.vote, 'down');
    assert.deepEqual(vote.facts, { consumption_kwh: 5000, bill_mode: false });

    const reason = feedbackPayload({ ...base, step: 'reason', answer: { choice: 'hard_to_use', position: 2, text: null } });
    assert.equal(reason.facts, undefined);
    assert.equal(reason.position, 2);
});
