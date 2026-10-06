import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { compileSfc, flush, mountSfc } from './support/mountSfc.ts';
import * as properties from '../core/constants/allowedImageProperties.ts';

for (const [kind, types] of [
    ['photo', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']],
    ['icon', ['image/png', 'image/webp']],
] as const) {
    test(`${kind} upload note and picker advertise only the server's accepted types`, async () => {
        const screen = await mountSfc('components/form/ImageDraggableInput.vue', { label: 'Upload', type: kind }, {
            '@/core/constants/allowedImageProperties': properties,
            '@/core/types/elements/ImageInput': {},
        });
        assert.deepEqual(screen.all((n) => n.tag === 'input' && n.props.type === 'file')[0].props.accept.split(','), types);
        assert.ok(screen.text().includes(`Accepted types (${types.map((t) => t.split('/')[1]).join(', ')},`));
        screen.unmount();
    });
}

test('an updated service icon advertises its existing GIF allowance', async () => {
    const screen = await mountSfc('components/form/ImageDraggableInput.vue', {
        label: 'Service Icon', type: 'icon', acceptedTypes: ['image/png', 'image/gif', 'image/webp'],
    }, {
        '@/core/constants/allowedImageProperties': properties,
        '@/core/types/elements/ImageInput': {},
    });
    assert.equal(screen.all((n) => n.tag === 'input' && n.props.type === 'file')[0].props.accept, 'image/png,image/gif,image/webp');
    assert.ok(screen.text().includes('Accepted types (png, gif, webp,'));
    screen.unmount();
});

for (const edit of [false, true]) {
    test(`the ${edit ? 'edit' : 'create'} service screen passes its field's formats to the real upload box`, async () => {
        const require = createRequire(import.meta.url);
        const vue = require('vue');
        const slotOnly = { setup(_: any, { slots }: any) { return () => vue.h('div', slots.default?.()); } };
        const input = await compileSfc('components/form/ImageDraggableInput.vue', {
            '@/core/constants/allowedImageProperties': properties, '@/core/types/elements/ImageInput': {},
        });
        const screen = await mountSfc('views/dashboard/services/ServiceFormView.vue', {}, {
            '@/assets/ts/swalMethods': {},
            '@/components/form/ColumnInputContainer.vue': { default: slotOnly },
            '@/components/form/ImageDraggableInput.vue': { default: input },
            '@/components/form/LoadingButton.vue': { default: slotOnly },
            '@/core/plugins/SweetAlerts2': {}, '@/core/services/ApiService': {},
            '@/core/types/config/AxiosCustom': {}, '@/core/types/config/BackendApiRoutes': {},
            '@/core/types/data/masjid-related/Service': {}, '@/core/types/elements/ImageInput': {},
            '@/stores/masjid/servicesStore': { useServicesStore: () => ({ fetchService: async () => {} }) },
            '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 } }) },
            axios: {}, sweetalert2: {}, yup: require('yup'),
            'vue-router': { useRoute: () => ({ params: edit ? { service_id: '7' } : {} }), useRouter: () => ({}) },
            'vee-validate': { Form: slotOnly, Field: { render: () => null }, ErrorMessage: { render: () => null },
                useForm: () => ({}) },
        });
        await flush();
        const files = screen.all((n) => n.tag === 'input' && n.props.type === 'file');
        assert.equal(files.length, 2);
        assert.equal(files[0].props.accept, edit ? 'image/png,image/gif,image/webp' : 'image/png,image/webp');
        assert.equal(files[1].props.accept, 'image/jpeg,image/png,image/gif,image/webp');
        screen.unmount();
    });
}
