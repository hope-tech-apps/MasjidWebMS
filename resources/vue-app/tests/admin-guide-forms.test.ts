import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import * as themeTokens from '../core/helpers/themeTokens.ts';
import { flush, loadTs, mountSfc, submit } from './support/mountSfc.ts';

const require = createRequire(import.meta.url);
const vue = require('vue');
const yup = require('yup');

// Keep the screens' schemas, hints, submit handlers and the real alert mixin defaults.
// Transport and widgets are stand-ins; this renderer has no browser layout.
async function mountForm(file: string, organization = 'Masjid', confirmed = true, outcome = 'success', settingsLoad = 'answers') {
    const alerts: any[] = [];
    const sent: any[] = [];
    let schema: any;
    const swal = await loadTs('core/plugins/SweetAlerts2.ts', { sweetalert2: { default: {
        mixin: (defaults: any) => ({ fire: async (...args: any[]) => {
            const options = typeof args[0] === 'object' ? args[0] : { title: args[0], text: args[1], icon: args[2] };
            alerts.push({ ...defaults, ...options });
            return { isConfirmed: confirmed };
        } }),
    } } });
    const slotOnly = { setup(_: any, { slots }: any) { return () => vue.h('div', slots.default?.()); } };
    const Form = { props: ['validationSchema'], emits: ['submit'], setup(props: any, { slots, emit }: any) {
        schema = props.validationSchema;
        return () => vue.h('form', { onSubmit: (e: any) => { e.preventDefault(); emit('submit'); } }, slots.default?.());
    } };
    const Field = { props: ['name', 'modelValue', 'type', 'as'], emits: ['update:modelValue'],
        setup(props: any, { slots, emit, attrs }: any) { return () => slots.default
            ? slots.default({ field: {}, value: props.modelValue, handleChange: () => {} })
            : vue.h(props.as || 'input', { ...attrs, name: props.name, value: props.modelValue,
                onInput: (e: any) => emit('update:modelValue', e.target.value) }); } };
    const data = { name: 'Sample organisation', email: 'office@example.org', phone: '+15555555555',
        social_media_links: [], timezone: 'UTC', latitude: 40, longitude: -75, website_link: '',
        copyright_text: '', app_store_link: '', google_play_link: '', google_maps_key: '',
        privacy_policy_url: 'https://www.example.org/privacy',
        primary_color: '#01B151', secondary_color: '#0B7A3B', accent_color: '#F2B705', background_color: '#FFFFFF' };
    const screen = await mountSfc(`views/dashboard/${file}.vue`, {}, {
        '@/components/form/ColumnInputContainer.vue': { default: slotOnly },
        '@/components/form/LoadingButton.vue': { default: { setup(_: any, { slots }: any) { return () => vue.h('button', slots.default?.()); } } },
        '@/components/form/ImageDraggableInput.vue': { default: { render: () => null } },
        '@/components/form/SearchableSelect.vue': { default: { render: () => null } },
        '@/components/preview/LivePreviewPane.vue': { default: { render: () => null } },
        '@/composables/useLivePreview': { usePreviewAvailability: () => vue.ref(false) },
        '@/core/helpers/themeTokens': themeTokens,
        '@/core/plugins/SweetAlerts2': swal,
        '@/core/services/ApiService': { default: {
            get: async (url: string) => {
                if (settingsLoad === 'fails' && !url.endsWith('/timezones')) throw new Error('offline');
                return { data: { status: 'success', data: url.endsWith('/timezones') ? ['UTC'] : data } };
            },
            post: async (url: string, body: any) => { sent.push({ url, body }); return { data: { status: outcome, data } }; },
        } },
        '@/stores/masjidStore': { useMasjidStore: () => ({ masjid: { id: 1 }, term: () => organization, fetchMasjid: async () => {} }) },
        '@/stores/authStore': { useAuthStore: () => ({ user: { type: 'MasjidAdmin' } }) },
        '@/stores/super/masjidsStore': { useMasjidsStore: () => ({ masjids: [] }) },
        '@/assets/ts/swalMethods': { getMessageFromObj: () => 'Request failed' },
        '@/assets/ts/handleVueTelInput': { applyCountryDialCode: () => '' },
        '@/core/types/elements/VueTelInput': {}, '@/core/types/data/custom/MasjidDetails': {},
        '@/core/types/elements/ImageInput': {}, '@/core/types/config/AxiosCustom': {},
        axios: {}, sweetalert2: {}, 'vee-validate': { Form, Field, ErrorMessage: { render: () => null }, useForm: () => ({ setFieldValue() {} }) }, yup,
    });
    await flush();
    return { screen, schema, alerts, sent };
}

for (const [field, label] of [['logo_image', 'Logo'], ['whatsapp_number', 'WhatsApp'], ['masjid_email', 'Email Address'], ['masjid_phone', 'Phone Number']]) {
    test(`Basic Info calls required ${field} by its visible label`, async () => {
        const f = await mountForm('MosqueDetailsView');
        await assert.rejects(f.schema.validateAt(field, { [field]: '' }), { message: `${label} is a required field` });
        f.screen.unmount();
    });
}

test('Basic Info link hints validate and URL errors name the visible fields', async () => {
    const f = await mountForm('MosqueDetailsView');
    for (const [field, label] of [['facebook_link', 'Facebook'], ['youtube_link', 'Youtube'], ['instagram_link', 'Instagram']]) {
        const input = f.screen.all((n) => n.tag === 'input' && n.props.name === field)[0];
        assert.ok(input.props.placeholder.startsWith('https://'));
        await f.schema.validateAt(field, { [field]: input.props.placeholder });
        await assert.rejects(f.schema.validateAt(field, { [field]: 'invalid' }), { message: `${label} must be a valid URL` });
    }
    assert.equal(f.screen.all((n) => n.tag === 'input' && n.props.name === 'masjid_website_link')[0].props.placeholder, 'https://example.org');
    f.screen.unmount();
});

for (const org of ['Masjid', 'School', 'Organization']) {
    test(`Basic Info confirms an update to the ${org.toLowerCase()} details`, async () => {
        const f = await mountForm('MosqueDetailsView', org, false);
        submit(f.screen.all((n) => n.tag === 'form')[0]); await flush();
        assert.equal(f.alerts[0].text, `Update the ${org.toLowerCase()} details?`);
        assert.equal(f.sent.length, 0);
        f.screen.unmount();
    });
    test(`Notifications describes sending through the ${org.toLowerCase()} channel`, async () => {
        const f = await mountForm('NotificationFormView', org);
        assert.ok(f.screen.button('Send notification'));
        submit(f.screen.all((n) => n.tag === 'form')[0]); await flush();
        assert.equal(f.alerts[0].text, 'Send the new notification?');
        assert.equal(f.sent.length, 1);
        assert.equal(f.alerts[1].text, 'Notification saved and sent.');
        assert.equal(f.alerts[1].confirmButtonText, 'Ok');
        f.screen.unmount();
    });
}

// The privacy policy link (2026-10-08). An empty value tells the server to REMOVE the link the
// organisation's app shows, so the form may send the field only when the box holds what was saved.
test('General Settings sends the privacy policy link it read from the saved settings', async () => {
    const f = await mountForm('GeneralSettingsView');
    submit(f.screen.all((n) => n.tag === 'form')[0]); await flush();
    assert.equal(f.sent.length, 1);
    assert.equal(f.sent[0].body.get('privacy_policy_url'), 'https://www.example.org/privacy');
    f.screen.unmount();
});

test('General Settings that could not read the saved settings does not send the privacy policy link', async () => {
    const f = await mountForm('GeneralSettingsView', 'Masjid', true, 'success', 'fails');
    submit(f.screen.all((n) => n.tag === 'form')[0]); await flush();
    assert.equal(f.sent.length, 1);
    assert.equal(f.sent[0].body.has('privacy_policy_url'), false, 'an empty box that was never loaded would erase the saved link');
    assert.equal(f.sent[0].body.has('copyright_text'), true, 'the rest of the form is still sent, as before');
    f.screen.unmount();
});

test('General Settings accepts the addresses the server accepts and refuses the ones it refuses', async () => {
    const f = await mountForm('GeneralSettingsView');
    for (const ok of ['', ' https://www.example.org/privacy ', 'HTTPS://example.org', 'https://www.example.org:8443/p?x=1#y', 'https://xn--r8jz45g.xn--zckzah/privacy']) {
        await f.schema.validateAt('privacy_policy_url', { privacy_policy_url: ok });
    }
    for (const bad of ['http://example.org/privacy', 'example.org/privacy', '/privacy', 'https://user:pw@example.org/', 'https://exa_mple.org/', 'https://example.org:123456/', 'https://example.org/our privacy', 'null']) {
        await assert.rejects(f.schema.validateAt('privacy_policy_url', { privacy_policy_url: bad }), bad);
    }
    f.screen.unmount();
});

for (const file of ['GeneralSettingsView', 'PrayerCalculationSettingsView', 'ThemeSettingsView']) {
    for (const outcome of ['success', 'failed']) {
        test(`${file}: ${outcome} uses an Ok message after the confirmation`, async () => {
            const f = await mountForm(file, 'Masjid', true, outcome);
            submit(f.screen.all((n) => n.tag === 'form')[0]); await flush();
            assert.equal(f.alerts[0].showCancelButton, true);
            assert.equal(f.alerts[0].confirmButtonText, 'Yes, confirm');
            assert.equal(f.sent.length, 1);
            assert.equal(f.alerts[1].confirmButtonText, 'Ok');
            assert.equal(f.alerts[1].showCancelButton, false);
            assert.equal(f.alerts[1].icon, outcome === 'success' ? 'success' : 'warning');
            f.screen.unmount();
        });
    }
}
