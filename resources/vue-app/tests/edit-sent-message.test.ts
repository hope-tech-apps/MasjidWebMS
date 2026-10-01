/**
 * Editing the words of a message already sent (W7, 2026-10-01): the helpers the
 * screens share, and the wiring of the three screens that show a message.
 *
 * `.vue` files cannot be loaded by `node --test`, so the wiring is pinned as source
 * text, the way scheduled-send.test.ts and story-seen.test.ts do it, and the logic
 * lives in core/helpers/messageEdit.ts.
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
const load = () => import(path.join(appRoot, 'core/helpers/messageEdit.ts'));

// ---------------------------------------------------------------- the helpers

test('save is on only for a valid draft that really changes the words', async () => {
    const { canSaveEdit } = await load();
    const text = { body: 'Salaam, a quick update.', attachments: [] };

    assert.equal(canSaveEdit('Salaam, a longer update.', text), true);
    assert.equal(canSaveEdit('Salaam, a quick update.', text), false, 'unchanged');
    assert.equal(canSaveEdit('  Salaam, a quick update. \n', text), false, 'whitespace only is not a change');
    assert.equal(canSaveEdit('', text), false, 'a text message cannot be emptied');
    assert.equal(canSaveEdit('   ', text), false);
});

test('a line ending is not a change of words', async () => {
    const { canSaveEdit, isUnchanged } = await load();
    // A message sent from a multipart form was stored with CRLF; the editor's textarea hands back LF.
    const stored = { body: 'Line one\r\nLine two', attachments: [] };

    assert.equal(isUnchanged('Line one\nLine two', stored.body), true);
    assert.equal(canSaveEdit('Line one\nLine two', stored), false, 'Save is not offered');
    assert.equal(canSaveEdit('Line one\r\nLine two', stored), false);
    assert.equal(canSaveEdit('Line one\nLine three', stored), true, 'a real change still is one');
});

test('the earlier-versions disclosure is named by its own words, with its state', () => {
    const vue = source('components/common/MessageEditHistory.vue');

    // A fixed aria-label said "Show earlier versions" even while the list was open, and hid
    // the button's own text ("Hide earlier versions") from a screen reader.
    assert.doesNotMatch(vue, /aria-label=/);
    assert.match(vue, /:aria-expanded="open \? 'true' : 'false'"/);
    assert.match(vue, /\{\{ open \? 'Hide earlier versions' : 'Earlier versions' \}\}/);
});

test('a message with a photo or video may lose its words; one whose media is withheld counts too', async () => {
    const { canSaveEdit, editProblem, messageHasMedia } = await load();
    const photo = { body: 'A caption', attachments: [{ id: 1 }] };
    const withheld = { body: 'A caption', attachments: [], media_withheld: true };

    assert.equal(messageHasMedia(photo), true);
    assert.equal(messageHasMedia(withheld), true);
    assert.equal(messageHasMedia({ body: 'x', attachments: [] }), false);
    assert.equal(messageHasMedia({ body: 'x' }), false);

    assert.equal(canSaveEdit('', photo), true);
    assert.equal(canSaveEdit('', withheld), true);
    assert.equal(editProblem('', { body: 'x', attachments: [] }), 'Write a message.');
});

test('the ceiling is the server\'s, when the screen knows it', async () => {
    const { editProblem, canSaveEdit } = await load();
    const msg = { body: 'short', attachments: [] };

    assert.equal(editProblem('a'.repeat(20), msg, 20), '');
    assert.match(editProblem('a'.repeat(21), msg, 20), /at most 20 characters/);
    assert.equal(canSaveEdit('a'.repeat(21), msg, 20), false);
    // Unknown ceiling (0): the server decides.
    assert.equal(editProblem('a'.repeat(9000), msg, 0), '');
});

test('the list takes the server\'s answer in the same place, and a new array', async () => {
    const { replaceMessage, applyEdited } = await load();
    const list = [{ id: 1, body: 'a' }, { id: 2, body: 'b' }, { id: 3, body: 'c' }];
    const next = replaceMessage(list, { id: 2, body: 'B', edited_at: '2026-10-01T10:00:00+00:00' });

    assert.deepEqual(next.map((m: any) => m.id), [1, 2, 3]);
    assert.equal(next[1].body, 'B');
    assert.equal(next[1].edited_at, '2026-10-01T10:00:00+00:00');
    assert.notEqual(next, list);
    assert.equal(list[1].body, 'b', 'the old array is untouched');
    // An id that is not in the list changes nothing.
    assert.deepEqual(replaceMessage(list, { id: 9, body: 'z' }), list);

    const held = { id: 5, body: 'old', reactions: [1] };
    assert.equal(applyEdited(held, { id: 5, body: 'new', edited_at: 'x' } as any), held);
    assert.equal(held.body, 'new');
});

test('the office reads the earlier texts as Original, Version 2, Version 3', async () => {
    const { versionLabel } = await load();

    assert.deepEqual([0, 1, 2].map(versionLabel), ['Original', 'Version 2', 'Version 3']);
});

// ---------------------------------------------------------------- the wiring

test('the editor shows the server\'s sentence in place, keeps the draft, and has accessible buttons', () => {
    const vue = source('components/common/EditableMessageBody.vue');

    assert.match(vue, /serverMessage\(e, /);
    assert.match(vue, /role="alert"/);
    assert.match(vue, /aria-label="Edit this message"/);
    assert.match(vue, /aria-label="Save the edited message"/);
    assert.match(vue, /aria-label="Cancel editing"/);
    assert.match(vue, /aria-label="Edit message text"/);
    // Success closes the editor; a failure must not.
    assert.match(vue, /await props\.save\(draft\.value\.trim\(\)\);\s*editing\.value = false;/);
});

test('the office screen edits through the admin store, shows Edited, and has the earlier-versions disclosure', () => {
    const vue = source('views/dashboard/groups/GroupThreadsTab.vue');
    const store = source('stores/masjid/groupThreadsStore.ts');

    assert.match(vue, /<EditableMessageBody[^>]*:can-edit="!!message\.can_edit"/);
    assert.match(vue, /message\.edited_at/);
    assert.match(vue, />Edited</);
    assert.match(vue, /<MessageEditHistory v-if="message\.edited_at"/);
    assert.match(vue, /threadsStore\.editMessage\(props\.groupId, thread\.id, message\.id, body\)/);
    // An edit does not move the conversation, so no reload of the list after one.
    const handler = vue.slice(vue.indexOf('const editMessage'), vue.indexOf('const reactTo'));
    assert.doesNotMatch(handler, /loadThreads|fetchThread/);

    assert.match(store, /ApiService\.put\(\s*`\/api\/admin\/masjids\/\$\{masjidStore\.masjid\.id\}\/groups\/\$\{groupId\}\/threads\/\$\{threadId\}\/messages\/\$\{messageId\}`/);
    assert.match(store, /\/messages\/\$\{messageId\}\/edits`/);
});

test('the teacher screen edits through the teacher realm, replaces the row, and never loads the history', () => {
    const vue = source('views/teacher/TeacherClass.vue');

    assert.match(vue, /<EditableMessageBody :body="m\.body" :can-edit="!!m\.can_edit"/);
    assert.match(vue, /<template v-if="m\.edited_at"> · <span[^>]*>Edited<\/span>/);
    assert.match(vue, /TeacherApiService\.put\(`\$\{base\.value\}\/threads\/\$\{openedThread\.value\.id\}\/messages\/\$\{m\.id\}`, \{ body \}\)/);
    assert.match(vue, /openedMessages\.value = replaceMessage\(openedMessages\.value, res\.data\.data\)/);

    const handler = vue.slice(vue.indexOf('const editMessage = async'), vue.indexOf('const reactTo = async'));
    assert.doesNotMatch(handler, /loadThreads/);
    assert.doesNotMatch(vue, /MessageEditHistory/);
    assert.doesNotMatch(vue, /\/edits`/);
});

test('the family screen says Edited, offers no edit control, and keys its translation on edited_at', () => {
    const vue = source('views/family/FamilyClass.vue');

    assert.match(vue, /<template v-if="m\.edited_at"> · <span[^>]*>\{\{ t\('msg_edited'\) \}\}<\/span>/);
    assert.doesNotMatch(vue, /EditableMessageBody|MessageEditHistory/);

    // The key itself, evaluated: an edited message must not reuse the OLD text's translation.
    const literal = vue.match(/messageBody: \(message: any\) => (`[^`]+`),/);
    assert.ok(literal, 'the message translation key was not found');
    const key = new Function('message', `return ${literal![1]};`) as (m: any) => string;

    assert.equal(key({ id: 7 }), 'message:7:0:body');
    assert.notEqual(key({ id: 7 }), key({ id: 7, edited_at: '2026-10-01T10:00:00+00:00' }));
    assert.notEqual(
        key({ id: 7, edited_at: '2026-10-01T10:00:00+00:00' }),
        key({ id: 7, edited_at: '2026-10-01T11:00:00+00:00' }),
    );
    assert.equal(key({ id: 7, edited_at: null }), key({ id: 7 }));
});

test('the family portal word is in all six tables', () => {
    const dir = 'views/family/';
    const en = source(`${dir}familyI18n.ts`);

    assert.equal((en.match(/^ {8}msg_edited:/gm) ?? []).length, 2, 'English and Arabic');
    for (const lang of ['ur', 'ps', 'fa-AF', 'es']) {
        assert.equal((source(`${dir}locales/${lang}.ts`).match(/^ {4}msg_edited:/gm) ?? []).length, 1, lang);
    }
});

test('the type carries the two new fields', () => {
    const ts = source('core/types/data/masjid-related/GroupThread.ts');

    assert.match(ts, /can_edit\?: boolean;/);
    assert.match(ts, /edited_at\?: string \| null;/);
});
