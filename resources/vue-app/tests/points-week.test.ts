/**
 * The weekly points view's helpers (core/helpers/pointsWeek.ts): which figure a
 * class leads with, and a week's label formatted from the server's calendar dates
 * with no browser-zone drift.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { isWeekly, pointsHeadline, showsThisWeek, signedPoints, weekFromQuery, weekRangeLabel, weeklyReportOn } from '../core/helpers/pointsWeek.ts';

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

// ---------------------------------------------------------------- review F1: capability off = nothing visible

test('the "This week" line is for a class that opted in to the weekly view and no other', () => {
    assert.equal(showsThisWeek({ points_period: 'weekly' }), true);

    for (const group of [{ points_period: 'running' }, { points_period: null }, {}, null, undefined, { points_period: 'WEEKLY' }]) {
        assert.equal(showsThisWeek(group as any), false, JSON.stringify(group));
    }
});

test('the weekly report is there only where the school says it is on; an older or failed payload reads as off', () => {
    assert.equal(weeklyReportOn({ weekly_report: true }), true);

    for (const group of [{ weekly_report: false }, { weekly_report: null }, {}, null, undefined, { weekly_report: 'true' }, { weekly_report: 1 }]) {
        assert.equal(weeklyReportOn(group as any), false, JSON.stringify(group));
    }
});

test('the class screen and the report page go through those two answers', () => {
    const cls = readFileSync(new URL('../views/family/FamilyClass.vue', import.meta.url), 'utf8');
    const report = readFileSync(new URL('../views/family/FamilyWeeklyReport.vue', import.meta.url), 'utf8');

    // The block needs the class's opt-in; the link to the report needs the school's switch.
    assert.match(cls, /<div v-if="showsThisWeek\(group\) && points\[child\.membership_id\]\?\.week"/);
    assert.match(cls, /<div v-if="weeklyReportOn\(group\)" class="mb-3">\s*<router-link :to="`\/family\/\$\{masjidId\}\/classes\/\$\{groupId\}\/report`"/);
    // A class that will not show the week is not asked for one.
    assert.match(cls, /setPoints: showsThisWeek\(group\.value\) \?/);
    // No other link to the report anywhere on the screen.
    assert.equal((cls.match(/\/report`/g) ?? []).length, 1);

    // The page itself: off means back to the class screen, before any week is read.
    assert.match(report, /if \(!weeklyReportOn\(group\.value\)\) \{\s*router\.replace\(`\/family\/\$\{masjidId\.value\}\/classes\/\$\{groupId\.value\}`\);\s*return;\s*\}/);
    assert.ok(report.indexOf('weeklyReportOn(group.value)') < report.indexOf('await loadWeek(run'), 'checked before the first read');
});

// ---------------------------------------------------------------- review G1: the report link follows the school's grant, not the class's opt-in

/** The `<div ...>` that starts at `from`, through its own closing tag: nested divs are counted, so it is the whole block. */
function divBlock(source: string, from: number): string {
    let depth = 0;
    for (const m of source.slice(from).matchAll(/<div\b|<\/div>/g)) {
        depth += m[0] === '</div>' ? -1 : 1;
        if (depth === 0) return source.slice(from, from + m.index! + m[0].length);
    }
    throw new Error('unclosed <div>');
}

test('the family class screen answers the block and the link separately, for every school and class', () => {
    const screen = (group: { points_period?: string; weekly_report?: boolean }) => ({ block: showsThisWeek(group), link: weeklyReportOn(group) });

    // A granted school with a class that has not opted in: the link, and no "This week" block. The Friday
    // email goes to every class in a granted school, so this family must not be left without its page.
    assert.deepEqual(screen({ weekly_report: true, points_period: 'running' }), { block: false, link: true });
    // An ungranted school with a class that has not opted in: neither.
    assert.deepEqual(screen({ weekly_report: false, points_period: 'running' }), { block: false, link: false });
    // A payload without the school's answer (older server, failed read) reads as ungranted.
    assert.deepEqual(screen({ points_period: 'running' }), { block: false, link: false });
    // The class's own opt-in still decides the block, and only the block.
    assert.deepEqual(screen({ weekly_report: true, points_period: 'weekly' }), { block: true, link: true });
    assert.deepEqual(screen({ weekly_report: false, points_period: 'weekly' }), { block: true, link: false });
});

test('the report link is not inside the "This week" block, so the block\'s opt-in cannot hide it', () => {
    const cls = readFileSync(new URL('../views/family/FamilyClass.vue', import.meta.url), 'utf8');

    const start = cls.indexOf('<div v-if="showsThisWeek(group) && points[child.membership_id]?.week"');
    assert.notEqual(start, -1, 'the block is there');
    const block = divBlock(cls, start);
    assert.ok(block.length > 300 && block.includes('points_this_week'), 'the whole block was read');

    // Not one link to the report in it, and the one link there is sits after it, gated on the grant alone.
    assert.doesNotMatch(block, /\/report`/);
    assert.doesNotMatch(block, /weeklyReportOn/);
    const link = cls.indexOf('<div v-if="weeklyReportOn(group)"');
    assert.ok(link > start + block.length, 'the link comes after the block ends');
    assert.equal(divBlock(cls, link).includes('/report`'), true);
    assert.doesNotMatch(divBlock(cls, link), /showsThisWeek/, 'and does not ask about the class\'s opt-in');
});
