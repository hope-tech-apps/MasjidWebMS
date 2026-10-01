/**
 * The unread-messages badge: how the number reads, how a screen keeps it true
 * without a reload, and how a long conversation is opened so that opening it
 * really does clear it.
 *
 * `.vue` files cannot be loaded by `node --test`, so the wiring is pinned as source
 * text, the way scheduled-send.test.ts does it.
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
const load = () => import(path.join(appRoot, 'core/helpers/threadUnread.ts'));

test('a count is capped at 99+ and a missing or silly one is nothing', async () => {
    const { unreadPill, unreadNumber, newChip } = await load();

    assert.equal(unreadPill(1), '1');
    assert.equal(unreadPill(99), '99');
    assert.equal(unreadPill(100), '99+');
    assert.equal(unreadPill(5000), '99+');
    assert.equal(newChip(3), '3 new');
    assert.equal(newChip(250), '99+ new');

    for (const nothing of [0, -4, null, undefined, '', 'many', NaN]) {
        assert.equal(unreadPill(nothing), '', `${String(nothing)} shows no pill`);
        assert.equal(newChip(nothing), '');
        assert.equal(unreadNumber(nothing), 0);
    }
    assert.equal(unreadNumber('7'), 7, 'a count that arrived as text still counts');
    assert.equal(unreadNumber(2.9), 2);
});

test('what a screen reader hears says what the number is', async () => {
    const { unreadSpoken } = await load();

    assert.equal(unreadSpoken(1), '1 unread message');
    assert.equal(unreadSpoken(12), '12 unread messages');
    assert.equal(unreadSpoken(400), '99+ unread messages');
    assert.equal(unreadSpoken(0), '');
});

test('a conversation row says how many are new, and keeps the plain word for a server that predates the count', async () => {
    const { threadNewLabel } = await load();

    assert.equal(threadNewLabel({ unread_count: 4, unread: true }), '4 new');
    assert.equal(threadNewLabel({ unread_count: 0, unread: false }), '');
    assert.equal(threadNewLabel({ unread: true }), 'New');
    assert.equal(threadNewLabel({ unread: false }), '');
    assert.equal(threadNewLabel(null), '');
});

test('opening a conversation takes its count off the class, never below zero', async () => {
    const { afterOpening } = await load();

    assert.equal(afterOpening(7, 3), 4);
    assert.equal(afterOpening(3, 3), 0);
    assert.equal(afterOpening(2, 5), 0, 'a stale class number cannot go negative');
    assert.equal(afterOpening(undefined, 2), 0);
    assert.equal(afterOpening(5, undefined), 5);
});

test('regaining focus refreshes at most once in a few seconds', async () => {
    const { focusRefreshDue, FOCUS_REFRESH_GAP_MS } = await load();

    assert.equal(focusRefreshDue(null, 1000), true);
    assert.equal(focusRefreshDue(1000, 1000 + FOCUS_REFRESH_GAP_MS - 1), false);
    assert.equal(focusRefreshDue(1000, 1000 + FOCUS_REFRESH_GAP_MS), true);
});

// A fake server: `total` messages, `perPage` to a page, as the paginator lays them out.
const server = (total: number, perPage: number) => {
    const requested: number[] = [];
    const fetchPage = async (page: number) => {
        requested.push(page);
        const lastPage = Math.max(1, Math.ceil(total / perPage));
        const from = (page - 1) * perPage;
        const data = Array.from({ length: Math.max(0, Math.min(perPage, total - from)) }, (_, i) => ({ id: from + i + 1 }));

        return { thread: { id: 9, seenThrough: page }, messages: { data, current_page: page, last_page: lastPage } };
    };

    return { requested, fetchPage };
};

test('a sixty-message conversation is read to its end in the page size the screen asks for', async () => {
    const { openWholeThread, MESSAGE_PAGE_SIZE } = await load();
    const s = server(60, MESSAGE_PAGE_SIZE);

    const opened = await openWholeThread(s.fetchPage);

    assert.equal(opened.messages.length, 60);
    assert.deepEqual(s.requested, [1], 'one request is enough under the ceiling');
    assert.equal(opened.truncated, false);
});

test('a conversation longer than a page is fetched page by page, in order, and the thread is the last one reported', async () => {
    const { openWholeThread } = await load();
    const s = server(60, 25);

    const opened = await openWholeThread(s.fetchPage);

    assert.deepEqual(s.requested, [1, 2, 3]);
    assert.deepEqual(opened.messages.map((m: { id: number }) => m.id), Array.from({ length: 60 }, (_, i) => i + 1));
    assert.deepEqual(opened.thread, { id: 9, seenThrough: 3 }, 'the count comes from the page that moved the bookmark last');
    assert.equal(opened.pages, 3);
});

test('a runaway conversation stops at the page limit and says it was cut', async () => {
    const { openWholeThread } = await load();
    const s = server(1000, 10);

    const opened = await openWholeThread(s.fetchPage, 4);

    assert.equal(s.requested.length, 4);
    assert.equal(opened.messages.length, 40);
    assert.equal(opened.truncated, true);
});

test('an empty or malformed page ends the loop instead of looping', async () => {
    const { openWholeThread } = await load();

    const none = await openWholeThread(async () => null);
    assert.deepEqual(none.messages, []);
    assert.equal(none.pages, 1);

    const odd = await openWholeThread(async () => ({ thread: { id: 1 }, messages: { data: [{ id: 1 }], last_page: 'x' } }) as any);
    assert.equal(odd.messages.length, 1);
    assert.equal(odd.pages, 1);
});

test('the page size the screens ask for is the ceiling the server allows', () => {
    const helper = source('core/helpers/threadUnread.ts');
    const controller = readFileSync(path.resolve(appRoot, '../../app/Http/Controllers/AdminDashboard/GroupThreadsController.php'), 'utf8');

    const asked = /MESSAGE_PAGE_SIZE = (\d+)/.exec(helper)?.[1];
    const allowed = /MAX_MESSAGES_PER_PAGE = (\d+)/.exec(controller)?.[1];

    assert.ok(asked && allowed);
    assert.equal(asked, allowed, 'asking for more than the server serves would silently page differently than the helper assumes');
});

test('the teacher class screen shows the count on the tab, beside the class name, and on each conversation, and keeps it true', () => {
    const view = source('views/teacher/TeacherClass.vue');

    // On the Messages tab button, with words for a screen reader.
    assert.match(view, /t\.key === 'messages' && unreadMessages > 0/);
    assert.match(view, /unreadPill\(unreadMessages\)/);
    assert.match(view, /<span class="visually-hidden">\{\{ unreadSpoken\(unreadMessages\) \}\}<\/span>/);

    // Beside the class name, because Messages scrolls off a phone: a button that goes to the tab.
    assert.match(view, /<button v-if="unreadMessages > 0" type="button"[^>]*tc-new-chip/);
    assert.match(view, /@click="activeTab = 'messages'"/);
    assert.match(view, /:aria-label="`\$\{unreadSpoken\(unreadMessages\)\}\. Open Messages\.`"/);

    // On each row, replacing the plain "New" pill.
    assert.match(view, /threadNewLabel\(thread\)/);
    assert.doesNotMatch(view, /v-if="thread\.unread" class="badge bg-success ms-1">New</);

    // Kept true: the list's total wins; opening re-reads the list (subtracting the row's
    // old count only if that fails); focus refreshes the list once it exists.
    assert.match(view, /group\.value\.unread_messages = unreadNumber\(res\.data\.meta\.unread_total\)/);
    assert.match(view, /afterOpening\(group\.value\.unread_messages, stale\)/);
    assert.match(view, /window\.addEventListener\('focus', refreshUnread\)/);
    assert.match(view, /document\.addEventListener\('visibilitychange', refreshUnread\)/);
    assert.match(view, /window\.removeEventListener\('focus', refreshUnread\)/);

    // A conversation is read to its end, in the biggest page the server serves.
    assert.match(view, /openWholeThread<any, any>/);
    assert.match(view, /per_page=\$\{MESSAGE_PAGE_SIZE\}&page=\$\{page\}/);
});

test('the refresh on focus never reloads the whole class', () => {
    const view = source('views/teacher/TeacherClass.vue');
    const body = /const refreshUnread = async \(\) => \{[\s\S]*?\n\};/.exec(view)?.[0] ?? '';

    assert.ok(body.length > 0, 'refreshUnread exists');
    assert.doesNotMatch(body, /loadGroup\(/, 'loadGroup blanks the screen and drops a half-written reply');
    assert.doesNotMatch(body, /loading\.value/);
    assert.match(body, /group\.value\.unread_messages = /);
});

test('My Classes shows each class\'s count', () => {
    const view = source('views/teacher/TeacherClasses.vue');

    assert.match(view, /unreadNumber\(group\.unread_messages\) > 0/);
    assert.match(view, /newChip\(group\.unread_messages\)/);
    assert.match(view, /unreadSpoken\(group\.unread_messages\)/);
    assert.match(view, /unread_messages\?: number;/);
});

test('the office screen shows the count on the Messages tab and on each row, and tells the tab bar when it changes', () => {
    const detail = source('views/dashboard/GroupDetailView.vue');
    const tab = source('views/dashboard/groups/GroupThreadsTab.vue');
    const type = source('core/types/data/masjid-related/GroupThread.ts');
    const group = source('core/types/data/masjid-related/Group.ts');
    const store = source('stores/masjid/groupThreadsStore.ts');

    assert.match(detail, /tab\.key === 'threads' && unreadNumber\(group\.unread_messages\) > 0/);
    assert.match(detail, /unreadSpoken\(group\.unread_messages\)/);
    assert.match(detail, /@unread-total="setUnread"/);
    assert.match(detail, /@opened="onThreadOpened"/);

    assert.match(tab, /threadNewLabel\(thread\)/);
    assert.match(tab, /emit\('unread-total'/);
    assert.match(tab, /emit\('opened', cleared\)/);

    assert.match(type, /unread_count: number;/);
    assert.match(type, /unread_total\?: number;/);
    assert.match(group, /unread_messages\?: number;/);

    // The office reads a long conversation to its end as well.
    assert.match(store, /openWholeThread<GroupMessage, GroupThread>/);
    assert.match(store, /per_page=\$\{MESSAGE_PAGE_SIZE\}&page=\$\{page\}/);
});

test('after a reply the teacher screen re-reads the open conversation before the list', () => {
    const teacher = readFileSync(path.join(appRoot, 'views/teacher/TeacherClass.vue'), 'utf8');

    // A parent message that arrived while the conversation was open is only shown,
    // and only cleared, by a fresh read: the server leaves the bookmark behind it.
    assert.match(teacher, /replyPhotos\.value = \[\];\s*await rereadOpenThread\(\);\s*await loadThreads\(\);/);
    assert.match(teacher, /const rereadOpenThread = async \(\) => \{[\s\S]*?if \(openedThread\.value\?\.id !== thread\.id\) return;/);
    // One reader for opening and re-reading, so both fetch every page.
    assert.equal((teacher.match(/readWholeThread\(/g) ?? []).length, 2);
});

test('coming back to the window refreshes the LIST once it exists, quietly, and not only the number', () => {
    const teacher = source('views/teacher/TeacherClass.vue');

    // The tab said "1 new" while no row said which conversation: the focus refresh
    // wrote only the class number.
    assert.match(teacher, /if \(threadsLoaded\.value\) \{\s*await loadThreads\(true\);\s*return;\s*\}/);
    assert.match(teacher, /const loadThreads = async \(quiet = false\): Promise<boolean> => \{/);
    // Quiet means: no spinner, the scheduled list left alone, and a failure keeps the rows.
    assert.match(teacher, /if \(!quiet\) threadsLoading\.value = true;/);
    assert.match(teacher, /if \(!quiet\) await loadScheduledMessages\(\);/);
    assert.match(teacher, /catch \{\s*if \(!quiet\) threads\.value = \[\];\s*return false;/);
    assert.match(teacher, /threadsLoaded\.value = true;/);
});

test('opening a conversation takes the number from a fresh list, not from the row it was opened from', () => {
    const teacher = source('views/teacher/TeacherClass.vue');
    // The row may have been loaded before two more messages arrived; the server cleared
    // all of them, so subtracting the row's old count leaves the tab too high.
    assert.match(teacher, /if \(!\(await loadThreads\(true\)\) && group\.value\) \{\s*group\.value\.unread_messages = afterOpening\(group\.value\.unread_messages, stale\);/);

    const office = source('views/dashboard/groups/GroupThreadsTab.vue');
    assert.match(office, /const refreshQuietly = async \(\): Promise<boolean> => \{/);
    assert.match(office, /if \(!\(await refreshQuietly\(\)\) && cleared > 0\) emit\('opened', cleared\);/);
    assert.match(office, /emit\('unread-total', unreadNumber\(threadsStore\.threadsMeta\.unread_total\)\);[\s\S]*?return true;/);
});
