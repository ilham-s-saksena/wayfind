import assert from 'node:assert/strict';
import test from 'node:test';
import { confirmArrival, formatDistance } from './navigationGuidance.js';

test('confirms arrival only after three accurate, distinct fixes over five seconds', () => {
    const metrics = { remainingDistance: 4, destinationDistance: 5, progress: 0.98, accuracy: 8 };

    const first = confirmArrival(null, { ...metrics, timestamp: 1000 }, 1000);
    const duplicate = confirmArrival(first, { ...metrics, timestamp: 1000 }, 1000);
    const second = confirmArrival(duplicate, { ...metrics, timestamp: 4000 }, 4000);
    const third = confirmArrival(second, { ...metrics, timestamp: 7000 }, 7000);

    assert.equal(first.arrived, false);
    assert.equal(duplicate.count, 1);
    assert.equal(second.arrived, false);
    assert.equal(third.arrived, true);
});

test('rejects inaccurate, stale, invalid, distant and incomplete arrival readings', () => {
    const near = { remainingDistance: 4, destinationDistance: 5, progress: 0.98, accuracy: 8, timestamp: 40000 };
    const invalid = [
        { accuracy: 50 }, { accuracy: null }, { accuracy: 0 }, { timestamp: 1000 },
        { remainingDistance: 60 }, { destinationDistance: 60 }, { progress: 0.5 }, { progress: NaN },
    ];

    for (const overrides of invalid) {
        const result = confirmArrival({ count: 2, startedAt: 1000, timestamp: 2000 }, { ...near, ...overrides }, 40000);
        assert.equal(result.count, 0);
        assert.equal(result.arrived, false);
    }
});

test('formats walking distances with Indonesian decimal notation', () => {
    assert.equal(formatDistance(128.4), '128 m');
    assert.equal(formatDistance(1250), '1,3 km');
});

test('starts arrival confirmation again after a gap in fresh position readings', () => {
    const result = confirmArrival({ count: 2, startedAt: 1000, timestamp: 4000 }, {
        remainingDistance: 4, destinationDistance: 5, progress: 0.98, accuracy: 8, timestamp: 60000,
    }, 60000);

    assert.equal(result.count, 1);
    assert.equal(result.startedAt, 60000);
    assert.equal(result.arrived, false);
});
