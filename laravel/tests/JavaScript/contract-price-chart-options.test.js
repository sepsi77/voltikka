import assert from 'node:assert/strict';
import test from 'node:test';

import { logicalSeriesPointOptions } from '../../resources/js/contract-price-chart-options.js';

test('logical series points stay off unless showPoints is true', () => {
    assert.deepEqual(logicalSeriesPointOptions(undefined, '#123456'), { show: false });
    assert.deepEqual(logicalSeriesPointOptions(false, '#123456'), { show: false });
    assert.deepEqual(logicalSeriesPointOptions(true, '#123456'), {
        show: true,
        size: 4,
        width: 1.25,
        stroke: '#123456',
        fill: '#ffffff',
    });
});
