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
import { click, compileSfc, deferred, flush, mountSfc, Node, press } from './support/mountSfc.ts';

const ADDRESS = 'https://platform.example.test/storage/412/academic-calendar-2026.pdf';
const OTHER_ADDRESS = 'https://platform.example.test/storage/413/class-schedule.pdf';
const HELP = 'Or upload a PDF (up to 25 MB) and its address is filled in for you. Anyone with the address can open it as soon as it is uploaded.';

const pdf = (name = 'Academic Calendar 2026.pdf', size = 480_000) => ({ name, type: 'application/pdf', size });

/** A store that records each file it is asked to upload. Each test decides the answers. */
function fakeStore(answer: (file: any) => Promise<any>) {
    const calls: any[] = [];

    return { calls, store: { uploadPageDocument: (file: any) => { calls.push(file); return answer(file); } } };
}

const stored = (url = ADDRESS, name = 'Academic Calendar 2026') => async () => ({ url, name, size: 480_000 });

/** Mount an editor with the real upload control, inside the v-model host. */
async function mountEditor(editorPath: string, initial: any, store: any) {
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
    const screen = await mountSfc('tests/support/ModelHost.vue', { editor, initial, onChange: (value: any) => sent.push(value) }, {});
    await flush();

    const byTitle = (title: string) => screen.all((n) => n.tag === 'button' && n.props.title === title);

    return {
        screen,
        sent,
        /** The content the modal would save now. */
        content: () => sent[sent.length - 1],
        fileInputs: () => screen.all((n) => n.tag === 'input' && n.props.type === 'file'),
        uploadButtons: () => screen.all((n) => n.tag === 'button' && /Upload a PDF|Replace PDF|Uploading/.test(n.textContent)),
        byTitle,
        /** Choose a file in the nth upload control, as the file picker does; resolves when the handler has run as far as it can. */
        async choose(nth: number, file: any) {
            const input: any = screen.all((n) => n.tag === 'input' && n.props.type === 'file')[nth];
            input.files = [file];
            const done = input.props.onChange({ target: input });
            await flush();

            return done;
        },
    };
}

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
    assert.ok(text.includes('It stays online while a saved section links to it. To take it offline, clear the address and save'));
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

    await editor.choose(0, { name: 'calendar.docx', type: 'application/msword', size: 1024 });
    assert.ok(editor.screen.text().includes('Choose a PDF file (a name ending in .pdf).'));

    await editor.choose(0, pdf('calendar.pdf', 32_715_571));
    assert.ok(editor.screen.text().includes('This file is 31.2 MB. The limit is 25 MB: export it at a lower quality or split it into parts.'));
    assert.ok(!editor.screen.text().includes('Choose a PDF file'), 'the earlier sentence is replaced, not added to');

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
    assert.deepEqual(editor.uploadButtons().map((b) => [b.textContent, b.disabled]), [['Upload a PDF', false]], 'the control can be used again');
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
    // The file input itself was emptied, so the same file can be chosen again.
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

    assert.deepEqual(editor.uploadButtons().map((b) => [b.textContent, b.disabled]), [
        ['Upload a PDF', false], ['Uploading…', true], ['Upload a PDF', false],
    ]);
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
    await editor.choose(0, { name: 'calendar.docx', type: 'application/msword', size: 1024 });
    assert.deepEqual(alerts(), ['Choose a PDF file (a name ending in .pdf).']);
    click(editor.byTitle('Move Down')[0]);
    await flush();
    assert.deepEqual(editor.content().links.map((l: any) => l.label), ['Schedule', 'Calendar', 'Fees']);
    assert.deepEqual(alerts(), []);

    await editor.choose(1, { name: 'calendar.docx', type: 'application/msword', size: 1024 });
    assert.equal(alerts().length, 1);
    click(editor.byTitle('Remove Link')[1]);
    await flush();
    assert.deepEqual(editor.content().links.map((l: any) => l.label), ['Schedule', 'Fees']);
    assert.deepEqual(alerts(), [], 'the removed button\'s refusal is not shown under Fees');

    // Adding a button moves nothing, so a sentence the office is still reading stays.
    await editor.choose(0, { name: 'calendar.docx', type: 'application/msword', size: 1024 });
    click(editor.screen.button('Add Link'));
    await flush();
    assert.equal(editor.content().links.length, 3);
    assert.equal(alerts().length, 1);

    editor.screen.unmount();
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

    // Words the office wrote are kept.
    store.uploadPageDocument = stored(OTHER_ADDRESS, 'Class Schedule');
    await editor.choose(1, pdf('Class Schedule.pdf'));
    assert.deepEqual(editor.content().programs.map((p: any) => [p.link_url, p.link_text]), [
        [ADDRESS, 'Academic Calendar 2026'],
        [OTHER_ADDRESS, 'Read the curriculum'],
    ]);

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
    await editor.choose(1, { name: 'curriculum.docx', type: 'application/msword', size: 1024 });
    assert.equal(alerts(), 1);
    click(editor.byTitle('Move Up')[1]);
    await flush();
    assert.equal(alerts(), 0);
    await editor.choose(0, { name: 'curriculum.docx', type: 'application/msword', size: 1024 });
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
    assert.equal(screen.button('Upload a PDF').disabled, false);

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
