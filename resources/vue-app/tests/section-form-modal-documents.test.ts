/**
 * SectionFormModal and the PDFs its editors upload, MOUNTED: the real modal, with the real Link
 * Buttons, Programs & Curriculum and Call to Action editors and the real upload control inside it
 * (components/form/SectionDocumentUpload.vue). Only the store, the message box, the live preview and
 * the editors that carry no upload control are stood in for.
 *
 * What only the modal can do, and so only this file can say:
 *
 *  - SAVE WAITS FOR AN UPLOAD. A PDF is sent at once and its address is written into the content
 *    when the answer comes. A section saved before that is saved without the address, the answer
 *    lands in an editor that is gone, and the file is online, linked from nowhere.
 *  - WHAT SAVE WILL TAKE OFFLINE is said beside Save, whichever editor or row let the file go.
 *  - WHICH FILES ARE SAVED is known here (the section as it was opened), and decides which sentence
 *    the control may truthfully show.
 *  - AN EDIT THAT IS ABANDONED IS GONE. The modal is opened on the object the page list holds, and
 *    Cancel does not reload that list. Its form is a copy of its own, so nothing an editor did before
 *    Cancel is there the next time the section is opened.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import * as documentFile from '../core/helpers/sectionDocumentFile.ts';
import * as shopSection from '../core/helpers/shopSection.ts';
import { check, click, compileSfc, deferred, flush, loadTs, mountSfc, Node, press, select, submit, type } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');

const MODAL = 'components/modals/SectionFormModal.vue';
const WITH_UPLOAD = ['LinkListSectionEditor', 'ProgramsSectionEditor', 'CTASectionEditor'];

const SAVED_ADDRESS = 'https://platform.example.test/storage/400/calendar-2025.pdf';
const ADDRESS = 'https://platform.example.test/storage/412/academic-calendar-2026.pdf';
const UPLOADING = 'A PDF is still uploading.';
const LEAVING = 'calendar-2025.pdf : This file is taken offline when you save, unless another saved section still links it.';
const SAVED = 'It stays online while a saved section links to it. To take it offline, clear the address and save; uploading another PDF in its place does the same to this one.';
const NOT_SAVED = 'This file is not in the saved section yet.';

const pdf = (name = 'Academic Calendar 2026.pdf') => ({ name, type: 'application/pdf', size: 480_000 });

const section = (section_type: string, content: any) => ({
    id: 9, section_type, title: 'Downloads', content, order: 1, platforms: ['web', 'mobile'], is_active: true, settings: {},
});

const linkList = (...rows: Array<Record<string, string>>) => section('link_list', {
    heading: 'Downloads', description: '', layout: 'stack', background_color: '#ffffff',
    links: rows.map((row) => ({ label: '', url: '', icon: '', style: 'primary', ...row })),
});

/** What `mountModal` may be told besides the section and the upload's answers. */
interface ModalOptions {
    /** What the office answers when the modal asks before closing. */
    closeAnyway?: boolean;
    /**
     * Stand-ins for editors a test wants to drive itself, by file name (`StatsSectionEditor`), in
     * place of the empty ones. Handed the real upload control, compiled against the same store.
     */
    editors?: (parts: { control: any }) => Record<string, any>;
    /** The store's section types, for a NEW section. */
    sectionTypes?: any[];
}

/**
 * Mount the modal on a saved section, or on none (a new section). `upload` answers each file the
 * control sends; a bare `true` or `false` is `closeAnyway`.
 */
async function mountModal(saved: any, upload: (file: any) => Promise<any>, options: boolean | ModalOptions = false) {
    const { closeAnyway = false, editors: standIns, sectionTypes = [] }: ModalOptions = typeof options === 'boolean' ? { closeAnyway: options } : options;
    const saves: Array<{ pageId: number; sectionId: number | null; content: any }> = [];
    const uploads: any[] = [];
    const store = {
        sectionTypes,
        sectionsLibrary: [],
        fetchSectionTypes: async () => {},
        fetchSectionsLibrary: async () => {},
        fetchPageSections: async () => [],
        uploadPageDocument: (file: any) => { uploads.push(file); return upload(file); },
        updateSectionWithImages: async (pageId: number, sectionId: number, body: FormData) => {
            saves.push({ pageId, sectionId, content: JSON.parse(String(body.get('content'))) });
        },
        createSectionWithImages: async (pageId: number, body: FormData) => {
            saves.push({ pageId, sectionId: null, content: JSON.parse(String(body.get('content'))) });
        },
    };

    const control = await compileSfc('components/form/SectionDocumentUpload.vue', {
        '@/stores/masjid/pagesStore': { usePagesStore: () => store },
        '@/core/helpers/sectionDocumentFile': documentFile,
    });
    const driven = standIns?.({ control }) ?? {};

    // Every editor the modal imports is an empty stand-in, except the three that carry the control
    // and any the test drives itself.
    const source = readFileSync(new URL(`../${MODAL}`, import.meta.url), 'utf8');
    const editors: Record<string, any> = {};
    for (const [, spec, name] of source.matchAll(/from '(@\/components\/sections\/editors\/(\w+)\.vue)'/g)) {
        editors[spec] = {
            default: name in driven
                ? driven[name]
                : WITH_UPLOAD.includes(name)
                    ? await compileSfc(`components/sections/editors/${name}.vue`, {
                        '@/core/types/data/masjid-related/PageSection': {},
                        '@/core/types/elements/ImageInput': {},
                        '@/components/form/ImageDraggableInput.vue': { default: { render: () => null } },
                        '@/components/form/SectionDocumentUpload.vue': { default: control },
                        '@/core/helpers/sectionDocumentFile': documentFile,
                        '@/composables/useSectionImages': {},
                    })
                    : { render: () => null },
        };
    }
    assert.equal(Object.keys(editors).length >= 29, true, 'the modal\'s editors were not found in its source');
    for (const name of Object.keys(driven)) {
        assert.ok(`@/components/sections/editors/${name}.vue` in editors, `the modal imports no editor called ${name}`);
    }

    const asked: any[] = [];
    const emitted = { close: 0, saved: 0 };
    const screen = await mountSfc(MODAL, {
        section: saved,
        pageId: 3,
        onClose: () => { emitted.close++; },
        onSaved: () => { emitted.saved++; },
    }, {
        '@/core/types/data/masjid-related/PageSection': {},
        '@/stores/masjid/pagesStore': { usePagesStore: () => store },
        '@/core/helpers/shopSection': shopSection,
        '@/core/helpers/sectionDocumentFile': documentFile,
        '@/composables/useSectionImages': await loadTs('composables/useSectionImages.ts', { vue }),
        '@/composables/useLivePreview': { pagePath: (slug: string) => `/${slug}`, usePreviewAvailability: () => ({ value: false }) },
        '@/components/preview/LivePreviewPane.vue': { default: { render: () => null } },
        sweetalert2: { default: { fire: (options: any) => { asked.push(options); return Promise.resolve({ isConfirmed: closeAnyway }); } } },
        ...editors,
    });
    await flush();

    const fileInputs = () => screen.all((n) => n.tag === 'input' && n.props.type === 'file');
    const byPlaceholder = (starts: RegExp) => screen.all((n) => n.tag === 'input' && starts.test(String(n.props.placeholder ?? '')));

    return {
        screen,
        saves,
        uploads,
        asked,
        emitted,
        save: () => screen.button('Update Section'),
        create: () => screen.button('Create Section'),
        cancel: () => screen.button('Cancel'),
        closeButton: () => screen.all((n) => n.tag === 'button' && String(n.props.class ?? '').includes('btn-close'))[0],
        form: () => screen.all((n) => n.tag === 'form')[0],
        /** The footer's notes, beside Save. */
        notes: () => screen.all((n) => String(n.props.class ?? '').includes('section-form-notes'))[0].textContent,
        linkFields: () => byPlaceholder(/^https:\/\/example\.com/),
        /** Link Buttons' Label fields, in the order of their rows. */
        labelFields: () => byPlaceholder(/^e\.g\., Email Us/),
        /** The Section Type list of a new section, and the two ways of adding one (Create New, Attach Existing). */
        typeSelect: () => screen.all((n) => n.tag === 'select')[0],
        modeRadios: () => screen.all((n) => n.tag === 'input' && n.props.name === 'sectionMode'),
        fileInputs,
        byTitle: (title: string) => screen.all((n) => n.tag === 'button' && n.props.title === title),
        /** Choose a file in the nth upload control, as the file picker does. */
        async choose(nth: number, file: any) {
            const input: any = fileInputs()[nth];
            input.files = [file];
            const done = input.props.onChange({ target: input });
            await flush();

            return done;
        },
    };
}

/**
 * A section as the page list holds it: ONE reactive object (PageSectionsView keeps its sections in a
 * ref), handed to every modal that is opened on it. Cancel does not reload the list, so whatever a
 * closed modal wrote into this object is what the next one is opened on.
 */
const listed = (saved: any) => vue.reactive(saved);

/** A plain copy of what an object holds now, to compare with later. */
const copyOf = (value: any) => JSON.parse(JSON.stringify(value));

/* -------------------------------------------------- Save waits for an upload */

test('while a PDF is uploading the section cannot be saved, and the modal says why; when the upload ends it can, with the address', async () => {
    const upload = deferred<any>();
    const modal = await mountModal(linkList({ label: 'Calendar' }), () => upload.promise);

    assert.equal(modal.save().disabled, false);
    assert.equal(modal.notes(), '');

    void modal.choose(0, pdf());
    await flush();
    assert.equal(modal.uploads.length, 1);

    // The button is off, and one line beside it says why.
    assert.equal(modal.save().disabled, true);
    assert.equal(modal.notes(), UPLOADING);

    // Not by a click, not by a tap the re-render missed, and not by Enter in a field, which submits
    // the form and asks no button.
    assert.equal(click(modal.save()), false);
    press(modal.save());
    submit(modal.form());
    await flush();
    assert.deepEqual(modal.saves, [], 'a save mid-upload would be a save without the address');
    assert.equal(modal.emitted.saved, 0);

    upload.resolve({ url: ADDRESS, name: 'Academic Calendar 2026', size: 480_000 });
    await flush();

    assert.equal(modal.save().disabled, false);
    assert.equal(modal.notes(), '');

    click(modal.save());
    await flush();
    assert.equal(modal.saves.length, 1);
    assert.deepEqual([modal.saves[0].pageId, modal.saves[0].sectionId], [3, 9]);
    assert.deepEqual(modal.saves[0].content.links, [
        { label: 'Calendar', url: ADDRESS, icon: 'bi-file-earmark-arrow-down', style: 'primary' },
    ]);
    assert.equal(modal.emitted.saved, 1);

    modal.screen.unmount();
});

test('when an upload fails the section can be saved again, as it was', async () => {
    const upload = deferred<any>();
    const modal = await mountModal(linkList({ label: 'Calendar', url: 'https://example.org/keep' }), () => upload.promise);

    void modal.choose(0, pdf());
    await flush();
    assert.equal(modal.save().disabled, true);

    upload.reject(new Error('This file is not a PDF. Save or print it as a PDF, then upload that.'));
    await flush();

    assert.equal(modal.save().disabled, false);
    assert.equal(modal.notes(), '');
    assert.deepEqual(modal.screen.all((n) => n.props.role === 'alert').map((n) => n.textContent), [
        'This file is not a PDF. Save or print it as a PDF, then upload that.',
    ]);

    click(modal.save());
    await flush();
    assert.equal(modal.saves.length, 1);
    assert.equal(modal.saves[0].content.links[0].url, 'https://example.org/keep');

    modal.screen.unmount();
});

test('Call to Action and Programs hold Save too: the count is the modal\'s, not one editor\'s', async () => {
    for (const saved of [
        section('cta', {
            heading: 'Read it', description: '', button_text: 'Open', button_link: '', button_style: 'primary',
            background_image_url: null, background_color: '#f8f9fa',
        }),
        section('programs', {
            heading: 'Our Programs', description: '', layout: 'cards', columns: 3, background_color: '#ffffff',
            programs: [{ name: 'Elementary', level: '', schedule: '', summary: '', highlights: [], image_url: null, link_url: '', link_text: '' }],
        }),
    ]) {
        const upload = deferred<any>();
        const modal = await mountModal(saved, () => upload.promise);

        void modal.choose(0, pdf());
        await flush();
        assert.equal(modal.save().disabled, true, saved.section_type);
        assert.equal(modal.notes(), UPLOADING, saved.section_type);
        submit(modal.form());
        await flush();
        assert.deepEqual(modal.saves, [], saved.section_type);

        upload.resolve({ url: ADDRESS, name: 'Academic Calendar 2026', size: 1 });
        await flush();
        assert.equal(modal.save().disabled, false, saved.section_type);
        click(modal.save());
        await flush();
        assert.ok(JSON.stringify(modal.saves[0].content).includes(ADDRESS), saved.section_type);

        modal.screen.unmount();
    }
});

test('Cancel and the close button ask before discarding an upload in flight, and close at once when there is none', async () => {
    // Nothing uploading: both close at once and ask nothing.
    const idle = await mountModal(linkList({ label: 'Calendar' }), async () => ({}));
    click(idle.cancel());
    click(idle.closeButton());
    await flush();
    assert.deepEqual(idle.asked, []);
    assert.equal(idle.emitted.close, 2);
    idle.screen.unmount();

    // Uploading, and the office chooses to keep editing: the modal stays.
    const kept = deferred<any>();
    const staying = await mountModal(linkList({ label: 'Calendar' }), () => kept.promise, false);
    void staying.choose(0, pdf());
    await flush();
    click(staying.cancel());
    click(staying.closeButton());
    await flush();
    assert.equal(staying.asked.length, 2);
    assert.equal(staying.asked[0].title, 'A PDF is still uploading');
    // The question says what closing would leave behind, and offers both ways out.
    assert.match(staying.asked[0].text, /stay online, linked from nowhere/);
    assert.equal(staying.asked[0].showCancelButton, true);
    assert.deepEqual([staying.asked[0].confirmButtonText, staying.asked[0].cancelButtonText], ['Close Anyway', 'Keep Editing']);
    assert.equal(staying.emitted.close, 0);
    staying.screen.unmount();

    // Uploading, and the office closes anyway.
    const dropped = deferred<any>();
    const leaving = await mountModal(linkList({ label: 'Calendar' }), () => dropped.promise, true);
    void leaving.choose(0, pdf());
    await flush();
    click(leaving.cancel());
    await flush();
    assert.equal(leaving.asked.length, 1);
    assert.equal(leaving.emitted.close, 1);
    leaving.screen.unmount();
});

/* ------------------------------------------- which files are saved, and what Save does */

test('the modal knows which documents the saved section links, and an upload since is not one of them', async () => {
    const modal = await mountModal(
        linkList({ label: 'Calendar', url: SAVED_ADDRESS }, { label: 'Schedule' }),
        async () => ({ url: ADDRESS, name: 'Academic Calendar 2026', size: 1 }),
    );
    const count = (sentence: string) => modal.screen.text().split(sentence).length - 1;

    assert.equal(count(SAVED), 1);
    assert.equal(count(NOT_SAVED), 0);

    // "What is saved" is the section the modal was opened on, which no editor can write into: an
    // upload made since is in the modal's own copy of the content only.
    await modal.choose(1, pdf());
    assert.equal(count(SAVED), 1, 'the saved document is still told how to take it offline');
    assert.equal(count(NOT_SAVED), 1, 'the new upload is not promised that');
    assert.ok(!modal.screen.text().includes('does the same to this one. This file is not in the saved section yet'));

    // After the rows move, each control is made anew and still says what is true of its file.
    click(modal.byTitle('Move Up')[1]);
    await flush();
    assert.equal(count(SAVED), 1);
    assert.equal(count(NOT_SAVED), 1);
    assert.ok(modal.screen.text().indexOf(NOT_SAVED) < modal.screen.text().indexOf(SAVED));

    modal.screen.unmount();
});

test('letting go of a saved document says, beside Save, that the file is taken offline when you save', async () => {
    const modal = await mountModal(
        linkList({ label: 'Calendar', url: SAVED_ADDRESS }, { label: 'Fees', url: 'https://example.org/fees' }),
        async () => ({ url: ADDRESS, name: 'Academic Calendar 2026', size: 1 }),
    );

    assert.equal(modal.notes(), '');

    // Cleared.
    type(modal.linkFields()[0], '');
    await flush();
    assert.equal(modal.notes(), LEAVING);

    // Put back (in another spelling of the same address): nothing is going anywhere.
    type(modal.linkFields()[0], 'http://another-host.example.test/storage/400/calendar-2025.pdf?v=2');
    await flush();
    assert.equal(modal.notes(), '');

    // Replaced by a new upload.
    await modal.choose(0, pdf());
    assert.equal(modal.notes(), LEAVING);
    // The control does not also call the replaced file "left online": the save deletes this one.
    assert.ok(!modal.screen.text().includes('The PDF this one replaced'));

    // Still said until the save, and the save goes ahead.
    click(modal.save());
    await flush();
    assert.equal(modal.saves.length, 1);
    assert.equal(modal.saves[0].content.links[0].url, ADDRESS);

    modal.screen.unmount();
});

test('removing the row that linked a saved document says so too, in each of the three editors', async () => {
    const programs = section('programs', {
        heading: 'Our Programs', description: '', layout: 'cards', columns: 3, background_color: '#ffffff',
        programs: [
            { name: 'Elementary', level: '', schedule: '', summary: '', highlights: [], image_url: null, link_url: SAVED_ADDRESS, link_text: 'Calendar' },
            { name: 'Middle School', level: '', schedule: '', summary: '', highlights: [], image_url: null, link_url: '', link_text: '' },
        ],
    });
    const cta = section('cta', {
        heading: 'Read it', description: '', button_text: 'Open', button_link: SAVED_ADDRESS, button_style: 'primary',
        background_image_url: null, background_color: '#f8f9fa',
    });
    const nothing = async () => ({});

    const buttons = await mountModal(linkList({ label: 'Calendar', url: SAVED_ADDRESS }, { label: 'Fees' }), nothing);
    click(buttons.byTitle('Remove Link')[0]);
    await flush();
    assert.equal(buttons.notes(), LEAVING);
    buttons.screen.unmount();

    const rows = await mountModal(programs, nothing);
    assert.equal(rows.notes(), '');
    click(rows.byTitle('Remove Program')[0]);
    await flush();
    assert.equal(rows.notes(), LEAVING);
    rows.screen.unmount();

    const button = await mountModal(cta, nothing);
    assert.equal(button.notes(), '');
    type(button.linkFields()[0], '');
    await flush();
    assert.equal(button.notes(), LEAVING);
    button.screen.unmount();
});

test('a file uploaded and let go before any save is not said to be taken offline: no save will delete it', async () => {
    const modal = await mountModal(linkList({ label: 'Calendar' }), async () => ({ url: ADDRESS, name: 'Academic Calendar 2026', size: 1 }));

    await modal.choose(0, pdf());
    type(modal.linkFields()[0], '');
    await flush();

    // Nothing saved linked it, so "taken offline when you save" would be false.
    assert.equal(modal.notes(), '');

    modal.screen.unmount();
});

/* ------------------------------------------- an edit that is abandoned with Cancel */

test('an upload that is abandoned with Cancel is not in the section the next time it is opened', async () => {
    const held = listed(linkList({ label: 'Calendar' }, { label: 'Fees', url: 'https://example.org/fees' }));
    const asSaved = copyOf(held);
    const upload = async () => ({ url: ADDRESS, name: 'Academic Calendar 2026', size: 1 });

    const first = await mountModal(held, upload, true);
    await first.choose(0, pdf());
    assert.equal(first.linkFields()[0].value, ADDRESS);
    click(first.cancel());
    await flush();
    assert.equal(first.emitted.close, 1);
    first.screen.unmount();

    // The page list's own object is as it was: the server never had that file in this section.
    assert.deepEqual(copyOf(held), asSaved, 'the abandoned upload was written into the section the page list holds');

    // Opened again, the modal shows what is saved, and calls nothing saved that is not.
    const second = await mountModal(held, upload);
    assert.equal(second.linkFields()[0].value, '');
    assert.ok(!second.screen.text().includes('Document: academic-calendar-2026.pdf'));
    assert.ok(!second.screen.text().includes(SAVED), 'a file no save ever linked was called saved');
    assert.equal(second.notes(), '');

    // Nothing is promised to be taken offline, and a save sends the saved content.
    type(second.linkFields()[0], '');
    await flush();
    assert.equal(second.notes(), '');
    click(second.save());
    await flush();
    assert.deepEqual(second.saves[0].content, asSaved.content);

    second.screen.unmount();
});

test('a saved address that is cleared and abandoned with Cancel is still there the next time, and the next save keeps it', async () => {
    const held = listed(linkList({ label: 'Calendar', url: SAVED_ADDRESS }, { label: 'Fees', url: 'https://example.org/fees' }));
    const asSaved = copyOf(held);
    const nothing = async () => ({});

    const first = await mountModal(held, nothing);
    type(first.linkFields()[0], '');
    await flush();
    assert.equal(first.notes(), LEAVING);
    click(first.cancel());
    await flush();
    assert.equal(first.emitted.close, 1);
    first.screen.unmount();

    assert.deepEqual(copyOf(held), asSaved, 'the abandoned clear was written into the section the page list holds');

    // Opened again: the address is there, and there is nothing to announce.
    const second = await mountModal(held, nothing);
    assert.equal(second.linkFields()[0].value, SAVED_ADDRESS);
    assert.equal(second.notes(), '');

    // Something else is changed and saved. The save must not carry the clear the office abandoned:
    // the server would delete a document the office believes it kept, with nothing said beside Save.
    type(second.labelFields()[1], 'Fees 2026');
    await flush();
    assert.equal(second.notes(), '');
    click(second.save());
    await flush();
    assert.equal(second.saves.length, 1);
    assert.equal(second.saves[0].content.links[0].url, SAVED_ADDRESS);
    assert.equal(second.saves[0].content.links[1].label, 'Fees 2026');

    second.screen.unmount();
});

test('with no document at all: a label that is edited and abandoned with Cancel is the saved label the next time', async () => {
    const held = listed(linkList({ label: 'Email Us', url: 'mailto:office@example.test' }));
    const asSaved = copyOf(held);
    const nothing = async () => ({});

    const first = await mountModal(held, nothing);
    type(first.labelFields()[0], 'Write to Us');
    await flush();
    click(first.closeButton());
    await flush();
    assert.deepEqual(first.asked, []);
    assert.equal(first.emitted.close, 1);
    first.screen.unmount();

    assert.deepEqual(copyOf(held), asSaved);

    const second = await mountModal(held, nothing);
    assert.equal(second.labelFields()[0].value, 'Email Us');
    click(second.save());
    await flush();
    assert.equal(second.saves[0].content.links[0].label, 'Email Us');

    second.screen.unmount();
});

/**
 * An editor that does what no editor may: it writes straight into the object it was handed, rows
 * and all. Whatever one editor or another copies for itself, the section the page list holds must
 * not be reachable from here.
 */
const scribbler = {
    props: ['modelValue'],
    setup(props: any) {
        const scribble = () => {
            props.modelValue.heading = 'Scribbled';
            props.modelValue.stats[0].label = 'Scribbled';
            props.modelValue.stats.push({ label: 'Added', value: '1', icon: '' });
        };

        return () => vue.h('button', { type: 'button', title: 'Scribble', onClick: scribble });
    },
};

const stats = () => ({ heading: 'In numbers', layout: 'horizontal', stats: [{ label: 'Students', value: '240', icon: '' }] });

test('no editor can write into the section the page list holds: the modal hands its editors a copy of its own', async () => {
    const held = listed(section('stats', stats()));
    const asSaved = copyOf(held);

    const modal = await mountModal(held, async () => ({}), { editors: () => ({ StatsSectionEditor: scribbler }) });
    click(modal.byTitle('Scribble')[0]);
    await flush();

    assert.deepEqual(copyOf(held), asSaved, 'an editor wrote into the section the page list holds');

    // The writes went somewhere: into the modal's own copy, which is what a save sends.
    click(modal.save());
    await flush();
    assert.deepEqual(modal.saves[0].content, {
        heading: 'Scribbled', layout: 'horizontal',
        stats: [{ label: 'Scribbled', value: '240', icon: '' }, { label: 'Added', value: '1', icon: '' }],
    });

    modal.screen.unmount();
});

test('a new section starts from a copy of its type\'s default content: what is typed into one is not the next one\'s default', async () => {
    // The types as the store holds them: reactive, and handed to every modal that is opened.
    const types = vue.reactive([
        { value: 'stats', label: 'Stats', description: 'Numbers', has_renderer: true, default_content: stats() },
    ]);
    const asServed = copyOf(types);

    const modal = await mountModal(undefined, async () => ({}), { sectionTypes: types, editors: () => ({ StatsSectionEditor: scribbler }) });
    assert.equal(select(modal.typeSelect(), 'stats'), true);
    await flush();
    click(modal.byTitle('Scribble')[0]);
    await flush();

    assert.deepEqual(copyOf(types), asServed, 'an editor wrote into the default content the store holds');

    click(modal.create());
    await flush();
    assert.equal(modal.saves[0].content.heading, 'Scribbled');

    modal.screen.unmount();
});

/* ------------------------------------------- the count is lowered once for each upload */

test('an upload that outlives its control and answers late does not lower the count for another that is still in flight', async () => {
    for (const late of ['answers', 'fails']) {
        const answers = [deferred<any>(), deferred<any>()];
        let sent = 0;
        let count: any = null;

        // Two real controls in one editor, the first of which can be taken away while its file is in
        // flight (as a control is when its editor goes), and the modal's own count as they are given it.
        const twoControls = ({ control }: { control: any }) => ({
            StatsSectionEditor: {
                setup() {
                    const first = vue.ref(true);
                    count = vue.inject('sectionDocumentUploads');

                    return () => vue.h('div', [
                        first.value ? vue.h(control, { key: 'first', value: '' }) : null,
                        vue.h(control, { key: 'second', value: '' }),
                        vue.h('button', { type: 'button', title: 'Take the first control away', onClick: () => { first.value = false; } }),
                    ]);
                },
            },
        });
        const modal = await mountModal(section('stats', stats()), () => answers[sent++].promise, { editors: twoControls });
        assert.equal(modal.fileInputs().length, 2);

        void modal.choose(0, pdf('first.pdf'));
        await flush();
        assert.equal(count.value, 1, late);
        assert.equal(modal.save().disabled, true, late);

        // The first control goes while its upload runs, and its count goes with it.
        click(modal.byTitle('Take the first control away')[0]);
        await flush();
        assert.equal(modal.fileInputs().length, 1);
        assert.equal(count.value, 0, late);
        assert.equal(modal.save().disabled, false, late);

        // Another upload starts, in the control that is left.
        void modal.choose(0, pdf('second.pdf'));
        await flush();
        assert.equal(modal.uploads.length, 2);
        assert.equal(count.value, 1, late);
        assert.equal(modal.save().disabled, true, late);

        // The first answers late. It was counted down when its control went; counted down a second
        // time it would open Save while the second file is still on its way.
        if (late === 'answers') {
            answers[0].resolve({ url: ADDRESS, name: 'first', size: 1 });
        } else {
            answers[0].reject(new Error('The PDF could not be uploaded. Check your connection and try again.'));
        }
        await flush();
        assert.equal(count.value, 1, `the first upload ${late} late, and the second is no longer counted`);
        assert.equal(modal.save().disabled, true, `the first upload ${late} late, and Save is on while the second is still uploading`);
        assert.equal(modal.notes(), UPLOADING, late);
        submit(modal.form());
        await flush();
        assert.deepEqual(modal.saves, [], late);

        answers[1].resolve({ url: SAVED_ADDRESS, name: 'second', size: 1 });
        await flush();
        assert.equal(count.value, 0, late);
        assert.equal(modal.save().disabled, false, late);
        assert.equal(modal.notes(), '', late);

        modal.screen.unmount();
    }
});

/* ------------------------------------------- a NEW section: its type and its mode wait too */

/** The two types a new section is offered here, with the default content the server gives each. */
const offeredTypes = () => [
    {
        value: 'link_list', label: 'Link Buttons', description: 'Buttons', has_renderer: true,
        default_content: { heading: '', description: '', links: [], layout: 'stack', background_color: '#ffffff' },
    },
    {
        value: 'cta', label: 'Call to Action', description: 'One button', has_renderer: true,
        default_content: {
            heading: '', description: '', button_text: 'Get Started', button_link: '', button_style: 'primary',
            background_image_url: null, background_color: '#2c5f2d',
        },
    },
];

/** A new Link Buttons section with one button, and a PDF on its way into it. */
async function newSectionMidUpload() {
    const upload = deferred<any>();
    const modal = await mountModal(undefined, () => upload.promise, { sectionTypes: offeredTypes() });

    assert.equal(select(modal.typeSelect(), 'link_list'), true);
    await flush();
    click(modal.screen.button('Add Link'));
    await flush();
    assert.equal(modal.fileInputs().length, 1);

    // Nothing is held before a file is sent.
    assert.equal(modal.typeSelect().disabled, false);
    assert.deepEqual(modal.modeRadios().map((radio) => radio.disabled), [false, false]);

    void modal.choose(0, pdf());
    await flush();
    assert.equal(modal.uploads.length, 1);

    return { modal, upload };
}

test('while a PDF uploads into a new section, its Section Type cannot be changed: the upload\'s answer still has its field', async () => {
    const { modal, upload } = await newSectionMidUpload();

    // Changing the type takes the editor away, and the control with it, exactly as Cancel does, but
    // asks nothing: the file would be stored and its address written nowhere. So the list is held,
    // as Save is, and the line beside Save already says why.
    assert.equal(modal.typeSelect().disabled, true);
    assert.equal(modal.notes(), UPLOADING);
    assert.equal(select(modal.typeSelect(), 'cta'), false);
    await flush();
    assert.equal(modal.fileInputs().length, 1, 'the control the upload was started from is gone');
    assert.equal(modal.linkFields().length, 1);

    upload.resolve({ url: ADDRESS, name: 'Academic Calendar 2026', size: 1 });
    await flush();

    // The answer landed in the button it was started from, and the list is free again.
    assert.equal(modal.linkFields()[0].value, ADDRESS);
    assert.equal(modal.typeSelect().disabled, false);
    assert.equal(modal.notes(), '');

    click(modal.create());
    await flush();
    assert.equal(modal.saves.length, 1);
    assert.equal(modal.saves[0].content.links[0].url, ADDRESS);

    modal.screen.unmount();
});

test('while a PDF uploads into a new section, Attach Existing cannot be chosen either', async () => {
    const { modal, upload } = await newSectionMidUpload();
    const [createNew, attachExisting] = modal.modeRadios();
    assert.deepEqual([createNew.props.value, attachExisting.props.value], ['create', 'attach']);

    // Attach Existing takes the whole form away. Both choices are held, as the type and Save are.
    assert.deepEqual([createNew.disabled, attachExisting.disabled], [true, true]);
    assert.equal(check(attachExisting), false);
    await flush();
    assert.equal(modal.fileInputs().length, 1, 'the form, and the control the upload was started from, are gone');
    assert.ok(modal.form(), 'the form is gone');

    upload.reject(new Error('The PDF could not be uploaded. Check your connection and try again.'));
    await flush();

    // A failed upload frees them as an answered one does.
    assert.deepEqual(modal.modeRadios().map((radio) => radio.disabled), [false, false]);
    assert.equal(modal.typeSelect().disabled, false);
    assert.equal(check(modal.modeRadios()[1]), true);
    await flush();
    assert.equal(modal.form(), undefined, 'Attach Existing shows no form');

    modal.screen.unmount();
});
