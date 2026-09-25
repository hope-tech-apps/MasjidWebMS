/**
 * What the Iqama Times screen saves (views/dashboard/iqamaSettingsForm.ts), and
 * the two guards in the view that keep it from saving what it never loaded.
 * Run: npm run test:spa
 */

// West of UTC, where new Date("YYYY-MM-DD") is the previous day on the wall.
// node --test runs each file in its own process, so this reaches no other test.
process.env.TZ = 'America/New_York';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { SALAH_KEYS, formatDate, iqamaSavePayload, parseLocalDate } from '../views/dashboard/iqamaSettingsForm.ts';

const offsets = { fajr: 20, dhuhr: 10, asr: 10, maghrib: 5, isha: 10 };
const noRanges = { fajr: [], dhuhr: [], asr: [], maghrib: [], isha: [] };

test('a stored range date comes back as the same date west of UTC', () => {
    // The premise: the old parse really is a day early in this zone.
    assert.equal(formatDate(new Date('2026-09-21')), '2026-09-20');

    for (const ymd of ['2026-09-21', '2026-10-31', '2026-11-01', '2026-03-08']) {
        assert.equal(formatDate(parseLocalDate(ymd)), ymd, ymd);
    }
    // The API may send a full timestamp; the date part is what counts.
    assert.equal(formatDate(parseLocalDate('2026-10-31T00:00:00.000000Z')), '2026-10-31');
});

test('a Specific Time Ranges save carries all five offsets, zeros included, and the complete ranges', () => {
    const payload = iqamaSavePayload({
        iqamaType: 'specific_time_ranges',
        showIqamaTimes: true,
        offsets: { fajr: 0, dhuhr: 0, asr: 0, maghrib: 5, isha: 5 },
        timeRanges: {
            ...noRanges,
            dhuhr: [
                { start_date: '2026-09-21', end_date: '2026-10-31', specific_time: '13:45' },
                { start_date: '2026-11-01', end_date: '', specific_time: '13:15' },
            ],
            isha: [{ start_date: '2026-09-21', end_date: '2026-10-31', specific_time: '20:45' }],
        },
    });

    assert.deepEqual(payload, {
        iqama_type: 'specific_time_ranges',
        show_iqama_times: true,
        fajr: 0, dhuhr: 0, asr: 0, maghrib: 5, isha: 5,
        time_ranges: [
            { salah: 'dhuhr', start_date: '2026-09-21', end_date: '2026-10-31', specific_time: '13:45' },
            { salah: 'isha', start_date: '2026-09-21', end_date: '2026-10-31', specific_time: '20:45' },
        ],
    });
});

test('a Minutes After Adhan save carries the offsets and no ranges', () => {
    const payload = iqamaSavePayload({
        iqamaType: 'minutes_after_adhan',
        showIqamaTimes: false,
        offsets,
        timeRanges: { ...noRanges, isha: [{ start_date: '2026-09-21', end_date: '2026-10-31', specific_time: '20:45' }] },
    });

    assert.deepEqual(payload, { iqama_type: 'minutes_after_adhan', show_iqama_times: false, ...offsets });
});

test('the view uses these helpers and guards Save on the settings having loaded', () => {
    const view = readFileSync(new URL('../views/dashboard/IqamaTimeSettingsView.vue', import.meta.url), 'utf8');

    // The offsets render once the GET has answered, even with "no row yet": gating them
    // on the row itself left the schema's required fields unrendered, and Save did nothing.
    assert.match(
        view,
        /<template v-if="loadState === 'ready'">\s*<tr v-for="key in SALAH_KEYS"/,
        'the offset rows are gated on loadState, not on the row',
    );

    // A failed or unfinished load must never save defaults over settings it could not read.
    assert.match(
        view,
        /const onSubmit = async \(\) => \{\s*if \(loadState\.value !== 'ready'\) \{\s*return;\s*\}/,
        'onSubmit refuses until the settings have loaded',
    );

    assert.match(view, /iqamaSavePayload\(\{/, 'the view builds its body with iqamaSavePayload');
    assert.match(view, /parseLocalDate\(startDate\), parseLocalDate\(endDate\)/, 'the picker dates are parsed at local midnight');
    assert.doesNotMatch(view, /new Date\(startDate\), new Date\(endDate\)/);
    assert.deepEqual([...SALAH_KEYS], ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha']);
});
