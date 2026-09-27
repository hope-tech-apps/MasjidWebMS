/**
 * Studio's mock prayer times (core/studio/mockPrayerTimes.ts): every method the
 * server offers computes, nothing is shown without the draft's own coordinates,
 * and iqama follows what the client gave. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { mockPrayerTimes, PRAYER_METHODS } from '../core/studio/mockPrayerTimes.ts';

const here = dirname(fileURLToPath(import.meta.url));
const burlington = { latitude: 43.32, longitude: -79.79, timezone: 'America/Toronto' };
const day = new Date('2026-09-24T16:00:00Z');

test('the methods are exactly PrayerCalculationMethod, and each one computes a day', () => {
    const php = readFileSync(join(here, '../../../app/Enums/PrayerCalculationMethod.php'), 'utf8');
    const served = [...php.matchAll(/case\s+\w+\s*=\s*'([^']+)'/g)].map((match) => match[1]);

    assert.deepEqual([...PRAYER_METHODS], served);
    for (const method of served) {
        const rows = mockPrayerTimes({ ...burlington, method }, day);
        assert.equal(rows?.length, 5, method);
        assert.match(rows![0].adhan, /^\d{2}:\d{2}$/);
    }
});

test('no coordinates, no timezone or no method means no times, never another city\'s', () => {
    assert.equal(mockPrayerTimes({ ...burlington, method: null }, day), null);
    assert.equal(mockPrayerTimes({ ...burlington, latitude: null, method: 'NorthAmerica' }, day), null);
    assert.equal(mockPrayerTimes({ ...burlington, timezone: 'Not/AZone', method: 'NorthAmerica' }, day), null);
});

test('iqama is adhan plus the offset given, per prayer, and absent where none was given', () => {
    const rows = mockPrayerTimes({ ...burlington, method: 'NorthAmerica', iqama: { fajr: 20 } }, day)!;
    const [hour, minute] = rows[0].adhan.split(':').map(Number);
    const expected = new Date(Date.UTC(2000, 0, 1, hour, minute + 20));

    assert.equal(rows[0].iqama, `${String(expected.getUTCHours()).padStart(2, '0')}:${String(expected.getUTCMinutes()).padStart(2, '0')}`);
    assert.equal(rows[1].iqama, null);
});

test('"client has not given iqama times" hides every iqama', () => {
    const rows = mockPrayerTimes({ ...burlington, method: 'NorthAmerica', iqama: { fajr: 20 }, iqama_given: false }, day)!;
    assert.ok(rows.every((row) => row.iqama === null));
});

test('Hanafi puts Asr later than Shafi', () => {
    const shafi = mockPrayerTimes({ ...burlington, method: 'NorthAmerica', madhab: 'Shafi' }, day)!;
    const hanafi = mockPrayerTimes({ ...burlington, method: 'NorthAmerica', madhab: 'Hanafi' }, day)!;
    assert.ok(hanafi[2].adhan > shafi[2].adhan);
});

test('a fixed iqama wins over the offset while it holds, and is adhan + minutes (blank 0) after its until-date', () => {
    const input = {
        ...burlington,
        method: 'NorthAmerica',
        iqama: { dhuhr: 10, maghrib: 5 },
        iqama_fixed: { dhuhr: '13:45', asr: '17:30' },
    };
    const plus = (clock: string, minutes: number) => {
        const [hour, minute] = clock.split(':').map(Number);
        const at = new Date(Date.UTC(2000, 0, 1, hour, minute + minutes));
        return `${String(at.getUTCHours()).padStart(2, '0')}:${String(at.getUTCMinutes()).padStart(2, '0')}`;
    };

    // The until-date is the day itself: both ends inclusive, as IqamaResolver.
    const holding = mockPrayerTimes({ ...input, iqama_fixed_until: '2026-09-24' }, day)!;
    assert.equal(holding[1].iqama, '13:45');
    assert.equal(holding[2].iqama, '17:30');
    assert.equal(holding[3].iqama, plus(holding[3].adhan, 5), 'a prayer on minutes mixes with the fixed ones');
    assert.equal(holding[0].iqama, null, 'nothing given for Fajr');

    const after = mockPrayerTimes({ ...input, iqama_fixed_until: '2026-09-23' }, day)!;
    assert.equal(after[1].iqama, plus(after[1].adhan, 10), 'the minutes given for after the date');
    assert.equal(after[2].iqama, after[2].adhan, 'no minutes given: stored as 0');

    const noDate = mockPrayerTimes(input, day)!;
    assert.equal(noDate[1].iqama, '13:45', 'the panel asks for the date; until then the time shows as typed');

    const hidden = mockPrayerTimes({ ...input, iqama_fixed_until: '2026-09-24', iqama_given: false }, day)!;
    assert.ok(hidden.every((row) => row.iqama === null));
});
