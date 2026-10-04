/**
 * The admin Teachers form (core/helpers/teacherForm.ts): a refusal is always shown
 * somewhere, the class picker lists every class of the school, not a page of them,
 * and it says "none exist" only when the server said so.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { TEACHER_FORM_FIELDS, classOptionsFrom, hasClassList, sortTeacherFormErrors, teacherPickerState } from '../core/helpers/teacherForm.ts';

const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8');

// ------------------------------------------------------------- the banner

test('a refusal under a key the form does not render goes to the banner, not nowhere', () => {
    // The shape the server gave when the office pressed Save six times and saw nothing.
    const sorted = sortTeacherFormErrors({ 'class_subjects.5': ['The class_subjects.5 field must be an array.'] });

    assert.deepEqual(sorted.fields, {});
    assert.equal(sorted.banner, 'The class_subjects.5 field must be an array.');
});

test('a rendered field keeps its inline message, and `class_ids.0` belongs to the one control', () => {
    const sorted = sortTeacherFormErrors({
        name: ['The name field is required.', 'A second message.'],
        'class_ids.0': ['The class_ids.0 field must be an integer.'],
        class_ids: ['One or more of those classes are not in this school.'],
        email: 'layla@school.test is already a teacher at this school. Use Edit to change their classes.',
    });

    assert.deepEqual(sorted.fields, {
        name: 'The name field is required.',
        class_ids: 'The class_ids.0 field must be an integer.',
        email: 'layla@school.test is already a teacher at this school. Use Edit to change their classes.',
    });
    assert.equal(sorted.banner, '');
});

test('a mixed bag shows both: inline where there is a field, the banner for the rest, each message once', () => {
    const sorted = sortTeacherFormErrors({
        phone: ['The phone field format is invalid.'],
        'class_subjects.5.0': ['The selected subject is invalid.'],
        'class_subjects.7.0': ['The selected subject is invalid.'],
        user_id: ['That account owns this organisation, so its access cannot be removed here.'],
    });

    assert.deepEqual(sorted.fields, { phone: 'The phone field format is invalid.' });
    assert.equal(
        sorted.banner,
        'The selected subject is invalid. That account owns this organisation, so its access cannot be removed here.'
    );
});

test('an unrendered key with two messages shows both in the banner, not only the first', () => {
    // What a rule without `bail` gives for one bad value: it fails `string` and `in`.
    const sorted = sortTeacherFormErrors({
        'class_subjects.3.0': ['The class_subjects.3.0 field must be a string.', 'The selected class_subjects.3.0 is invalid.'],
    });

    assert.deepEqual(sorted.fields, {});
    assert.equal(sorted.banner, 'The class_subjects.3.0 field must be a string. The selected class_subjects.3.0 is invalid.');
});

test('the banner says each sentence once, across keys and within one, and skips the empty ones', () => {
    const sorted = sortTeacherFormErrors({
        'class_subjects.5.0': ['Must be a string.', '', 'The selected subject is invalid.', null],
        'class_subjects.7.0': ['Must be a string.', 'The selected subject is invalid.', '  '],
        user_id: 'A single message, not a list.',
        // A rendered field still keeps one line under its control: its first that is not empty.
        phone: ['', 'The phone field format is invalid.', 'A second message.'],
    });

    assert.deepEqual(sorted.fields, { phone: 'The phone field format is invalid.' });
    assert.equal(sorted.banner, 'Must be a string. The selected subject is invalid. A single message, not a list.');
});

test('every key the form has no place for is a banner key, whatever it is called', () => {
    for (const key of ['class_subjects', 'class_subjects.5', 'user_id', 'type', '']) {
        const sorted = sortTeacherFormErrors({ [key]: ['Refused.'] });

        assert.deepEqual(sorted.fields, {}, key);
        assert.equal(sorted.banner, 'Refused.', key);
    }
});

test('something that is not a validation bag sorts to nothing, so the caller falls back to its own message', () => {
    for (const bag of [undefined, null, 'A single message.', ['a list'], 422, {}, { name: [] }, { name: [''] }, { name: null }]) {
        assert.deepEqual(sortTeacherFormErrors(bag), { fields: {}, banner: '' }, JSON.stringify(bag));
    }
});

test('the fields with an inline message are exactly the ones the template renders', () => {
    const view = read('../views/dashboard/TeachersView.vue');
    const template = view.slice(0, view.indexOf('<script setup'));
    const rendered = [...new Set([...template.matchAll(/fieldErrors\.([a-z_]+)/g)].map((m) => m[1]))].sort();

    assert.deepEqual(rendered, [...TEACHER_FORM_FIELDS].sort(), 'a field added to the form is added to TEACHER_FORM_FIELDS too');
});

test('the form sorts a 422 with the helper, puts the rest in the banner, and counts the banner as shown', () => {
    const view = read('../views/dashboard/TeachersView.vue');

    assert.match(view, /import \{ sortTeacherFormErrors, teacherPickerState \} from '@\/core\/helpers\/teacherForm';/);
    assert.match(view, /const sorted = sortTeacherFormErrors\(error\?\.response\?\.data\?\.data\);/);
    assert.match(view, /fieldErrors\.value = sorted\.fields;/);
    assert.match(view, /formError\.value = sorted\.banner;/);
    assert.match(view, /return Object\.keys\(sorted\.fields\)\.length > 0 \|\| sorted\.banner !== '';/);
    assert.match(view, /<div v-if="formError" class="alert alert-danger py-2" role="alert">/, 'the banner is on the form');
    assert.doesNotMatch(view, /mapped\[key\]/, 'the old mapping, which hid an unrendered key, is gone');
});

// ------------------------------------------------------------- the picker

test('the picker lists every class the server sent, in the order it sent them', () => {
    // More than the 15 a page of the Classes list holds, and not in id order.
    const classes = Array.from({ length: 18 }, (_, i) => ({ id: 40 - i, name: `Grade ${i + 1}` }));
    const options = classOptionsFrom({ subjects: [], classes });

    assert.equal(options.length, 18);
    assert.deepEqual(options.map((o) => o.id), classes.map((c) => c.id), 'display order is the server\'s, never re-sorted');
    assert.deepEqual(options[17], { id: 23, name: 'Grade 18' });
});

test('an option is an id and a name and nothing else, with a numeric id', () => {
    const options = classOptionsFrom({ classes: [{ id: '7', name: 'Halaqa A', slug: 'a', participants_count: 12 }] });

    assert.deepEqual(options, [{ id: 7, name: 'Halaqa A' }]);
});

test('a reply without classes, or with rows that have no usable id, gives no broken checkboxes', () => {
    for (const meta of [undefined, null, {}, { classes: null }, { classes: 'x' }, { classes: {} }]) {
        assert.deepEqual(classOptionsFrom(meta), [], JSON.stringify(meta));
    }

    assert.deepEqual(
        classOptionsFrom({ classes: [null, {}, { id: 'x', name: 'Bad' }, { id: 0, name: 'Zero' }, { id: 3, name: 'Kept' }, { id: 3, name: 'Twice' }] }),
        [{ id: 3, name: 'Kept' }]
    );
});

test('the store fills the picker from the teachers reply and never touches the Classes screen\'s paginated list', () => {
    const store = read('../stores/masjid/teachersStore.ts');
    const code = store.replace(/\/\*[\s\S]*?\*\/|^\s*\/\/.*$/gm, '');

    assert.match(code, /const classOptions = ref<TeacherClass\[\]>\(\[\]\);/);
    assert.equal((code.match(/classOptions\.value = /g) ?? []).length, 1, 'one place writes the picker\'s classes');
    assert.match(code, /if \(classOptionsKnown\.value\) classOptions\.value = classOptionsFrom\(meta\);/);
    assert.equal((code.match(/takeClassOptions\(res\.data\?\.meta\)/g) ?? []).length, 2, 'on the list read and on the refresh');
    assert.match(code, /classOptions,\s*classOptionsKnown,\s*fetchTeachers,\s*fetchClassOptions,/);
    assert.doesNotMatch(code, /groupsStore|useGroupsStore|groupsPaginated|\/groups/);
});

test('the view takes the picker from the teachers store, not from a page of the groups list', () => {
    const view = read('../views/dashboard/TeachersView.vue');
    const script = view.slice(view.indexOf('<script setup')).replace(/\/\*[\s\S]*?\*\/|^\s*\/\/.*$/gm, '');

    assert.match(script, /const classOptions = computed<TeacherClass\[\]>\(\(\) => teachersStore\.classOptions\);/);
    assert.match(script, /await teachersStore\.fetchClassOptions\(\);/);
    assert.doesNotMatch(script, /groupsStore|useGroupsStore|groupsPaginated|fetchGroups/);
    assert.match(view, /v-for="option in classOptions"/, 'the picker renders one checkbox per option');
});

// ------------------------------------------------- the picker with nothing to list

test('"none exist yet" needs an answered read: a failed or unanswered one says it could not load', () => {
    // The request failed, or the reply had no meta.classes: nothing in hand, nothing known.
    assert.equal(teacherPickerState({ loading: false, known: false, count: 0 }), 'failed');
    // The server answered, and the school has no classes.
    assert.equal(teacherPickerState({ loading: false, known: true, count: 0 }), 'empty');
    assert.equal(teacherPickerState({ loading: false, known: true, count: 3 }), 'list');
    // Classes read earlier are still offered when a later refresh fails.
    assert.equal(teacherPickerState({ loading: false, known: false, count: 3 }), 'list');

    for (const known of [true, false]) {
        for (const count of [0, 3]) {
            assert.equal(teacherPickerState({ loading: true, known, count }), 'loading', `${known} ${count}`);
        }
    }
});

test('an empty list of classes is an answer; a reply without the key is not', () => {
    assert.equal(hasClassList({ subjects: [], classes: [] }), true);
    assert.equal(hasClassList({ classes: [{ id: 3, name: 'Grade 1' }] }), true);

    for (const meta of [undefined, null, {}, { subjects: [] }, { classes: null }, { classes: 'x' }, { classes: {} }]) {
        assert.equal(hasClassList(meta), false, JSON.stringify(meta));
    }
});

test('the store marks the classes as not read on a failed list read, a failed refresh and a reply without them', () => {
    const store = read('../stores/masjid/teachersStore.ts');
    const code = store.replace(/\/\*[\s\S]*?\*\/|^\s*\/\/.*$/gm, '');

    assert.match(code, /const classOptionsKnown = ref\(false\);/, 'not known before the first read');
    assert.match(code, /classOptionsKnown\.value = hasClassList\(meta\);/, 'known only when the reply carried the list');

    const listRead = code.slice(code.indexOf('async function fetchTeachers'), code.indexOf('async function fetchClassOptions'));
    assert.match(listRead, /\.catch\(\(e: Error\) => \{\s*classOptionsKnown\.value = false;[\s\S]*?throw e;/, 'a failed list read');
    assert.match(listRead, /\} else \{\s*classOptionsKnown\.value = false;\s*\}/, 'a reply that is not a list');

    const refresh = code.slice(code.indexOf('async function fetchClassOptions'), code.indexOf('async function createTeacher'));
    assert.match(refresh, /\} catch \(e\) \{\s*classOptionsKnown\.value = false;\s*throw e;\s*\}/, 'a failed refresh rejects');
    assert.match(
        refresh,
        /if \(res\.data\?\.status !== 'success' \|\| !takeClassOptions\(res\.data\?\.meta\)\) \{\s*classOptionsKnown\.value = false;\s*throw new Error\(/,
        'a refresh that did not bring the classes rejects too, it is not silently nothing'
    );
});

test('the picker has a "could not load" state with Retry, and "none exist yet" only for an answered empty list', () => {
    const view = read('../views/dashboard/TeachersView.vue');
    const template = view.slice(0, view.indexOf('<script setup'));
    const script = view.slice(view.indexOf('<script setup')).replace(/\/\*[\s\S]*?\*\/|^\s*\/\/.*$/gm, '');

    assert.match(
        script,
        /teacherPickerState\(\{\s*loading: classesLoading\.value,\s*known: teachersStore\.classOptionsKnown,\s*count: classOptions\.value\.length\s*\}\)/
    );

    // The four branches, in order, and each sentence under its own branch only.
    const picker = template.slice(template.indexOf('<!-- Class multiselect -->'), template.indexOf('v-for="option in classOptions"'));
    const branches = [...picker.matchAll(/v-(?:else-)?if="pickerState === '([a-z]+)'"/g)].map((m) => m[1]);
    assert.deepEqual(branches, ['loading', 'failed', 'empty']);

    const failed = picker.slice(picker.indexOf("pickerState === 'failed'"), picker.indexOf("pickerState === 'empty'"));
    const empty = picker.slice(picker.indexOf("pickerState === 'empty'"));

    assert.match(failed, /Could not load the \{\{ classesTerm\.toLowerCase\(\) \}\}\./);
    assert.match(failed, /<button type="button"[^>]*@click="loadClasses">Retry<\/button>/, 'Retry re-reads, and never submits the form');
    assert.doesNotMatch(failed, /exist yet/);
    assert.match(empty, /No \{\{ classesTerm\.toLowerCase\(\) \}\} exist yet\./);
    assert.equal((template.match(/exist yet/g) ?? []).length, 1);
    assert.doesNotMatch(template, /classOptions\.length === 0/, 'an empty list alone no longer means "none exist"');
});
