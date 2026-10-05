/**
 * "Upload a PDF" in the three section editors that have it, MOUNTED: Link Buttons, Programs &
 * Curriculum and Call to Action, each compiled from its .vue file with the real upload control
 * (components/form/SectionDocumentUpload.vue) inside it and a store that answers what the server
 * answers. Each editor sits in tests/support/ModelHost.vue, which feeds every edit back to it as
 * SectionFormModal's v-model does.
 *
 * What only a mounted test can say: that the address lands on the row the office chose even though
 * rows are keyed by position, that the rows hold still while a file is in flight, that a refused file
 * is never sent, and that a section's content only ever carries an address, never a file.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as documentFile from '../core/helpers/sectionDocumentFile.ts';
import { click, compileSfc, deferred, flush, mountSfc, Node, press, type } from './support/mountSfc.ts';

const ADDRESS = 'https://platform.example.test/storage/412/academic-calendar-2026.pdf';
const OTHER_ADDRESS = 'https://platform.example.test/storage/413/class-schedule.pdf';
const HELP = 'Or upload a PDF (up to 25 MB) and its address is filled in for you. Anyone with the address can open it as soon as it is uploaded.';

// What the control says about taking a file offline. Which of the two is TRUE depends on whether the
// file is in the saved section: the server deletes a document when a save stops linking it, compared
// with what was saved.
const SAVED = 'It stays online while a saved section links to it. To take it offline, clear the address and save; uploading another PDF in its place does the same to this one.';
const NOT_SAVED = 'This file is not in the saved section yet. If you clear its address or upload another PDF in its place before you save, it stays online. To take it offline, save the section with it first, then clear the address and save again.';
const NOT_A_PDF = 'This file is not a PDF. Save or print it as a PDF, then upload that.';

// Any sentence saying a field beside the link was filled. (The help line's "its address is filled in
// for you" is about the address, and is always there.)
const FILLED = /(was|were) filled in/;

const docx = () => ({ name: 'calendar.docx', type: 'application/msword', size: 1024 });

const pdf = (name = 'Academic Calendar 2026.pdf', size = 480_000) => ({ name, type: 'application/pdf', size });

/** A store that records each file it is asked to upload. Each test decides the answers. */
function fakeStore(answer: (file: any) => Promise<any>) {
    const calls: any[] = [];

    return { calls, store: { uploadPageDocument: (file: any) => { calls.push(file); return answer(file); } } };
}

const stored = (url = ADDRESS, name = 'Academic Calendar 2026') => async () => ({ url, name, size: 480_000 });

/** Mount an editor with the real upload control, inside the v-model host; `provided` is what the modal would provide. */
async function mountEditor(editorPath: string, initial: any, store: any, provided?: Record<string, any>) {
    const control = await compileSfc('components/form/SectionDocumentUpload.vue', {
        '@/stores/masjid/pagesStore': { usePagesStore: () => store },
        '@/core/helpers/sectionDocumentFile': documentFile,
    });
    const editor = await compileSfc(editorPath, {
        '@/core/types/data/masjid-related/PageSection': {},
        '@/core/types/elements/ImageInput': {},
        '@/components/form/ImageDraggableInput.vue': { default: { render: () => null } },
        '@/components/form/SectionDocumentUpload.vue': { default: control },
        '@/core/helpers/sectionDocumentFile': documentFile,
        '@/composables/useSectionImages': {},
    });

    const sent: any[] = [];
    const screen = await mountSfc('tests/support/ModelHost.vue', { editor, initial, provided, onChange: (value: any) => sent.push(value) }, {});
    await flush();

    const byTitle = (title: string) => screen.all((n) => n.tag === 'button' && n.props.title === title);
    const fileInputs = () => screen.all((n) => n.tag === 'input' && n.props.type === 'file');
    // How often each file picker was opened: the control opens it by clicking its hidden input.
    const pickersOpened: number[] = [];

    return {
        screen,
        sent,
        /** The content the modal would save now. */
        content: () => sent[sent.length - 1],
        fileInputs,
        uploadButtons: () => screen.all((n) => n.tag === 'button' && /Upload a PDF|Replace PDF|Uploading/.test(n.textContent)),
        /** The link fields (URL, Link URL, Button Link), in the order of their rows. */
        linkFields: () => screen.all((n) => n.tag === 'input' && /^https:\/\/example\.com/.test(String(n.props.placeholder ?? ''))),
        byTitle,
        pickersOpened,
        /** Press an upload button as a person does, and count the pickers it opens. */
        pressUpload(nth: number) {
            fileInputs().forEach((input: any, at) => { input.click = () => { pickersOpened[at] = (pickersOpened[at] ?? 0) + 1; }; });
            press(screen.all((n) => n.tag === 'button' && /Upload a PDF|Replace PDF|Uploading/.test(n.textContent))[nth]);
        },
        /** Choose a file in the nth upload control, as the file picker does; resolves when the handler has run as far as it can. */
        async choose(nth: number, file: any) {
            const input: any = fileInputs()[nth];
            input.files = [file];
            // A browser's file input holds the chosen file's path until it is emptied, and fires no
            // `change` when the same file is chosen while it still holds it.
            input.value = `C:\\fakepath\\${file.name}`;
            const done = input.props.onChange({ target: input });
            await flush();

            return done;
        },
    };
}

/** Whether an upload button is marked busy: `aria-disabled`, never `disabled`, which would drop the keyboard's focus. */
const busy = (button: Node) => button.props['aria-disabled'] === 'true';

const links = (...rows: Array<Partial<{ label: string; url: string; icon: string; style: string }>>) => ({
    heading: 'Downloads', description: '', layout: 'stack', background_color: '#ffffff',
    links: rows.map((row) => ({ label: '', url: '', icon: '', style: 'primary', ...row })),
});

const LINK_LIST = 'components/sections/editors/LinkListSectionEditor.vue';
const PROGRAMS = 'components/sections/editors/ProgramsSectionEditor.vue';
const CTA = 'components/sections/editors/CTASectionEditor.vue';

/* ------------------------------------------------------------ Link Buttons */

test('Link Buttons: choosing a PDF uploads it once and writes its address, a label and the download icon into that button', async () => {
    const { store, calls } = fakeStore(stored());
    const editor = await mountEditor(LINK_LIST, links({}, {}), store);

    assert.equal(editor.uploadButtons().length, 2, 'each button has its own upload control');
    assert.ok(editor.screen.text().includes(HELP), 'the office is told the file is public at once');
    assert.equal(editor.fileInputs()[1].props.accept, 'application/pdf,.pdf');

    const file = pdf();
    await editor.choose(1, file);

    assert.equal(calls.length, 1);
    assert.equal(calls[0], file, 'the store was given the chosen file itself');

    // The second button, and only the second.
    assert.deepEqual(editor.content().links, [
        { label: '', url: '', icon: '', style: 'primary' },
        { label: 'Academic Calendar 2026', url: ADDRESS, icon: 'bi-file-earmark-arrow-down', style: 'primary' },
    ]);

    // What the office now reads under that field.
    const text = editor.screen.text();
    assert.ok(text.includes('Document: academic-calendar-2026.pdf'));
    assert.ok(text.includes('Uploaded. It is already online, even before you save.'));
    // The label and the icon appeared without being typed, and the Icon field's own help says to
    // leave it blank for a button with no icon: the office is told, and told it can change them.
    assert.ok(text.includes('The label (from the file\'s name) and a download icon were filled in. Change them if you like.'));
    // It was uploaded a moment ago and is in nothing saved: only the sentence that is true of it.
    assert.ok(text.includes(NOT_SAVED));
    assert.ok(!text.includes('does the same to this one'), 'replacing an unsaved file does NOT take it offline');
    assert.ok(!text.includes(SAVED));
    assert.deepEqual(editor.uploadButtons().map((b) => b.textContent), ['Upload a PDF', 'Replace PDF']);
    const open = editor.screen.all((n) => n.tag === 'a' && n.textContent === 'Open');
    assert.equal(open.length, 1);
    assert.equal(open[0].props.href, ADDRESS);
    assert.equal(open[0].props.target, '_blank');
    assert.equal(open[0].props.rel, 'noopener noreferrer');

    editor.screen.unmount();
});

test('Link Buttons: a label and an icon the office chose are left alone', async () => {
    const { store } = fakeStore(stored());
    const editor = await mountEditor(LINK_LIST, links({ label: 'School Year', url: 'https://example.org/old', icon: 'bi-calendar' }), store);

    await editor.choose(0, pdf());

    assert.deepEqual(editor.content().links, [{ label: 'School Year', url: ADDRESS, icon: 'bi-calendar', style: 'primary' }]);
    // A file put in place of something is the moment the wording can go stale.
    assert.ok(editor.screen.text().includes('Check that the wording beside it still describes this file.'));
    // Nothing was filled, so nothing is said to have been.
    assert.ok(!FILLED.test(editor.screen.text()));

    editor.screen.unmount();
});

test('Link Buttons: a file with no readable name still gets a label, never a raw address', async () => {
    const { store } = fakeStore(stored(ADDRESS, ''));
    const editor = await mountEditor(LINK_LIST, links({}), store);

    await editor.choose(0, pdf('___.pdf'));

    assert.equal(editor.content().links[0].label, 'Document');

    editor.screen.unmount();
});

test('Link Buttons: a file the page tool refuses is never sent, and its sentence is shown', async () => {
    const { store, calls } = fakeStore(stored());
    const editor = await mountEditor(LINK_LIST, links({ url: 'https://example.org/keep' }), store);

    await editor.choose(0, docx());
    assert.ok(editor.screen.text().includes(NOT_A_PDF), 'the sentence says what to do, as the server\'s does');

    await editor.choose(0, pdf('calendar.pdf', 32_715_571));
    assert.ok(editor.screen.text().includes('This file is 31.2 MB. The limit is 25 MB: export it at a lower quality or split it into parts.'));
    assert.ok(!editor.screen.text().includes(NOT_A_PDF), 'the earlier sentence is replaced, not added to');

    assert.equal(calls.length, 0);
    assert.equal(editor.sent.length, 0, 'nothing was written into the section');

    editor.screen.unmount();
});

test('Link Buttons: a refusal by the server is shown word for word and the address is unchanged', async () => {
    const refusal = 'This file is not a PDF. Save or print it as a PDF, then upload that.';
    const { store, calls } = fakeStore(async () => { throw new Error(refusal); });
    const editor = await mountEditor(LINK_LIST, links({ label: 'Calendar', url: 'https://example.org/keep' }), store);

    await editor.choose(0, pdf());

    assert.equal(calls.length, 1);
    const alert = editor.screen.all((n) => n.props.role === 'alert');
    assert.deepEqual(alert.map((n) => n.textContent), [refusal]);
    assert.equal(editor.sent.length, 0, 'the section was not touched');
    assert.deepEqual(editor.uploadButtons().map((b) => [b.textContent, busy(b)]), [['Upload a PDF', false]], 'the control can be used again');
    assert.equal(editor.byTitle('Remove Link')[0].disabled, false, 'and the rows are free again');

    // A second try that works takes the sentence down.
    store.uploadPageDocument = stored();
    await editor.choose(0, pdf());
    assert.deepEqual(editor.screen.all((n) => n.props.role === 'alert'), []);
    assert.equal(editor.content().links[0].url, ADDRESS);

    editor.screen.unmount();
});

test('Link Buttons: what the section carries is an address, never a file, a blob: or a data: value', async () => {
    const { store } = fakeStore(stored());
    const editor = await mountEditor(LINK_LIST, links({}), store);

    const file = pdf();
    await editor.choose(0, file);

    for (const content of editor.sent) {
        const json = JSON.stringify(content);
        assert.ok(!json.includes('blob:') && !json.includes('data:'), json);
        const walk = (value: any): void => {
            assert.notEqual(value, file);
            assert.ok(value === null || ['string', 'number', 'boolean', 'object'].includes(typeof value));
            if (value && typeof value === 'object') Object.values(value).forEach(walk);
        };
        walk(content);
        assert.equal(typeof content.links[0].url, 'string');
    }
    // The file input itself was emptied (it held the file's path when the handler began), so the
    // same file can be chosen again and is a change again.
    assert.equal(editor.fileInputs()[0].value, '');

    editor.screen.unmount();
});

test('Link Buttons: while a PDF is in flight the rows hold still, and its address lands on the button the office chose', async () => {
    const upload = deferred<any>();
    const { store, calls } = fakeStore(() => upload.promise);
    const editor = await mountEditor(LINK_LIST, links({ label: 'Curriculum' }, { label: 'Calendar' }, { label: 'Schedule' }), store);

    // The office starts an upload on the MIDDLE button.
    void editor.choose(1, pdf());
    await flush();

    assert.deepEqual(editor.uploadButtons().map((b) => [b.textContent, busy(b)]), [
        ['Upload a PDF', false], ['Uploading…', true], ['Upload a PDF', false],
    ]);
    // Busy, and shown as busy, but never `disabled`: the button the office just pressed keeps the
    // keyboard's focus instead of dropping it to the page.
    const uploadingButton = editor.uploadButtons()[1];
    assert.equal(uploadingButton.disabled, false);
    assert.ok(String(uploadingButton.props.class).split(' ').includes('disabled'));
    // Pressing it while it is busy opens no file picker.
    editor.pressUpload(1);
    assert.deepEqual(editor.pickersOpened, []);
    assert.ok(editor.screen.text().includes('A PDF is uploading. Links can be added, moved or removed again when it has finished.'));

    // Add, Move and Remove are all off...
    const structural = () => [editor.screen.button('Add Link'), ...editor.byTitle('Move Up'), ...editor.byTitle('Move Down'), ...editor.byTitle('Remove Link')];
    assert.equal(structural().length, 10);
    assert.deepEqual(structural().map((b) => b.disabled), Array(10).fill(true));
    // ...and a tap the re-render missed does nothing either.
    structural().forEach((button) => { assert.equal(click(button), false); press(button); });
    await flush();
    assert.equal(editor.sent.length, 0, 'no row was added, moved or removed');

    // A second file chosen on the same control while the first is in flight is not sent.
    void editor.choose(1, pdf('Another.pdf'));
    await flush();
    assert.equal(calls.length, 1);

    upload.resolve({ url: ADDRESS, name: 'Academic Calendar 2026', size: 480_000 });
    await flush();

    assert.deepEqual(editor.content().links.map((l: any) => [l.label, l.url]), [
        ['Curriculum', ''], ['Calendar', ADDRESS], ['Schedule', ''],
    ]);
    assert.ok(!editor.screen.text().includes('A PDF is uploading.'));
    assert.deepEqual(
        structural().map((b) => b.disabled),
        // Add, Up x3, Down x3, Remove x3: only the ends of the list are off again.
        [false, true, false, false, false, false, true, false, false, false],
    );
    // And the button opens the picker again.
    assert.equal(busy(editor.uploadButtons()[1]), false);
    editor.pressUpload(1);
    assert.deepEqual(editor.pickersOpened[1], 1);

    editor.screen.unmount();
});

test('Link Buttons: after the upload, Move Down takes the address with its button, and the note does not stay behind', async () => {
    const { store } = fakeStore(stored());
    const editor = await mountEditor(LINK_LIST, links({ label: 'Calendar' }, { label: 'Schedule' }), store);

    await editor.choose(0, pdf());
    assert.ok(editor.screen.text().includes('Uploaded. It is already online'));

    click(editor.byTitle('Move Down')[0]);
    await flush();

    assert.deepEqual(editor.content().links.map((l: any) => [l.label, l.url]), [['Schedule', ''], ['Calendar', ADDRESS]]);
    // The controls follow the DATA: the second row now offers Replace, the first Upload.
    assert.deepEqual(editor.uploadButtons().map((b) => b.textContent), ['Upload a PDF', 'Replace PDF']);
    assert.equal(editor.screen.all((n) => n.tag === 'a' && n.textContent === 'Open').length, 1);

    // Removing the button removes its address from what would be saved.
    click(editor.byTitle('Remove Link')[1]);
    await flush();
    assert.deepEqual(editor.content().links.map((l: any) => [l.label, l.url]), [['Schedule', '']]);
    assert.ok(!editor.screen.text().includes('Document:'));

    editor.screen.unmount();
});

test('Link Buttons: a refusal shown under one button does not stay behind under the button that takes its place', async () => {
    const { store } = fakeStore(stored());
    const editor = await mountEditor(LINK_LIST, links({ label: 'Calendar' }, { label: 'Schedule' }, { label: 'Fees' }), store);
    const alerts = () => editor.screen.all((n) => n.props.role === 'alert').map((n) => n.textContent);

    // Rows are keyed by position, so the control in first place stays in first place when the rows
    // move. Whatever it last said was about the button that has now gone elsewhere.
    await editor.choose(0, docx());
    assert.deepEqual(alerts(), [NOT_A_PDF]);
    click(editor.byTitle('Move Down')[0]);
    await flush();
    assert.deepEqual(editor.content().links.map((l: any) => l.label), ['Schedule', 'Calendar', 'Fees']);
    assert.deepEqual(alerts(), []);

    await editor.choose(1, docx());
    assert.equal(alerts().length, 1);
    click(editor.byTitle('Remove Link')[1]);
    await flush();
    assert.deepEqual(editor.content().links.map((l: any) => l.label), ['Schedule', 'Fees']);
    assert.deepEqual(alerts(), [], 'the removed button\'s refusal is not shown under Fees');

    // Adding a button moves nothing, so a sentence the office is still reading stays.
    await editor.choose(0, docx());
    click(editor.screen.button('Add Link'));
    await flush();
    assert.equal(editor.content().links.length, 3);
    assert.equal(alerts().length, 1);

    editor.screen.unmount();
});

test('Link Buttons: Move Up takes a refusal away with its button too, and the note about an upload with it', async () => {
    const { store } = fakeStore(stored());
    const editor = await mountEditor(LINK_LIST, links({ label: 'Calendar' }, { label: 'Schedule' }, { label: 'Fees' }), store);
    const alerts = () => editor.screen.all((n) => n.props.role === 'alert').map((n) => n.textContent);

    // A refusal under the LAST button, which then moves up: the control in last place is now Schedule's.
    await editor.choose(2, docx());
    assert.deepEqual(alerts(), [NOT_A_PDF]);
    click(editor.byTitle('Move Up')[2]);
    await flush();
    assert.deepEqual(editor.content().links.map((l: any) => l.label), ['Calendar', 'Fees', 'Schedule']);
    assert.deepEqual(alerts(), [], 'the refusal was about Fees, and is not shown under Schedule');

    // The same for what a control says after an upload: it is about the file, and goes with it.
    await editor.choose(2, pdf());
    assert.ok(editor.screen.text().includes('Uploaded. It is already online'));
    click(editor.byTitle('Move Up')[2]);
    await flush();
    assert.deepEqual(editor.content().links.map((l: any) => [l.label, l.url]), [['Calendar', ''], ['Schedule', ADDRESS], ['Fees', '']]);
    assert.ok(!editor.screen.text().includes('Uploaded. It is already online'));
    assert.deepEqual(editor.uploadButtons().map((b) => b.textContent), ['Upload a PDF', 'Replace PDF', 'Upload a PDF']);

    editor.screen.unmount();
});

test('Link Buttons: once the address is edited by hand, nothing more is said about the upload', async () => {
    const { store } = fakeStore(stored());
    const editor = await mountEditor(LINK_LIST, links({ label: 'Calendar' }), store);

    await editor.choose(0, pdf());
    assert.ok(editor.screen.text().includes('Uploaded. It is already online, even before you save.'));

    // The office types another address over it: the note was about the file that was there.
    type(editor.linkFields()[0], 'https://example.org/somewhere-else');
    await flush();
    assert.equal(editor.linkFields()[0].value, 'https://example.org/somewhere-else');
    assert.ok(!editor.screen.text().includes('Uploaded.'));
    assert.ok(!editor.screen.text().includes('Document:'));
    assert.deepEqual(editor.uploadButtons().map((b) => b.textContent), ['Upload a PDF']);

    // Put back, it is that upload again.
    type(editor.linkFields()[0], ADDRESS);
    await flush();
    assert.ok(editor.screen.text().includes('Uploaded. It is already online, even before you save.'));

    editor.screen.unmount();
});

test('Link Buttons: each button\'s upload control and Open link are named for that button', async () => {
    const { store } = fakeStore(stored());
    const editor = await mountEditor(LINK_LIST, links({ label: 'Calendar', url: ADDRESS }, { label: '  ' }, { label: 'Fees', url: OTHER_ADDRESS }), store);
    const name = (n: Node) => n.props['aria-label'];

    // Four rows would otherwise be four buttons called "Upload a PDF" and four links called "Open".
    // Each name still begins with the words on the control.
    assert.deepEqual(editor.uploadButtons().map((b) => [b.textContent, name(b)]), [
        ['Replace PDF', 'Replace PDF for Calendar'],
        ['Upload a PDF', 'Upload a PDF for Link 2'],
        ['Replace PDF', 'Replace PDF for Fees'],
    ]);
    assert.deepEqual(editor.screen.all((n) => n.tag === 'a' && n.textContent === 'Open').map(name), [
        'Open the PDF for Calendar', 'Open the PDF for Fees',
    ]);

    // While it uploads the name says so, as the button does.
    const upload = deferred<any>();
    store.uploadPageDocument = () => upload.promise;
    void editor.choose(1, pdf());
    await flush();
    assert.equal(name(editor.uploadButtons()[1]), 'Uploading… for Link 2');
    upload.resolve({ url: ADDRESS, name: 'Academic Calendar 2026', size: 1 });
    await flush();
    // The label the upload filled is the name the control now carries.
    assert.equal(name(editor.uploadButtons()[1]), 'Replace PDF for Academic Calendar 2026');

    editor.screen.unmount();
});

test('Link Buttons: only what was blank is said to have been filled', async () => {
    const { store } = fakeStore(stored());
    const text = (editor: any) => editor.screen.text();

    const labelOnly = await mountEditor(LINK_LIST, links({ icon: 'bi-calendar' }), store);
    await labelOnly.choose(0, pdf());
    assert.ok(text(labelOnly).includes('The label was filled in from the file\'s name. Change it if you like.'));
    assert.ok(!text(labelOnly).includes('download icon'));
    assert.equal(labelOnly.content().links[0].icon, 'bi-calendar');

    const iconOnly = await mountEditor(LINK_LIST, links({ label: 'School Year' }), store);
    await iconOnly.choose(0, pdf());
    assert.ok(text(iconOnly).includes('A download icon was filled in. Change it, or clear it, if you like.'));
    assert.ok(!text(iconOnly).includes('The label'));
    assert.equal(iconOnly.content().links[0].label, 'School Year');

    labelOnly.screen.unmount();
    iconOnly.screen.unmount();
});

/* ------------------------------------- saved or not: what is true of each file */

const SAVED_CALENDAR = 'https://platform.example.test/storage/400/calendar-2025.pdf';
const savedCalendar = { path: '/storage/400/calendar-2025.pdf', name: 'calendar-2025.pdf' };

test('a document the saved section links is told how to take it offline; one uploaded since is told the truth instead', async () => {
    const { store } = fakeStore(stored());
    // As SectionFormModal provides it: the page documents in the section as it was last saved.
    const editor = await mountEditor(LINK_LIST, links({ label: 'Calendar', url: SAVED_CALENDAR }, { label: 'Schedule' }), store, {
        sectionSavedDocuments: [savedCalendar],
    });

    // The saved one: clearing or replacing it and saving DOES delete it.
    assert.ok(editor.screen.text().includes(SAVED));
    assert.ok(!editor.screen.text().includes(NOT_SAVED));

    // A new upload on the other button is in nothing saved.
    await editor.choose(1, pdf());
    const text = editor.screen.text();
    assert.equal(text.split(SAVED).length - 1, 1, 'the saved document\'s sentence, once');
    assert.equal(text.split(NOT_SAVED).length - 1, 1, 'the new upload\'s sentence, once');

    // Rows are keyed by position, so moving them makes each control anew. The new upload is still
    // unsaved, and must not be promised what is only true of a saved file.
    click(editor.byTitle('Move Up')[1]);
    await flush();
    assert.deepEqual(editor.content().links.map((l: any) => l.url), [ADDRESS, SAVED_CALENDAR]);
    const moved = editor.screen.text();
    assert.ok(!moved.includes('Uploaded.'), 'the note about the upload went with the control');
    assert.equal(moved.split(SAVED).length - 1, 1);
    assert.equal(moved.split(NOT_SAVED).length - 1, 1);
    assert.ok(moved.indexOf(NOT_SAVED) < moved.indexOf(SAVED), 'each sentence is under its own button');

    // The same address written another way is the same saved document.
    type(editor.linkFields()[1], 'http://another-host.example.test/storage/400/calendar-2025.pdf?v=2');
    await flush();
    assert.equal(editor.screen.text().split(SAVED).length - 1, 1);

    editor.screen.unmount();
});

test('replacing a file that was never saved leaves it online, and the control says so with its address', async () => {
    const { store } = fakeStore(stored());
    const editor = await mountEditor(LINK_LIST, links({ label: 'Calendar' }), store, { sectionSavedDocuments: [] });
    const leftOnline = () => editor.screen.all((n) => n.textContent.startsWith('The PDF this one replaced')).map((n) => n.textContent);

    // The wrong file, then the natural reaction: Replace PDF with the right one.
    await editor.choose(0, pdf('Wrong File.pdf'));
    assert.deepEqual(leftOnline(), []);
    store.uploadPageDocument = stored(OTHER_ADDRESS, 'Class Schedule');
    await editor.choose(0, pdf('Class Schedule.pdf'));
    assert.equal(editor.content().links[0].url, OTHER_ADDRESS);

    // No save ever linked the first file, so no save will delete it, and its address is now in no
    // field: this is the last screen that can show it.
    assert.deepEqual(leftOnline(), [
        'The PDF this one replaced (academic-calendar-2026.pdf) was not in the saved section, so replacing it did not take it '
        + 'offline: it is still online. To take it offline, save a section with its address in a link, then clear the address '
        + `and save again. Its address: ${ADDRESS}`,
    ]);

    // Put back into the field, it is not left anywhere.
    type(editor.linkFields()[0], ADDRESS);
    await flush();
    assert.deepEqual(leftOnline(), []);

    editor.screen.unmount();
});

test('replacing a file the saved section links says nothing of the kind: that one IS taken offline by the save', async () => {
    const { store } = fakeStore(stored());
    const editor = await mountEditor(LINK_LIST, links({ label: 'Calendar', url: SAVED_CALENDAR }), store, {
        sectionSavedDocuments: [savedCalendar],
    });

    await editor.choose(0, pdf());

    assert.equal(editor.content().links[0].url, ADDRESS);
    assert.ok(!editor.screen.text().includes('The PDF this one replaced'));
    assert.ok(editor.screen.text().includes(NOT_SAVED));
    // And something that was not a document at all is nobody's file to warn about.
    const other = await mountEditor(LINK_LIST, links({ label: 'Calendar', url: 'https://example.org/old' }), store, { sectionSavedDocuments: [] });
    await other.choose(0, pdf());
    assert.ok(!other.screen.text().includes('The PDF this one replaced'));

    other.screen.unmount();
    editor.screen.unmount();
});

test('the modal\'s count of uploads is raised while a file is in flight, lowered when it ends or fails, and never left raised', async () => {
    const first = deferred<any>();
    const second = deferred<any>();
    const third = deferred<any>();
    const answers = [first, second, third];
    const { store } = fakeStore(() => answers.shift()!.promise);
    // As SectionFormModal provides it. Vue is the test's own copy; the count is a plain ref.
    const uploads = { value: 0 };
    const editor = await mountEditor(LINK_LIST, links({ label: 'Calendar' }, { label: 'Schedule' }), store, { sectionDocumentUploads: uploads });

    void editor.choose(0, pdf());
    void editor.choose(1, pdf('Class Schedule.pdf'));
    await flush();
    assert.equal(uploads.value, 2);

    // One ends, one is refused by the server.
    first.resolve({ url: ADDRESS, name: 'Academic Calendar 2026', size: 1 });
    await flush();
    assert.equal(uploads.value, 1);
    second.reject(new Error(NOT_A_PDF));
    await flush();
    assert.equal(uploads.value, 0);

    // One is still in flight when its control goes (the editor is closed): it is not counted for
    // ever, and its late answer does not lower the count a second time.
    void editor.choose(1, pdf('Class Schedule.pdf'));
    await flush();
    assert.equal(uploads.value, 1);
    editor.screen.unmount();
    assert.equal(uploads.value, 0);
    third.resolve({ url: OTHER_ADDRESS, name: 'Class Schedule', size: 1 });
    await flush();
    assert.equal(uploads.value, 0);
});

test('Link Buttons: two uploads at once each land on their own button, and the rows are held until both end', async () => {
    const first = deferred<any>();
    const second = deferred<any>();
    const answers = [first, second];
    const { store } = fakeStore(() => answers.shift()!.promise);
    const editor = await mountEditor(LINK_LIST, links({ label: 'Calendar' }, { label: 'Schedule' }), store);

    void editor.choose(0, pdf());
    void editor.choose(1, pdf('Class Schedule.pdf'));
    await flush();

    // The later one answers first.
    second.resolve({ url: OTHER_ADDRESS, name: 'Class Schedule', size: 1 });
    await flush();
    assert.equal(editor.byTitle('Remove Link')[0].disabled, true, 'one upload is still in flight');

    first.resolve({ url: ADDRESS, name: 'Academic Calendar 2026', size: 1 });
    await flush();

    assert.deepEqual(editor.content().links.map((l: any) => [l.label, l.url]), [['Calendar', ADDRESS], ['Schedule', OTHER_ADDRESS]]);
    assert.equal(editor.byTitle('Remove Link')[0].disabled, false);

    editor.screen.unmount();
});

/* ---------------------------------------------------- Programs & Curriculum */

const programs = (...rows: Array<Record<string, any>>) => ({
    heading: 'Our Programs', description: '', layout: 'cards', columns: 3, background_color: '#ffffff',
    programs: rows.map((row) => ({
        name: '', level: '', schedule: '', summary: '', highlights: [], image_url: null, link_url: '', link_text: '', ...row,
    })),
});

test('Programs: the PDF\'s address goes into that program\'s Link URL, and a blank Link Text is filled from the file\'s name', async () => {
    const { store, calls } = fakeStore(stored());
    const editor = await mountEditor(PROGRAMS, programs({ name: 'Elementary' }, { name: 'Middle School', link_text: 'Read the curriculum' }), store);

    // Where the office first tried to put a document, it is told where one goes.
    assert.equal(
        editor.screen.all((n) => n.textContent === 'Images only. To attach a PDF, use Upload a PDF under Link URL.').length >= 2,
        true,
        'each program\'s image box says it takes images only',
    );

    await editor.choose(0, pdf());
    assert.equal(calls.length, 1);
    assert.deepEqual(editor.content().programs.map((p: any) => [p.name, p.link_url, p.link_text]), [
        ['Elementary', ADDRESS, 'Academic Calendar 2026'],
        ['Middle School', '', 'Read the curriculum'],
    ]);
    // The office is told the words on the link were filled, and each control is named for its program.
    assert.equal(editor.screen.text().split('Link Text was filled in from the file\'s name. Change it if you like.').length - 1, 1);
    assert.deepEqual(editor.uploadButtons().map((b) => b.props['aria-label']), ['Replace PDF for Elementary', 'Upload a PDF for Middle School']);

    // Words the office wrote are kept.
    store.uploadPageDocument = stored(OTHER_ADDRESS, 'Class Schedule');
    await editor.choose(1, pdf('Class Schedule.pdf'));
    assert.deepEqual(editor.content().programs.map((p: any) => [p.link_url, p.link_text]), [
        [ADDRESS, 'Academic Calendar 2026'],
        [OTHER_ADDRESS, 'Read the curriculum'],
    ]);
    // Words the office wrote were not filled, and are not said to have been.
    assert.equal(editor.screen.text().split('Link Text was filled in').length - 1, 1);

    // A name with nothing to read still leaves words on the link.
    const blank = await mountEditor(PROGRAMS, programs({ name: 'Elementary' }), fakeStore(stored(ADDRESS, '')).store);
    await blank.choose(0, pdf('___.pdf'));
    assert.equal(blank.content().programs[0].link_text, 'View PDF');

    blank.screen.unmount();
    editor.screen.unmount();
});

test('Programs: the rows hold still while a PDF is in flight, and Move Up then takes the address with its program', async () => {
    const upload = deferred<any>();
    const { store } = fakeStore(() => upload.promise);
    const editor = await mountEditor(PROGRAMS, programs({ name: 'Elementary' }, { name: 'Middle School' }), store);

    void editor.choose(1, pdf());
    await flush();

    const structural = () => [editor.screen.button('Add Program'), ...editor.byTitle('Move Up'), ...editor.byTitle('Move Down'), ...editor.byTitle('Remove Program')];
    assert.deepEqual(structural().map((b) => b.disabled), Array(7).fill(true));
    structural().forEach((button) => press(button));
    await flush();
    assert.equal(editor.sent.length, 0);

    upload.resolve({ url: ADDRESS, name: 'Academic Calendar 2026', size: 1 });
    await flush();
    assert.deepEqual(editor.content().programs.map((p: any) => [p.name, p.link_url]), [['Elementary', ''], ['Middle School', ADDRESS]]);

    click(editor.byTitle('Move Up')[1]);
    await flush();
    assert.deepEqual(editor.content().programs.map((p: any) => [p.name, p.link_url]), [['Middle School', ADDRESS], ['Elementary', '']]);

    // A refusal shown under one program does not stay behind under the program that takes its place.
    const alerts = () => editor.screen.all((n) => n.props.role === 'alert').length;
    await editor.choose(1, docx());
    assert.equal(alerts(), 1);
    click(editor.byTitle('Move Up')[1]);
    await flush();
    assert.equal(alerts(), 0);
    await editor.choose(0, docx());
    assert.equal(alerts(), 1);
    click(editor.byTitle('Remove Program')[0]);
    await flush();
    assert.equal(alerts(), 0);
    assert.deepEqual(editor.content().programs.map((p: any) => p.name), ['Middle School']);

    editor.screen.unmount();
});

/* ------------------------------------------------------------ Call to Action */

test('Call to Action: the PDF\'s address goes into Button Link and the button\'s text is left as it was', async () => {
    const { store, calls } = fakeStore(stored());
    const initial = {
        heading: 'Read the calendar', description: '', button_text: 'Open the Calendar', button_link: '',
        button_style: 'primary', background_image_url: null, background_color: '#f8f9fa',
    };
    const editor = await mountEditor(CTA, initial, store);

    assert.ok(editor.screen.text().includes(HELP));
    assert.equal(editor.uploadButtons().length, 1);

    const file = pdf();
    await editor.choose(0, file);

    assert.equal(calls[0], file);
    assert.deepEqual(editor.content(), { ...initial, button_link: ADDRESS });
    assert.ok(editor.screen.text().includes('Document: academic-calendar-2026.pdf'));
    // Nothing beside the link is filled here, so nothing is said to have been; and there is one
    // control, so its words alone name it.
    assert.ok(!FILLED.test(editor.screen.text()));
    assert.equal(editor.uploadButtons()[0].props['aria-label'], undefined);

    editor.screen.unmount();
});

/* ------------------------------------------------- the control, on its own */

test('the control says nothing about an address that is not a page document, and an upload that outlives it is not counted for ever', async () => {
    const upload = deferred<any>();
    const { store } = fakeStore(() => upload.promise);
    const events: Array<[string, any]> = [];
    const screen = await mountSfc('components/form/SectionDocumentUpload.vue', {
        value: 'https://example.org/files/calendar.pdf',
        onBusy: (busy: boolean) => events.push(['busy', busy]),
        onUploaded: (document: any) => events.push(['uploaded', document]),
    }, {
        '@/stores/masjid/pagesStore': { usePagesStore: () => store },
        '@/core/helpers/sectionDocumentFile': documentFile,
    });

    // A PDF somewhere else: no "Document:", no promise about taking it offline, and Upload, not Replace.
    assert.ok(!screen.text().includes('Document:'));
    assert.ok(!screen.text().includes('take it offline'));
    assert.equal(busy(screen.button('Upload a PDF')), false);

    const input: any = screen.all((n: Node) => n.tag === 'input')[0];
    input.files = [pdf()];
    void input.props.onChange({ target: input });
    await flush();
    assert.deepEqual(events, [['busy', true]]);

    // The editor is closed (or its section type changed) before the answer comes.
    screen.unmount();
    assert.deepEqual(events, [['busy', true], ['busy', false]]);

    upload.resolve({ url: ADDRESS, name: 'Academic Calendar 2026', size: 1 });
    await flush();
    assert.deepEqual(events, [['busy', true], ['busy', false]], 'nothing is written into an editor that is gone');
});
