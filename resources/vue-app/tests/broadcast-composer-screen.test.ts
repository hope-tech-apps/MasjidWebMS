/**
 * The broadcast composer, MOUNTED: views/dashboard/broadcasts/BroadcastComposerView.vue is compiled
 * from its .vue file and drawn for an organisation (tests/support/mountSfc.ts says how, with no DOM).
 *
 * broadcast-payload.test.ts says which channels the list holds; only a mounted test can say which
 * tick boxes an admin is shown: no "Lobby screen" (no TV app reads that channel), the lobby TV named
 * on the announcements feed for an organisation that has one, and a switched-off module still hidden.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import * as broadcastPayload from '../views/dashboard/broadcasts/broadcastPayload.ts';
import { flush, loadTs, mountSfc, submit } from './support/mountSfc.ts';
import type { Mounted, Node } from './support/mountSfc.ts';

const require = createRequire(import.meta.url);
const vue = require('vue');
const yup = require('yup');

// ------------------------------------------------------------------------------------------- doubles

const typesOnly = {};
const slotOnly = (tag: string) => ({ render(this: any) { return vue.h(tag, this.$slots.default?.()); } });

/** vee-validate's Form without its validation: a form that says when it was submitted. */
const formStub = {
    props: ['validationSchema'],
    emits: ['submit'],
    render(this: any) { return vue.h('form', { onSubmit: () => this.$emit('submit') }, this.$slots.default?.()); },
};

/** The app's own grant and module checks, not a copy of them. */
async function orgAccess() {
    const menu = await loadTs('core/constants/dashboardAsideMenuItems.ts', { '@/core/types/config/AsideMenuItem': typesOnly });

    return loadTs('core/access/orgAccess.ts', {
        '@/core/constants/dashboardAsideMenuItems': menu,
        '@/core/types/config/AsideMenuItem': typesOnly,
        '@/core/types/data/Capability': typesOnly,
        '@/core/types/data/Masjid': typesOnly,
        '@/core/types/data/User': typesOnly,
        '@/core/types/data/Vertical': typesOnly,
    });
}

async function mount(masjid: Record<string, any>) {
    const asked: string[] = [];
    const screen = await mountSfc('views/dashboard/broadcasts/BroadcastComposerView.vue', {}, {
        '@/assets/ts/swalMethods': { getMessageFromObj: () => '' },
        '@/components/form/ColumnInputContainer.vue': { default: slotOnly('div') },
        '@/components/form/ImageDraggableInput.vue': { default: slotOnly('div') },
        '@/components/broadcasts/NewsletterEditor.vue': { default: slotOnly('div') },
        '@/components/broadcasts/NewsletterPreview.vue': { default: slotOnly('div') },
        '@/core/helpers/newsletterBlocks': { appendNewsletter: () => {}, keyMinter: () => () => 'k1', previewCopy: async (f: any) => f },
        '@/components/form/LoadingButton.vue': { default: slotOnly('button') },
        '@/core/plugins/SweetAlerts2': {
            MSwal: { fire: async () => ({ isConfirmed: true }) },
            // The last reversible moment: record the question, and answer "no" so nothing is sent.
            QSwal: { fire: async (_title: string, question: string) => { asked.push(question); return { isConfirmed: false }; } },
        },
        '@/core/services/ApiService': { default: { get: async () => ({ data: { status: 'success', data: [] } }), post: async () => { throw new Error('nothing is sent in this test'); } } },
        '@/core/types/config/AxiosCustom': typesOnly,
        '@/core/types/config/BackendApiRoutes': typesOnly,
        '@/core/types/data/masjid-related/Service': typesOnly,
        '@/core/types/data/masjid-related/ContactTag': typesOnly,
        '@/core/types/elements/ImageInput': typesOnly,
        '@/stores/masjidStore': { useMasjidStore: () => vue.reactive({ masjid }) },
        '@/stores/masjid/broadcastsStore': { useBroadcastsStore: () => ({ fetchBroadcastsPaginated: async () => {} }) },
        './broadcastPayload': broadcastPayload,
        '@/core/access/orgAccess': await orgAccess(),
        'axios': typesOnly,
        'sweetalert2': typesOnly,
        'vee-validate': { Form: formStub, Field: slotOnly('input') },
        'vue-router': { useRouter: () => ({ push: () => {} }) },
        'yup': yup,
    });
    await flush();

    return { screen, asked };
}

/** The "Send to" tick boxes, in order: [channel, label, hint]. */
function channels(screen: Mounted): Array<[string, string, string]> {
    return screen.all((n) => n.tag === 'input' && String(n.props.id ?? '').startsWith('ch-')).map((box) => {
        const label = screen.all((n) => n.tag === 'label' && n.props.for === box.props.id)[0];
        const [name, hint] = label.children.filter((c) => c.kind === 'el').map((c) => c.textContent);

        return [String(box.props.id).slice(3), name, hint];
    });
}

/**
 * Tick a box bound with v-model, as a browser does: the box changes, says so, and the page is drawn
 * again before the next click (each box reads the list as it stood at the last draw).
 */
async function tick(screen: Mounted, channel: string) {
    const box: Node = screen.all((n) => n.props.id === `ch-${channel}`)[0];
    (box as any).checked = true;
    (box.listeners.change ?? []).forEach((listener) => listener({ target: box }));
    await flush();
}

const organisation = (over: Record<string, any> = {}) => ({ id: 7, name: 'Al-Noor Centre', crm_enabled: false, capabilities: {}, modules_off: [], ...over });

// --------------------------------------------------------------------------------------------- tests

test('an organisation is offered four channels and no lobby screen', async () => {
    const { screen } = await mount(organisation());

    assert.deepEqual(channels(screen).map(([channel, label]) => [channel, label]), [
        ['announcement', 'Announcements feed'],
        ['push', 'Push notification'],
        ['email', 'Email'],
        ['sms', 'Text message'],
    ]);
    assert.doesNotMatch(screen.text(), /lobby|signage|TV/i);
    screen.unmount();
});

test('the announcements feed names the lobby TV for an organisation with the tv_display grant, and for no other', async () => {
    const withTv = await mount(organisation({ capabilities: { tv_display: true } }));
    const feed = channels(withTv.screen).find(([channel]) => channel === 'announcement')!;
    assert.equal(feed[2], 'Adds a post to the app and website feed. Your lobby TV shows it too. Needs a picture and a date range.');
    assert.equal(channels(withTv.screen).length, 4);
    withTv.screen.unmount();

    const without = await mount(organisation({ capabilities: { tv_display: false } }));
    assert.equal(channels(without.screen).find(([channel]) => channel === 'announcement')![2], 'Adds a post to the app and website feed. Needs a picture and a date range.');
    without.screen.unmount();
});

test('a channel whose module is switched off is still not offered', async () => {
    const { screen } = await mount(organisation({ modules_off: ['announcements'], capabilities: { tv_display: true } }));

    assert.deepEqual(channels(screen).map(([channel]) => channel), ['push', 'email', 'sms']);
    screen.unmount();
});

test('the question before sending names the channels that were ticked', async () => {
    const { screen, asked } = await mount(organisation());

    await tick(screen, 'push');
    await tick(screen, 'email');
    submit(screen.all((n) => n.tag === 'form')[0]);
    await flush();

    assert.equal(asked.length, 1);
    assert.match(asked[0], /to everyone via Push notification, Email\?/);
    screen.unmount();
});
