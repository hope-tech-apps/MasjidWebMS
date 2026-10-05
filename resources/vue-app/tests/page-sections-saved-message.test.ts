/**
 * The page tool's list of sections, MOUNTED: what it says after a section is saved.
 *
 * Saving an EDIT used to be announced as "Section created successfully". The words were chosen
 * after the modal was closed, and closing forgets which section was being edited, so every save
 * read as a new section. An office that had just replaced a PDF on a page was told it had made a
 * second section.
 *
 * The view is compiled from its .vue file; the modal is a stand-in that only says "saved"
 * (tests/support/mountSfc.ts says how, with no DOM).
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { click, flush, mountSfc } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');

const section = { id: 7, section_type: 'link_list', title: 'Downloads', order: 1, is_active: true, uses_external_data: false, content: {} };

async function mountList() {
    const said: Array<Record<string, any>> = [];
    const modal = {
        props: ['section', 'pageId', 'previewPage'],
        emits: ['saved', 'close'],
        setup(props: any, { emit }: any) {
            return () => vue.h('button', { onClick: () => emit('saved') }, props.section ? 'Save the edit' : 'Save the new section');
        },
    };
    const draggable = {
        props: ['modelValue'],
        setup(props: any, { slots }: any) {
            return () => (props.modelValue ?? []).map((element: any, index: number) => slots.item?.({ element, index }));
        },
    };
    const store = {
        currentPage: { id: 3, slug: 'school', title: 'School', is_active: true },
        fetchPage: async () => {},
        fetchPageSections: async () => [{ ...section }],
        updateSection: async () => {},
    };

    const screen = await mountSfc('views/dashboard/pages/PageSectionsView.vue', {}, {
        '@/components/modals/SectionFormModal.vue': { default: modal },
        '@/components/preview/LivePreviewPane.vue': { default: { render: () => null } },
        '@/composables/useLivePreview': { pagePath: () => '/school', usePreviewAvailability: () => vue.ref(false) },
        '@/core/types/data/masjid-related/PageSection': {},
        '@/stores/masjid/pagesStore': { usePagesStore: () => store },
        'vue-router': { useRouter: () => ({ back() {}, push() {} }), useRoute: () => ({ params: { pageId: '3' } }) },
        sweetalert2: { default: { fire: (options: Record<string, any>) => { said.push(options); return Promise.resolve({}); } } },
        vuedraggable: { default: draggable },
    });
    await flush();

    return { screen, said };
}

test('saving an edit of a section says it was updated, not created', async () => {
    const { screen, said } = await mountList();

    const edit = screen.all((n) => n.tag === 'button' && n.props.title === 'Edit');
    assert.equal(edit.length, 1, 'the one section has one Edit button');
    click(edit[0]);
    await flush();

    click(screen.button('Save the edit'));
    await flush();

    assert.equal(said.length, 1);
    assert.equal(said[0].icon, 'success');
    assert.equal(said[0].text, 'Section updated successfully');
    screen.unmount();
});

test('saving a new section still says it was created', async () => {
    const { screen, said } = await mountList();

    click(screen.button('Add Section'));
    await flush();

    click(screen.button('Save the new section'));
    await flush();

    assert.equal(said.length, 1);
    assert.equal(said[0].text, 'Section created successfully');
    screen.unmount();
});
