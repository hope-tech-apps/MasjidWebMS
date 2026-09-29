/**
 * "Send later" on a class story and a new conversation (T-002.4): the school's own
 * clock, the bounds the field offers, the request fields, and the Scheduled list's rows.
 *
 * `.vue` files cannot be loaded by `node --test`, so the wiring is pinned as source text,
 * the way story-seen.test.ts does it.
 *
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = (rel: string) => readFileSync(path.join(appRoot, rel), 'utf8');
const load = () => import(path.join(appRoot, 'core/helpers/scheduledSend.ts'));

// 12:00 UTC on 1 October 2026 is 08:00 in New York (EDT) and 21:00 in Tokyo.
const NOW = new Date('2026-10-01T12:00:00Z');

test('the school\'s clock is read in the school\'s zone, whatever zone the browser is in', async () => {
    const { schoolNow } = await load();

    assert.equal(schoolNow('America/New_York', NOW), '2026-10-01T08:00');
    assert.equal(schoolNow('Asia/Tokyo', NOW), '2026-10-01T21:00');
    assert.equal(schoolNow('UTC', NOW), '2026-10-01T12:00');
    assert.equal(schoolNow('America/New_York', NOW, 90), '2026-10-01T09:30');
});

test('midnight is 00:00, not 24:00, and a day boundary in the school zone is respected', async () => {
    const { schoolNow } = await load();

    // 04:00Z is midnight in New York (EDT).
    assert.equal(schoolNow('America/New_York', new Date('2026-10-02T04:00:00Z')), '2026-10-02T00:00');
    assert.equal(schoolNow('America/New_York', new Date('2026-10-02T03:59:00Z')), '2026-10-01T23:59');
});

test('the school zone follows daylight saving, so the max the field offers moves an hour across a clock change', async () => {
    const { schoolMax } = await load();

    // Clocks go back on 1 Nov 2026. Thirty days after 08:00 EDT on 1 Oct is 07:00 EST.
    // Adding whole days of minutes lands one wall-clock hour EARLIER; the field's max is
    // a convenience and the server (which adds calendar days) is the authority.
    assert.equal(schoolMax('America/New_York', 30, NOW), '2026-10-31T08:00');
    assert.equal(schoolMax('America/New_York', 45, NOW), '2026-11-15T07:00');
});

test('an unusable zone name falls back to the browser rather than throwing', async () => {
    const { schoolNow } = await load();

    assert.match(schoolNow('Not/AZone', NOW), /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
    assert.match(schoolNow(null, NOW), /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
});

test('a time is refused unless it is a real, future time within the window', async () => {
    const { sendAtError } = await load();
    const tz = 'America/New_York';

    assert.equal(sendAtError('2026-10-05T10:00', tz, 30, NOW), null);
    assert.equal(sendAtError('2026-10-01T08:01', tz, 30, NOW), null);
    assert.match(sendAtError('2026-10-01T08:00', tz, 30, NOW) ?? '', /future/);
    assert.match(sendAtError('2026-09-30T10:00', tz, 30, NOW) ?? '', /future/);
    assert.match(sendAtError('2026-10-31T08:01', tz, 30, NOW) ?? '', /at most 30 days/);
    assert.match(sendAtError('', tz, 30, NOW) ?? '', /Choose/);
    assert.match(sendAtError(null, tz, 30, NOW) ?? '', /Choose/);
    assert.match(sendAtError('next tuesday', tz, 30, NOW) ?? '', /not a date/);
    // The window is the SCHOOL's: 10:00 tomorrow in Tokyo is yesterday in New York.
    assert.match(sendAtError('2026-10-01T07:00', tz, 30, NOW) ?? '', /future/);
    assert.equal(sendAtError('2026-10-01T22:00', 'Asia/Tokyo', 30, NOW), null);
});

test('an ordinary post sends no send_at at all, so it is what it was before scheduling', async () => {
    const { sendLaterFields } = await load();

    assert.deepEqual(sendLaterFields(false, '2026-10-05T10:00'), {});
    assert.deepEqual(sendLaterFields(true, ''), {});
    assert.deepEqual(sendLaterFields(true, null), {});
    assert.deepEqual(sendLaterFields(true, '2026-10-05T10:00'), { send_at: '2026-10-05T10:00' });
});

test('a time is described from its own parts, in words, with the zone named', async () => {
    const { describeSchoolTime } = await load();

    assert.equal(describeSchoolTime('2026-10-05T10:00', 'America/New_York'), 'Mon, Oct 5, 10:00 AM (America/New_York)');
    assert.equal(describeSchoolTime('2026-10-05T22:30'), 'Mon, Oct 5, 10:30 PM');
    assert.equal(describeSchoolTime('nonsense'), '');
    assert.equal(describeSchoolTime(null), '');
});

test('a story and a conversation become the same kind of row, with the same rules for buttons', async () => {
    const { storyRow, messageRow, failureText } = await load();

    const story = storyRow({
        id: 7, title: 'Trip', body: 'Bring a hat', published_at_local: '2026-10-05T10:00', status: 'scheduled',
        can_change_schedule: false, author: { name: 'Ustadh Bilal' },
    });
    assert.equal(story.kind, 'story');
    assert.equal(story.whenLocal, '2026-10-05T10:00');
    assert.equal(story.canChange, false, 'a co-teacher is not offered the buttons');
    assert.equal(story.status, 'scheduled');

    const failedStory = storyRow({ id: 8, body: 'x', status: 'failed', publish_failure: 'The author no longer teaches this class.' });
    assert.equal(failedStory.status, 'failed');
    assert.equal(failureText(failedStory), 'The author no longer teaches this class.');

    const message = messageRow({
        id: 3, subject: 'About Amina', body: 'Settling in', send_at_local: '2026-10-05T10:00', status: 'sending',
        can_change: true, about: { contact: { first_name: 'Amina', last_name: 'Yusuf' } }, author: { name: 'Ustadh Bilal' },
    });
    assert.equal(message.kind, 'message');
    assert.equal(message.about, 'Amina Yusuf');
    assert.equal(message.status, 'sending');
    assert.equal(message.canChange, true);

    // A message row is changeable only when the server says so: absent means no.
    assert.equal(messageRow({ id: 4, status: 'scheduled' }).canChange, false);
    // A failure with no reason still says something.
    assert.match(failureText({ status: 'failed', failure: null }), /Edit it/);
    assert.equal(failureText({ status: 'scheduled', failure: 'ignored' }), '');
});

test('every compose box offers Send later, and reads the school\'s zone from the server', () => {
    const office = source('views/dashboard/groups/GroupStoryTab.vue');
    const officeMessages = source('views/dashboard/groups/GroupThreadsTab.vue');
    const teacher = source('views/teacher/TeacherClass.vue');

    for (const [name, view] of [['office story', office], ['office messages', officeMessages], ['teacher', teacher]] as const) {
        assert.match(view, /<SendLaterField/, `${name} offers Send later`);
        assert.match(view, /<ScheduledItems/, `${name} shows the Scheduled list`);
    }

    // The field labels the zone the SERVER named, never the browser's.
    const field = source('components/common/SendLaterField.vue');
    assert.match(field, /timezone/);
    assert.doesNotMatch(field, /Intl\.DateTimeFormat\(\)\.resolvedOptions/, 'the browser zone must not label the field');
});

test('the family portal shows when a story went OUT, not when it was typed', () => {
    const view = source('views/family/FamilyClass.vue');

    assert.match(view, /post\.published_at \?\? post\.created_at/);
    assert.doesNotMatch(view, /when\(post\.created_at\)/);
});
