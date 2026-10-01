/**
 * Editing a class story AFTER it is sent: what the Edit control is drawn from, what it
 * sends, the "Edited" marker on the staff screens and the family card, and the family
 * translation key that makes an edited story translate again.
 *
 * `.vue` files cannot be loaded by `node --test`, so the wiring is pinned as source text,
 * the way scheduled-send.test.ts does it, and the logic lives in core/helpers/storyEdit.ts.
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
const load = () => import(path.join(appRoot, 'core/helpers/storyEdit.ts'));

const TEACHER = 'views/teacher/TeacherClass.vue';
const OFFICE = 'views/dashboard/groups/GroupStoryTab.vue';

test('the Edit control follows the server\'s can_edit and nothing else', async () => {
    const { canEditStory } = await load();

    assert.equal(canEditStory({ can_edit: true }), true);
    assert.equal(canEditStory({ can_edit: false }), false);
    // An older server, a scheduled row, a row the office reads as metadata: absent is no.
    assert.equal(canEditStory({}), false);
    assert.equal(canEditStory({ can_edit: 'true' as unknown as boolean }), false);
    assert.equal(canEditStory(null), false);
    assert.equal(canEditStory(undefined), false);
});

test('a save sends the title and the text, trimmed, and nothing else', async () => {
    const { storyEditFields } = await load();

    assert.deepEqual(storyEditFields({ heading: '  Our day ', body: ' We read.  ' }), { title: 'Our day', body: 'We read.' });
    // A cleared title is an empty string (the office's form-encoded PUT) and the teacher screen maps it to null.
    assert.deepEqual(storyEditFields({ heading: '   ', body: 'Text' }), { title: '', body: 'Text' });
    assert.deepEqual(Object.keys(storyEditFields({ heading: 'a', body: 'b' })).sort(), ['body', 'title']);
});

test('the form opens on what the story says and is submittable only when it says something different', async () => {
    const { draftOf, storyEditChanged, storyEditReady } = await load();
    const post = { title: 'Our day', body: 'We read.' };

    assert.deepEqual(draftOf(post), { heading: 'Our day', body: 'We read.' });
    assert.deepEqual(draftOf({ title: null, body: 'Only text' }), { heading: '', body: 'Only text' });

    assert.equal(storyEditChanged({ heading: 'Our day', body: 'We read.' }, post), false);
    assert.equal(storyEditChanged({ heading: ' Our day ', body: 'We read. ' }, post), false, 'spaces alone are not a change');
    assert.equal(storyEditReady({ heading: 'Our day', body: 'We read.' }, post), false);
    assert.equal(storyEditReady({ heading: 'Our day', body: 'We read twice.' }, post), true);
    assert.equal(storyEditReady({ heading: 'A new title', body: 'We read.' }, post), true);
    assert.equal(storyEditReady({ heading: 'Our day', body: '   ' }, post), false, 'a story needs words');
    assert.equal(storyEditReady({ heading: '', body: 'We read.' }, post), true, 'a title may be removed');
});

test('the Edited marker is drawn only for an edited story, with the time as its tooltip', async () => {
    const { editedMarker } = await load();
    const format = (iso: string) => `at ${iso}`;

    assert.equal(editedMarker(null, format), null);
    assert.equal(editedMarker(undefined, format), null);
    assert.equal(editedMarker('not a time', format), null);
    assert.deepEqual(editedMarker('2026-10-01T15:30:00+00:00', format), {
        text: 'Edited',
        title: 'Edited at 2026-10-01T15:30:00+00:00',
    });
});

test('the translation key carries edited_at, so an edited story is translated again', async () => {
    const { postTranslationKey } = await load();

    const before = postTranslationKey({ id: 12, edited_at: null }, 'body');
    const after = postTranslationKey({ id: 12, edited_at: '2026-10-01T15:30:00+00:00' }, 'body');
    const later = postTranslationKey({ id: 12, edited_at: '2026-10-02T09:00:00+00:00' }, 'body');

    assert.notEqual(before, after, 'the first edit is a new key');
    assert.notEqual(after, later, 'a second edit is a new key again');
    assert.notEqual(postTranslationKey({ id: 12, edited_at: null }, 'title'), before, 'title and body stay apart');
    assert.equal(postTranslationKey({ id: 12 }, 'body'), before, 'a missing edited_at reads as never edited');
    assert.ok(after.length <= 120, 'the server refuses a key over 120 characters');
    // A post and a message numbered 12 still cannot collide.
    assert.ok(before.startsWith('post:12:body'));
});

test('the family card builds its translation key from that helper, in the collector and in the reader alike', () => {
    const view = source('views/family/FamilyClass.vue');

    assert.match(view, /import \{ postTranslationKey \} from '@\/core\/helpers\/storyEdit'/);
    assert.match(view, /post: \(post: any, field: 'title' \| 'body'\) => postTranslationKey\(post, field\)/);
    // Both the collector and txPost go through KEY.post, so one key shape serves both.
    assert.match(view, /add\(KEY\.post\(post, 'body'\), post\.body\)/);
    assert.match(view, /const txPost = .*tx\(KEY\.post\(post, field\), post\[field\]\)/);
    assert.ok(!/`post:\$\{post\.id\}:\$\{field\}`/.test(view), 'no hand-built id-only key is left');
});

test('the family card shows an Edited marker from edited_at, in the reader\'s language', () => {
    const view = source('views/family/FamilyClass.vue');

    assert.match(view, /v-if="post\.edited_at"/);
    assert.match(view, /t\('story_edited'\)/);
    assert.match(view, /whenAt\(post\.edited_at\)/, 'the time is the tooltip');
});

test('both staff screens draw Edit from can_edit, edit in place, and send title and text only', () => {
    for (const rel of [TEACHER, OFFICE]) {
        const view = source(rel);

        assert.match(view, /import StoryEditForm from '@\/components\/common\/StoryEditForm\.vue'/, `${rel} uses the shared form`);
        assert.match(view, /v-if="canEditStory\(post\) && editingStoryId !== post\.id"/, `${rel} draws Edit from can_edit`);
        assert.match(view, /aria-label="Edit this story"/, `${rel} labels the button`);
        assert.match(view, /<StoryEditForm v-if="editingStoryId === post\.id"/, `${rel} swaps the text for the form`);
        assert.match(view, /editedMarker\(post\.edited_at, /, `${rel} draws the Edited marker`);
        assert.match(view, /useStoryEdit/);
    }

    // The request: title and body, and no time, no media, no send_now.
    assert.match(source(TEACHER), /put\(`\$\{base\.value\}\/posts\/\$\{post\.id\}`, \{ title: fields\.title \|\| null, body: fields\.body \}\)/);
    assert.match(source(OFFICE), /feedStore\.updatePost\(props\.groupId, post\.id, \{ title: fields\.title, body: fields\.body \}\)/);
});

test('the shared form has no send time and no file control, and shows the server\'s error beside itself', () => {
    const form = source('components/common/StoryEditForm.vue');

    assert.ok(!/SendLaterField/.test(form), 'a story that is out has no send time');
    assert.ok(!/type="file"|GroupMediaPicker/.test(form), 'files are not edited in this slice');
    assert.match(form, /v-if="error" class="small text-danger[^"]*" role="alert"/);
    assert.match(form, /aria-label="Save changes to this story"/);
    assert.match(form, /aria-label="Stop editing this story"/);
    assert.match(form, /Nobody is notified/);
});

test('a failed save keeps the form open with what was typed, and a good one replaces the row and closes', () => {
    const edit = source('composables/useStoryEdit.ts');

    assert.match(edit, /saved\(await save\(post, storyEditFields\(draft\)\)\);\s*editingId\.value = null;/, 'closes only after the save and the row swap');
    assert.match(edit, /catch \(e\) \{\s*error\.value = apiErrorText\(e, /, 'the failure is shown, not thrown');
    // Not closed on failure: the only place editingId is cleared besides cancel() is after success.
    assert.equal((edit.match(/editingId\.value = null/g) ?? []).length, 2);
});

test('the story types carry the two new fields', () => {
    const type = source('core/types/data/masjid-related/GroupPost.ts');

    assert.match(type, /edited_at\?: string \| null;/);
    assert.match(type, /can_edit\?: boolean;/);
});

test('the Edited word is in every family language table', () => {
    for (const rel of ['views/family/familyI18n.ts', 'views/family/locales/ur.ts', 'views/family/locales/ps.ts',
        'views/family/locales/fa-AF.ts', 'views/family/locales/es.ts']) {
        const count = (source(rel).match(/^\s+story_edited: "[^"]+",$/gm) ?? []).length;

        assert.equal(count, rel.endsWith('familyI18n.ts') ? 2 : 1, `${rel} carries story_edited`);
    }
});
