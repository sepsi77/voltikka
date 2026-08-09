import assert from 'node:assert/strict';
import test from 'node:test';

import {
    latestNonNullPointIndices,
    logicalSeriesPointOptions,
} from '../../resources/js/contract-price-chart-options.js';

test('logical series points stay off unless a point mode is enabled', () => {
    assert.deepEqual(logicalSeriesPointOptions(undefined, '#123456'), { show: false });
    assert.deepEqual(logicalSeriesPointOptions(false, '#123456'), { show: false });
    assert.deepEqual(logicalSeriesPointOptions(true, '#123456'), {
        show: true,
        size: 4,
        width: 1.25,
        stroke: '#123456',
        fill: '#ffffff',
    });

    const latestOnly = logicalSeriesPointOptions(false, '#123456', true);
    assert.equal(latestOnly.show, true);
    assert.equal(latestOnly.filter, latestNonNullPointIndices);
    assert.deepEqual(latestOnly.filter({ data: [[], [10, null, 20, null]] }, 1), [2]);
    assert.deepEqual(latestOnly.filter({ data: [[], [null, null]] }, 1), []);
});
