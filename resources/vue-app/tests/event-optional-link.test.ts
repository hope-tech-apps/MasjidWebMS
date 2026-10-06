import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { flush, mountSfc, submit, type } from './support/mountSfc.ts';

const require = createRequire(import.meta.url);
const vue = require('vue');
const yup = require('yup');

// Keep the screen's real Yup schema and v-model values. Only Vee's rendering and
// the API are stood in for: a refused schema must never reach the request.
async function mountEvent(edit: boolean, link: string | null = null) {
    const values: Record<string, any> = {};
    const errors: string[] = [];
    const sent: Array<{ method: string; body: FormData | URLSearchParams }> = [];
    const slotOnly = { setup(_: any, { slots }: any) { return () => vue.h('div', slots.default?.()); } };
    const Form = {
        props: ['validationSchema'], emits: ['submit'],
        setup(props: any, { slots, emit }: any) {
            return () => vue.h('form', { onSubmit: async (event: any) => {
                event.preventDefault();
                try { await props.validationSchema.validate(values); }
                catch (error: any) { errors.push(error.message); return; }
                emit('submit');
            } }, slots.default?.());
        },
    };
    const Field = {
        props: ['name', 'modelValue', 'type', 'as'], emits: ['update:modelValue'],
        setup(props: any, { emit }: any) {
            vue.watchEffect(() => { values[props.name] = props.modelValue; });
            return () => vue.h(props.as || 'input', { name: props.name, type: props.type, value: props.modelValue,
                onInput: (event: any) => emit('update:modelValue', event.target.value) });
        },
    };
    const api = (method: string) => async (_url: string, body: FormData | URLSearchParams) => {
        sent.push({ method, body });
        return { data: { status: 'success' } };
    };
    const screen = await mountSfc('views/dashboard/events/EventFormView.vue', {}, {
        '@/assets/ts/swalMethods': { getMessageFromObj: () => '' },
        '@/components/form/ColumnInputContainer.vue': { default: slotOnly },
        '@/components/form/LoadingButton.vue': { default: slotOnly },
        '@/core/plugins/SweetAlerts2': { QSwal: { fire: async () => ({ isConfirmed: true }) }, MSwal: { fire: async () => ({}) } },
        '@/core/services/ApiService': { default: { post: api('post'), put: api('put') } },
        '@/core/types/config/AxiosCustom': {}, '@/core/types/config/BackendApiRoutes': {},
        '@/core/types/data/masjid-related/Event': {}, axios: {}, sweetalert2: {},
        '@/stores/masjid/eventsStore': { useEventsStore: () => ({
            fetchEvent: async (_id: string, target: any) => { target.value = {
                title: 'Circle', details: 'Details', place: 'Hall', link,
                start: '2099-01-05 19:00:00', end: '2099-01-05 20:00:00',
            }; }, fetchMasjidEventsPaginated: async () => {},
        }) },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 } }) },
        'vue-router': { useRoute: () => ({ params: edit ? { event_id: '7' } : {} }), useRouter: () => ({ push() {} }) },
        'vee-validate': { Form, Field }, yup,
    });
    await flush();
    return { screen, sent, errors };
}

for (const link of [null, '', 'https://example.org/event']) {
    test(`editing an event with stored Link ${JSON.stringify(link)} validates and sends the address`, async () => {
        const { screen, sent, errors } = await mountEvent(true, link);
        submit(screen.all((n) => n.tag === 'form')[0]);
        await flush();
        assert.deepEqual(errors, []);
        assert.equal(sent.length, 1);
        assert.equal(sent[0].method, 'put');
        assert.equal(sent[0].body.get('link'), link ?? '');
        screen.unmount();
    });
}

test('creating an event still allows an empty Link', async () => {
    const { screen, sent, errors } = await mountEvent(false);
    const fields = { title_input: 'Circle', details_input: 'Details', place_input: 'Hall',
        start_date_input: '2099-01-05', end_date_input: '2099-01-05', start_time_input: '19:00', end_time_input: '20:00' };
    for (const [name, value] of Object.entries(fields)) {
        type(screen.all((n) => ['input', 'textarea'].includes(n.tag) && n.props.name === name)[0], value);
        await flush();
    }
    await flush();
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();
    assert.deepEqual(errors, []);
    assert.equal(sent.length, 1);
    assert.equal(sent[0].method, 'post');
    assert.equal(sent[0].body.get('link'), '');
    screen.unmount();
});
