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
            const later = new Date('2026-10-20T15:00:00Z');
            assert.equal(hifzDayToSend('2026-10-05', later), '2026-10-05T12:00:00Z');
            assert.equal(hifzDayOf(hifzDayToSend('2026-10-05', later)), '2026-10-05');
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

test('a recording made at exactly midnight or noon UTC is a real moment when the row says it was typed that second', () => {
    inZone('America/New_York', () => {
        // 8 pm on 6 October in New York, recorded as it was heard.
        assert.equal(hifzDayOf('2026-10-07T00:00:00+00:00', '2026-10-07T00:00:01+00:00'), '2026-10-06');
        assert.equal(hifzDayLabel('2026-10-07T00:00:00+00:00', 'en-US', '2026-10-07T00:00:00+00:00'), 'Oct 6, 2026');
        // The same instant typed in days later is a date somebody chose: the 7th.
        assert.equal(hifzDayOf('2026-10-07T00:00:00+00:00', '2026-10-09T14:00:00+00:00'), '2026-10-07');
        // A payload with no created_at (the family's) falls back to the date reading.
        assert.equal(hifzDayOf('2026-10-07T00:00:00+00:00'), '2026-10-07');
    });
});

test('a chosen day is never sent as an instant the server would refuse as the future', () => {
    // The Americas, mid-morning: noon UTC of yesterday is long past.
    assert.equal(hifzDayToSend('2026-10-06', new Date('2026-10-07T14:00:00Z')), '2026-10-06T12:00:00Z');
    // Auckland, half past midnight on the 7th (11:30 UTC on the 6th), choosing yesterday the 6th:
    // noon UTC of the 6th has not happened yet, midnight UTC of the 6th has.
    const auckland = new Date('2026-10-06T11:30:00Z');
    assert.equal(hifzDayToSend('2026-10-06', auckland), '2026-10-06');
    inZone('Pacific/Auckland', () => assert.equal(hifzDayOf('2026-10-06T00:00:00+00:00'), '2026-10-06'));
    // The same moment, choosing the reader's own today (the 7th there): neither has
    // happened, so a minute ago, which reads as that local day.
    const sent = hifzDayToSend('2026-10-07', auckland);
    assert.equal(sent, '2026-10-06T11:29:00.000Z');
    assert.ok(new Date(sent).getTime() < auckland.getTime());
    inZone('Pacific/Auckland', () => assert.equal(hifzDayOf(sent), '2026-10-07'));
    // New York, 7 am on the 7th, changing a line's day to today: noon UTC is ahead, midnight UTC is behind.
    assert.equal(hifzDayToSend('2026-10-07', new Date('2026-10-07T11:00:00Z')), '2026-10-07');
});
