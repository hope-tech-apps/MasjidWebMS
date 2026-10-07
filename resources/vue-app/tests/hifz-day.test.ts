import { test } from 'node:test';
import assert from 'node:assert/strict';
import { hifzDayLabel, hifzDayOf, hifzDayToSend } from '../core/helpers/hifzDay.ts';

/**
 * The day a recitation was heard. A day chosen in the date box used to be sent as
 * a bare date, stored as midnight UTC, and shown in the reader's own zone: in the
 * Americas, the day before the one the teacher picked.
 */
const inZone = <T>(tz: string, run: () => T): T => {
    const before = process.env.TZ;
    process.env.TZ = tz;
    try { return run(); } finally { if (before === undefined) delete process.env.TZ; else process.env.TZ = before; }
};

for (const tz of ['America/New_York', 'America/Los_Angeles', 'Pacific/Honolulu', 'UTC', 'Asia/Karachi', 'Pacific/Auckland']) {
    test(`a chosen day reads as that day in ${tz}, whether it was stored at midnight UTC (old rows) or noon UTC (new)`, () => {
        inZone(tz, () => {
            // Every row written before the fix: a bare date, stored as midnight UTC.
            assert.equal(hifzDayOf('2026-09-17T00:00:00+00:00'), '2026-09-17');
            assert.equal(hifzDayLabel('2026-09-17T00:00:00+00:00', 'en-US'), 'Sep 17, 2026');
            // What the form sends now.
            assert.equal(hifzDayToSend('2026-10-05'), '2026-10-05T12:00:00Z');
            assert.equal(hifzDayOf(hifzDayToSend('2026-10-05')), '2026-10-05');
            assert.equal(hifzDayLabel('2026-10-05T12:00:00+00:00', 'en-US'), 'Oct 5, 2026');
            // The first and last days of a month and a year do not slip either.
            assert.equal(hifzDayOf('2027-01-01T00:00:00Z'), '2027-01-01');
            assert.equal(hifzDayOf('2026-12-31T12:00:00Z'), '2026-12-31');
        });
    });
}

test('a recitation recorded as it was heard keeps the reader\'s own day', () => {
    inZone('America/New_York', () => {
        // 9:30 in the evening on 6 October in New York is already the 7th in UTC.
        assert.equal(hifzDayOf('2026-10-07T01:30:00+00:00'), '2026-10-06');
        assert.equal(hifzDayLabel('2026-10-07T01:30:00+00:00', 'en-US'), 'Oct 6, 2026');
        assert.equal(hifzDayOf('2026-10-06T14:43:14+00:00'), '2026-10-06');
    });
});

test('no instant, or an unreadable one, is no day', () => {
    assert.equal(hifzDayOf(null), '');
    assert.equal(hifzDayOf(''), '');
    assert.equal(hifzDayOf('not a date'), '');
    assert.equal(hifzDayLabel(undefined), '');
});
