/**
 * The weekly points view's helpers (core/helpers/pointsWeek.ts): which figure a
 * class leads with, and a week's label formatted from the server's calendar dates
 * with no browser-zone drift.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { isWeekly, pointsHeadline, signedPoints, weekFromQuery, weekRangeLabel } from '../core/helpers/pointsWeek.ts';

test('only the word weekly makes a class weekly', () => {
    assert.equal(isWeekly('weekly'), true);
    assert.equal(isWeekly('running'), false);
    assert.equal(isWeekly(null), false);
    assert.equal(isWeekly(undefined), false);
    assert.equal(isWeekly('WEEKLY'), false);
});

test('a weekly class leads with the week and keeps the running figure beside it', () => {
    const row = { points: 40, awards: 12, week_points: 5, week_awards: 2 };
    assert.deepEqual(pointsHeadline('weekly', row), {
        lead: 'week', points: 5, awards: 2, other: { points: 40, awards: 12 },
    });
});

test('every other class leads with the running total and shows the week second', () => {
    const row = { points: 40, awards: 12, week_points: 5, week_awards: 2 };
    for (const period of ['running', null, undefined, 'sideways']) {
        assert.deepEqual(pointsHeadline(period, row), {
            lead: 'running', points: 40, awards: 12, other: { points: 5, awards: 2 },
        });
    }
});

test('a missing row reads as zeros, not as NaN or invented data', () => {
    assert.deepEqual(pointsHeadline('weekly', null), { lead: 'week', points: 0, awards: 0, other: { points: 0, awards: 0 } });
    assert.deepEqual(pointsHeadline('weekly', {}), { lead: 'week', points: 0, awards: 0, other: { points: 0, awards: 0 } });
});

test('a negative week keeps its minus sign and a zero stays a bare zero', () => {
    assert.equal(signedPoints(-2), '-2');
    assert.equal(signedPoints(3), '+3');
    assert.equal(signedPoints(0), '0');
    assert.equal(signedPoints(null), '0');
});

test('a week label is built from the server dates and does not move with the browser zone', () => {
    const saved = process.env.TZ;
    try {
        for (const tz of ['UTC', 'America/Los_Angeles', 'Pacific/Kiritimati']) {
            process.env.TZ = tz;
            assert.equal(weekRangeLabel('2026-10-04', '2026-10-10', 'en'), 'Oct 4 - Oct 10, 2026', tz);
        }
    } finally {
        if (saved === undefined) delete process.env.TZ; else process.env.TZ = saved;
    }
});

test('a week across new year names both years', () => {
    assert.equal(weekRangeLabel('2026-12-27', '2027-01-02', 'en'), 'Dec 27, 2026 - Jan 2, 2027');
});

test('a value that is not a date is printed as given rather than as Invalid Date', () => {
    assert.equal(weekRangeLabel('soon', '2026-10-10'), 'soon - 2026-10-10');
    assert.equal(weekRangeLabel('2026-02-30', '2026-03-05'), '2026-02-30 - 2026-03-05');
});

test('a linked week is one real calendar day, else the week in progress', () => {
    assert.equal(weekFromQuery('2026-10-04'), '2026-10-04');
    assert.equal(weekFromQuery('2028-02-29'), '2028-02-29');

    // Not a date, not a real date, a repeated key, an empty value, a sentence: null = "current".
    for (const bad of [undefined, null, '', 'current', '2026-02-30', '2026-13-01', '2026-10-4', '2026-10-04 ', ' 2026-10-04',
        '2026-10-04&x=1', ['2026-10-04'], ['2026-10-04', '2026-10-11'], 20261004, {}]) {
        assert.equal(weekFromQuery(bad), null, JSON.stringify(bad));
    }
});

test('the teacher\'s Points tab opens on the week the email reported and loads on landing', () => {
    const view = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');

    // The link's week is read only together with ?tab=points, through the validator.
    assert.match(view, /linkedPointsWeek = route\.query\.tab === 'points' \? weekFromQuery\(route\.query\.week\) : null/);
    // Landing on the tab is not a tab change, so the watch never fires for it: the mount hook loads it.
    assert.match(view, /onMounted\(\(\) => \{\s*if \(activeTab\.value !== 'points'\) return;\s*loadSkills\(\);\s*loadPointsTotals\(linkedPointsWeek\);\s*\}\);/);
});
