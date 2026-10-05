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
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import * as documentFile from '../core/helpers/sectionDocumentFile.ts';
import * as shopSection from '../core/helpers/shopSection.ts';
import { click, compileSfc, deferred, flush, loadTs, mountSfc, Node, press, submit, type } from './support/mountSfc.ts';

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

/**
 * Mount the modal on a saved section. `upload` answers each file the control sends; `closeAnyway` is
 * what the office answers when the modal asks before closing.
 */
async function mountModal(saved: any, upload: (file: any) => Promise<any>, closeAnyway = false) {
    const saves: Array<{ pageId: number; sectionId: number; content: any }> = [];
    const uploads: any[] = [];
    const store = {
        sectionTypes: [],
        sectionsLibrary: [],
        fetchSectionTypes: async () => {},
        fetchSectionsLibrary: async () => {},
        fetchPageSections: async () => [],
        uploadPageDocument: (file: any) => { uploads.push(file); return upload(file); },
        updateSectionWithImages: async (pageId: number, sectionId: number, body: FormData) => {
            saves.push({ pageId, sectionId, content: JSON.parse(String(body.get('content'))) });
        },
    };

    const control = await compileSfc('components/form/SectionDocumentUpload.vue', {
        '@/stores/masjid/pagesStore': { usePagesStore: () => store },
        '@/core/helpers/sectionDocumentFile': documentFile,
    });

    // Every editor the modal imports is an empty stand-in, except the three that carry the control.
    const source = readFileSync(new URL(`../${MODAL}`, import.meta.url), 'utf8');
    const editors: Record<string, any> = {};
    for (const [, spec, name] of source.matchAll(/from '(@\/components\/sections\/editors\/(\w+)\.vue)'/g)) {
        editors[spec] = {
            default: WITH_UPLOAD.includes(name)
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

    return {
        screen,
        saves,
        uploads,
        asked,
        emitted,
        save: () => screen.button('Update Section'),
        cancel: () => screen.button('Cancel'),
        closeButton: () => screen.all((n) => n.tag === 'button' && String(n.props.class ?? '').includes('btn-close'))[0],
        form: () => screen.all((n) => n.tag === 'form')[0],
        /** The footer's notes, beside Save. */
        notes: () => screen.all((n) => String(n.props.class ?? '').includes('section-form-notes'))[0].textContent,
        linkFields: () => screen.all((n) => n.tag === 'input' && /^https:\/\/example\.com/.test(String(n.props.placeholder ?? ''))),
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

    // The editors write into objects they share with the section the modal was opened on, so "what
    // is saved" has to be what the modal read when it opened, not what that object holds now.
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
