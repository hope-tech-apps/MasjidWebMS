import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { click, flush, mountSfc } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');

const page = { id: 7, title: 'School', slug: 'school', order: 1, is_active: true };

async function mountList() {
    const said: Array<Record<string, any>> = [];
    const modal = {
        props: ['page'],
        emits: ['saved', 'close'],
        setup(props: any, { emit }: any) {
            return () => vue.h('button', { onClick: () => emit('saved') }, props.page ? 'Save the edit' : 'Save the new page');
        },
    };
    const draggable = {
        props: ['modelValue'],
        setup(props: any, { slots }: any) {
            return () => (props.modelValue ?? []).map((element: any, index: number) => slots.item?.({ element, index }));
        },
    };
    const store = {
        pagesPaginated: { data: [page], total: 1, current_page: 1, per_page: 15 },
        fetchMasjidPagesPaginated: async () => {},
    };

    const screen = await mountSfc('views/dashboard/pages/PagesView.vue', {}, {
        '@/components/PageDataContainer.vue': { default: {
            setup(_: any, { slots, emit }: any) {
                return () => vue.h('div', [vue.h('button', { onClick: () => emit('headerButtonClick') }, 'Add Page'), slots.default?.()]);
            },
        } },
        '@/components/modals/PageFormModal.vue': { default: modal },
        '@/core/types/data/masjid-related/Page': {},
        '@/core/types/elements/Pagination': {},
        '@/stores/masjid/pagesStore': { usePagesStore: () => store },
        'vue-router': { useRouter: () => ({ push() {} }) },
        sweetalert2: { default: { fire: (options: Record<string, any>) => { said.push(options); return Promise.resolve({}); } } },
        vuedraggable: { default: draggable },
    });
    await flush();

    return { screen, said };
}

test('saving an edit of a page says it was updated, not created', async () => {
    const { screen, said } = await mountList();

    const edit = screen.all((n) => n.tag === 'button' && n.props.title === 'Edit');
    assert.equal(edit.length, 1, 'the one page has one Edit button');
    click(edit[0]);
    await flush();

    click(screen.button('Save the edit'));
    await flush();

    assert.equal(said.length, 1);
    assert.equal(said[0].icon, 'success');
    assert.equal(said[0].text, 'Page updated successfully');
    screen.unmount();
});

test('saving a new page still says it was created', async () => {
    const { screen, said } = await mountList();

    click(screen.button('Add Page'));
    await flush();

    click(screen.button('Save the new page'));
    await flush();

    assert.equal(said.length, 1);
    assert.equal(said[0].text, 'Page created successfully');
    screen.unmount();
});
