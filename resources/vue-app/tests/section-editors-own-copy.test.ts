/**
 * Every section editor that holds ROWS (buttons, slides, staff, fees...) edits rows of its OWN, MOUNTED:
 * each editor compiled from its .vue file and sat in tests/support/ModelHost.vue, which is to it what
 * SectionFormModal is.
 *
 * Why it matters. An editor is handed the section's content and binds its fields straight onto its
 * rows (`v-model="link.url"`). If those rows are the very objects it was handed, typing in a field
 * writes into the caller's content at once, whether or not anything is ever saved. That is how an
 * edit abandoned with Cancel stayed in the section the page list holds, and how a document the office
 * had kept came to be deleted by the next save (section-form-modal-documents.test.ts tells that story
 * through the modal, which now also hands every editor a copy of its own). Copying the list
 * (`[...value.links]`) is not enough: the rows in the copy are the same rows.
 *
 * The other editors bind only top-level fields of an object they build themselves, and hold no rows.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as documentFile from '../core/helpers/sectionDocumentFile.ts';
import { click, compileSfc, flush, mountSfc, type } from './support/mountSfc.ts';

/** What any of these editors imports, stood in for: none of it is what is being tested. */
const MODULES = {
    '@/core/types/data/masjid-related/PageSection': {},
    '@/core/types/data/masjid-related/Page': {},
    '@/core/types/elements/ImageInput': {},
    '@/components/form/ImageDraggableInput.vue': { default: { render: () => null } },
    '@/components/form/SectionDocumentUpload.vue': { default: { render: () => null } },
    '@/core/helpers/sectionDocumentFile': documentFile,
    '@/composables/useSectionImages': {},
    '@/stores/masjid/pagesStore': { usePagesStore: () => ({ fetchMasjidPagesPaginated: async () => {}, pagesPaginated: null }) },
};

/** One editor: its file, saved content with a row in every list it has, and the names of those lists. */
const EDITORS: Array<{ name: string; lists: string[]; content: Record<string, any> }> = [
    {
        name: 'LinkListSectionEditor',
        lists: ['links'],
        content: {
            heading: 'Downloads', description: '', layout: 'stack', background_color: '#ffffff',
            links: [{ label: 'Calendar', url: 'https://example.org/calendar', icon: '', style: 'primary' }],
        },
    },
    {
        name: 'ProgramsSectionEditor',
        lists: ['programs'],
        content: {
            heading: 'Our Programs', description: '', layout: 'cards', columns: 3, background_color: '#ffffff',
            programs: [{
                name: 'Elementary', level: 'K-5', schedule: 'Weekdays', summary: 'A summary', highlights: ['Reading'],
                image_url: null, link_url: '', link_text: '',
            }],
        },
    },
    {
        name: 'AdmissionsTuitionSectionEditor',
        lists: ['tiers', 'fees', 'payment_plans', 'steps'],
        content: {
            heading: 'Tuition', description: '', school_year: '2026-27', disclaimer: '', button_text: '',
            button_page_id: null, button_link: null, background_color: '#ffffff',
            tiers: [{ name: 'Full Day', badge: '', amount: '$500', period: 'a month', note: '', includes: ['Lunch'] }],
            fees: [{ label: 'Registration', amount: '$50', note: '' }],
            payment_plans: [{ label: 'Monthly', detail: 'Ten payments' }],
            steps: [{ title: 'Apply', description: 'Fill in the form' }],
        },
    },
    {
        name: 'CarouselSectionEditor',
        lists: ['slides'],
        content: {
            autoplay: true, interval_ms: 6000, show_arrows: true, show_dots: true, height: 'medium',
            slides: [{ image_url: '', title: 'Welcome', caption: 'A caption', link_url: '', link_text: '' }],
        },
    },
    {
        name: 'GridCardsSectionEditor',
        lists: ['items'],
        content: { items_per_row: 3, items: [{ title: 'One', text: 'First card', image_url: null }] },
    },
    {
        name: 'ImpactStatsSectionEditor',
        lists: ['stats'],
        content: {
            heading: 'Our Impact', description: '', period: '2026', layout: 'row', columns: 3, background_color: '#ffffff',
            stats: [{ value: '240', label: 'Families', description: 'Served this year' }],
        },
    },
    {
        name: 'MissionVisionSectionEditor',
        lists: ['items'],
        content: {
            heading: 'Who We Are', layout: 'side_by_side',
            items: [{ type: 'mission', title: 'Mission', content: 'To serve', icon_url: null }],
        },
    },
    {
        name: 'ProvidersDirectorySectionEditor',
        lists: ['providers'],
        content: {
            heading: 'Our Providers', description: '', layout: 'grid', columns: 3, background_color: '#ffffff',
            providers: [{ name: 'A. Provider', credential: 'MD', specialty: 'Family', department: 'Clinic', photo_url: null }],
        },
    },
    {
        name: 'ServicesEligibilitySectionEditor',
        lists: ['services'],
        content: {
            heading: 'Services', description: '', layout: 'cards', columns: 3, button_text: '', button_page_id: null,
            button_link: null, background_color: '#ffffff',
            services: [{ name: 'Food Pantry', description: 'Weekly', image_url: null }],
            eligibility: {
                heading: 'Who Can Come', intro: '', criteria: ['Lives nearby'], note: '',
                highlight: { badge: '', title: 'Free', subtitle: '', body: '' },
            },
        },
    },
    {
        name: 'StaffDirectorySectionEditor',
        lists: ['members'],
        content: {
            heading: 'Our Staff', description: '', layout: 'grid', columns: 3, show_contact: true, background_color: '#ffffff',
            members: [{
                name: 'A. Teacher', role: 'Teacher', department: 'Elementary', credentials: '', bio: 'A bio',
                email: 'teacher@example.test', phone: '', photo_url: null,
            }],
        },
    },
    {
        name: 'StatsSectionEditor',
        lists: ['stats'],
        content: { heading: 'In Numbers', layout: 'horizontal', stats: [{ label: 'Students', value: '240', icon: '' }] },
    },
];

const copyOf = (value: any) => JSON.parse(JSON.stringify(value));

/** Every field a person types words into. */
const typed = (screen: any) => screen.all((n: any) => n.tag === 'textarea' || (n.tag === 'input' && ['text', 'email', 'tel', 'url'].includes(n.type)));

const addButtons = (screen: any) => screen.all((n: any) => n.tag === 'button' && /^Add\b/.test(n.textContent));

for (const { name, lists, content } of EDITORS) {
    /**
     * Mount the editor on content the caller holds. ModelHost keeps it in a ref, as the modal keeps
     * its form, so a write through anything the editor shares with it lands in `handed` itself.
     */
    const mount = async (editor: any) => {
        const handed = copyOf(content);
        const sent: any[] = [];
        let give: (value: any) => void = () => {};
        const screen = await mountSfc('tests/support/ModelHost.vue', {
            editor, initial: handed, onChange: (value: any) => sent.push(value), handOver: (hand: (value: any) => void) => { give = hand; },
        }, {});
        await flush();

        return { handed, sent, screen, give: (value: any) => give(value) };
    };

    test(`${name}: the first thing typed, or the first row added, is not written into the content the editor was opened on`, async () => {
        const editor = await compileSfc(`components/sections/editors/${name}.vue`, MODULES);

        // An editor's first edit is the only one made on the rows it built when it was opened: after
        // it, the editor is handed its own content back and builds them again. So each field, and
        // each Add, gets an editor that has just been opened.
        const fields = typed((await mount(editor)).screen).length;
        assert.ok(fields > lists.length, 'the editor shows fewer fields than it has lists');
        for (let at = 0; at < fields; at++) {
            const fresh = await mount(editor);
            type(typed(fresh.screen)[at], 'Changed');
            await flush();
            assert.deepEqual(fresh.handed, content, `typing in field ${at + 1} wrote into the content the editor was opened on`);
            fresh.screen.unmount();
        }

        const adds = addButtons((await mount(editor)).screen).length;
        assert.ok(adds >= lists.length, 'the editor has a list with no Add button');
        for (let at = 0; at < adds; at++) {
            const fresh = await mount(editor);
            click(addButtons(fresh.screen)[at]);
            await flush();
            assert.deepEqual(fresh.handed, content, `Add button ${at + 1} wrote into the content the editor was opened on`);
            fresh.screen.unmount();
        }
    });

    test(`${name}: content it is handed later is not written into either, whatever is typed or added`, async () => {
        const editor = await compileSfc(`components/sections/editors/${name}.vue`, MODULES);
        const { screen, sent, give } = await mount(editor);

        // Other content, handed in from outside while the editor is open: the caller's again.
        const later = copyOf(content);
        give(later);
        await flush();

        for (let at = 0; at < addButtons(screen).length; at++) {
            click(addButtons(screen)[at]);
            await flush();
        }
        // Something different into every field, one after another. Each edit sends the content up,
        // the editor is handed it back, and it builds every row again.
        const said: string[] = [];
        for (let at = 0; at < typed(screen).length; at++) {
            said.push(`Typed ${at + 1}.`);
            type(typed(screen)[at], said[at]);
            await flush();
        }
        assert.deepEqual(later, content, 'an edit wrote into content the editor was handed while it was open');

        // WHAT WAS TYPED IS STILL THERE after all those rebuilds: in each field on the screen, and
        // in the content the editor sends up. A row rebuilt from the wrong copy would have lost it.
        const fields = typed(screen);
        assert.equal(fields.length, said.length, 'typing changed how many fields there are');
        said.forEach((value, at) => {
            assert.equal(fields[at].value, value, `field ${at + 1} no longer shows what was typed into it`);
        });

        // (This harness runs a field's own `@input` ahead of its v-model, the other way round from a
        // browser, so each content sent up holds everything typed BEFORE that edit. One more edit,
        // of the first field with the text it already has, brings the last one up too.)
        type(fields[0], said[0]);
        await flush();
        const now = sent[sent.length - 1];
        const sentUp = new Set<string>();
        const collect = (value: unknown): void => {
            if (typeof value === 'string') {
                sentUp.add(value);
            } else if (value && typeof value === 'object') {
                Object.values(value).forEach(collect);
            }
        };
        collect(now);
        said.forEach((value, at) => {
            assert.ok(sentUp.has(value), `what was typed into field ${at + 1} is not in the content the editor sent up`);
        });

        // And the test did reach every list: what the editor sent up has a row added to each, and
        // each first row changed.
        for (const list of lists) {
            assert.notDeepEqual(now[list][0], content[list][0], `nothing was typed into ${list}`);
            assert.ok(now[list].length > content[list].length, `no row was added to ${list}`);
        }

        screen.unmount();
    });
}
