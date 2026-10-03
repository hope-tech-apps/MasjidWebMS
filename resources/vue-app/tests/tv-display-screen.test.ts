/**
 * The TV Display screen, MOUNTED: views/dashboard/TvDisplayView.vue is compiled from its .vue file
 * and driven with the store answering what the server answers (tests/support/mountSfc.ts says how,
 * with no DOM).
 *
 * tv-display.test.ts says the helpers build the right body; only a mounted test can say the screen
 * SENDS it, that a failed load leaves nothing to save, and that a switch drawn off for want of a
 * donation link still goes to the server as null.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import * as ApiErrors from '../core/services/ApiErrors.ts';
import * as tvDisplay from '../views/dashboard/tvDisplay.ts';
import { deferred, flush, httpError, mountSfc, submit, type } from './support/mountSfc.ts';
import type { Mounted, Node } from './support/mountSfc.ts';

const vue = createRequire(import.meta.url)('vue');

// ------------------------------------------------------------------------------------------ fixtures

const limits = { header_title_max: 60, donate_caption_max: 40, carousel_interval_min: 3, carousel_interval_max: 120 };
const neverSaved = { is_enabled: null, header_title: null, carousel_interval_seconds: null, show_prayer_panel: null, show_qr: null, donate_caption: null };
const allNull = { ...neverSaved };

/** What the server answers, by its own rules: the stored row, and what the board receives for it. */
function answer(settings: Record<string, any> = {}, about: Record<string, any> = {}) {
    const stored = { ...neverSaved, ...settings };
    const context = {
        organisation_name: 'Al-Noor Centre', is_masjid: true, has_donation_link: true,
        defaults: { carousel_interval_seconds: 10, donate_caption: 'Scan to Donate' }, limits, updated_at: null, ...about,
    };

    return {
        settings: stored,
        effective: {
            is_enabled: stored.is_enabled ?? true,
            header_title: stored.header_title,
            carousel_interval_seconds: stored.carousel_interval_seconds ?? context.defaults.carousel_interval_seconds,
            show_prayer_panel: context.is_masjid && (stored.show_prayer_panel ?? true),
            show_qr: context.has_donation_link && (stored.show_qr ?? true),
            donate_caption: stored.donate_caption ?? context.defaults.donate_caption,
        },
        context,
    };
}

// ------------------------------------------------------------------------------------------- doubles

/** The sweetalert2 stand-in: records every toast and dialog. */
function swalDouble() {
    const dialogs: any[] = [];

    return { dialogs, default: { fire: async (options: any) => { dialogs.push(options); return { isConfirmed: true }; } } };
}

/** PageDataContainer without its card: the title, the header button unless it is hidden, and the body. */
const containerStub = {
    props: ['title', 'hideButton', 'buttonProps', 'paginationOptions'],
    render(this: any) {
        return vue.h('div', [
            vue.h('h1', this.title),
            this.hideButton ? null : vue.h('button', this.buttonProps?.title ?? 'Add New'),
            this.$slots.default?.(),
        ]);
    },
};

/**
 * The TV display store with its payload reactive and its calls recorded. A save is answered the way
 * the server answers one (an absent key unchanged, null = automatic) unless the test scripts it.
 */
function storeDouble(first: ReturnType<typeof answer>, script: { load?: () => Promise<any>; save?: (body: any) => Promise<any> } = {}) {
    const calls: Array<{ name: string; body?: any }> = [];
    const store: any = vue.reactive({ payload: null });

    store.load = async () => {
        calls.push({ name: 'load' });
        store.payload = null;
        store.payload = await (script.load ? script.load() : Promise.resolve(first));
    };
    store.save = async (body: any) => {
        calls.push({ name: 'save', body });
        store.payload = await (script.save ? script.save(body) : Promise.resolve(answer({ ...store.payload.settings, ...body }, store.payload.context)));
    };

    return { store, calls, module: { useTvDisplayStore: () => store } };
}

async function mount(storeDbl = storeDouble(answer()), options: { hidden?: string[]; swal?: ReturnType<typeof swalDouble>; masjidStore?: any } = {}) {
    const swal = options.swal ?? swalDouble();
    const hidden = options.hidden ?? [];
    const masjidStore = options.masjidStore ?? { masjid: { id: 7, name: 'Al-Noor Centre' }, orgType: 'masjid' };
    const screen = await mountSfc('views/dashboard/TvDisplayView.vue', {}, {
        'sweetalert2': swal,
        '@/components/PageDataContainer.vue': { default: containerStub },
        '@/core/access/orgAccess': { menuItemState: (item: any) => (hidden.includes(item.to) ? 'hidden' : 'visible') },
        '@/core/constants/dashboardAsideMenuItems': { MASJID_DASHBOARD_ASIDE_MENU: [{ to: '/masjid/announcements' }, { to: '/masjid/donation' }] },
        '@/core/services/ApiErrors': ApiErrors,
        '@/stores/authStore': { useAuthStore: () => ({ user: { type: 'MasjidAdmin' } }) },
        '@/stores/masjidStore': { useMasjidStore: () => masjidStore },
        '@/stores/masjid/tvDisplayStore': storeDbl.module,
        '@/views/dashboard/tvDisplay': tvDisplay,
    });
    await flush();

    return { screen, swal, ...storeDbl };
}

/** Whether an element with this id is on the screen. A boolean, because a failed assertion on a Node tries to print the whole tree. */
const shown = (screen: Mounted, id: string): boolean => screen.all((n) => n.props.id === id).length > 0;
const byId = (screen: Mounted, id: string): Node => {
    const found = screen.all((n) => n.props.id === id);
    if (found.length !== 1) throw new Error(`${found.length} elements have id ${id} (screen: ${screen.text()})`);

    return found[0];
};
const form = (screen: Mounted): Node => screen.all((n) => n.tag === 'form')[0];
const saves = (calls: Array<{ name: string; body?: any }>) => calls.filter((call) => call.name === 'save').map((call) => call.body);
const links = (screen: Mounted): string[] => screen.all((n) => n.tag === 'router-link').map((n) => n.props.to?.name);

/** A switch bound with v-model, flipped as a browser flips it: the box changes, then says so. */
function flip(el: Node) {
    (el as any).checked = !(el as any).checked;
    (el.listeners.change ?? []).forEach((listener) => listener({ target: el }));
}

/** A change event on a switch with its own handler. */
const change = (el: Node, checked: boolean) => el.props.onChange({ target: { checked } });

// --------------------------------------------------------------------------------------------- tests

test('an organisation that never saved: Save is off until something changes, and a change is sent with the rest as null', async () => {
    const { screen, calls, swal } = await mount();

    assert.match(screen.text(), /Settings for the screen in your lobby\. The screen picks up a change within about four minutes\./);
    assert.equal((byId(screen, 'tv-slides') as any).checked, true);
    assert.equal((byId(screen, 'tv-prayer') as any).checked, true);
    assert.equal(byId(screen, 'tv-qr').props.checked, true);
    assert.equal(screen.button('Save').disabled, true, 'nothing to save yet');

    // A submit the disabled button did not stop (Enter in a field) sends nothing either.
    submit(form(screen));
    await flush();
    assert.deepEqual(saves(calls), []);

    type(byId(screen, 'tv-title'), '  Welcome to Al-Noor ');
    await flush();
    assert.equal(screen.button('Save').disabled, false);

    submit(form(screen));
    await flush();

    assert.deepEqual(saves(calls), [{ ...allNull, header_title: 'Welcome to Al-Noor' }]);
    assert.equal(swal.dialogs.length, 1);
    assert.equal(swal.dialogs[0].title, 'TV display settings saved');
    // Redrawn from the server's answer: the trimmed title, the list below, and nothing left to save.
    assert.equal(byId(screen, 'tv-title').value, 'Welcome to Al-Noor');
    assert.match(screen.text(), /Title at the top: Welcome to Al-Noor/);
    assert.equal(screen.button('Save').disabled, true);
    screen.unmount();
});

test('every switch turned off is sent as false, and turned back on is sent as null', async () => {
    const { screen, calls } = await mount();

    flip(byId(screen, 'tv-slides'));
    flip(byId(screen, 'tv-prayer'));
    change(byId(screen, 'tv-qr'), false);
    await flush();
    assert.match(screen.text(), /The screen then shows only the title at the top\./, 'paused with the prayer panel off');
    submit(form(screen));
    await flush();

    flip(byId(screen, 'tv-slides'));
    flip(byId(screen, 'tv-prayer'));
    change(byId(screen, 'tv-qr'), true);
    await flush();
    submit(form(screen));
    await flush();

    assert.deepEqual(saves(calls), [
        { ...allNull, is_enabled: false, show_prayer_panel: false, show_qr: false },
        allNull,
    ]);
    screen.unmount();
});

test('a failed load offers Retry and no Save, and Retry brings the form', async () => {
    let attempt = 0;
    const dbl = storeDouble(answer(), {
        load: () => (++attempt === 1 ? Promise.reject(httpError(500, { status: 'error', message: 'Server Error' })) : Promise.resolve(answer({ carousel_interval_seconds: 15 }))),
    });
    const { screen, calls } = await mount(dbl);

    assert.match(screen.text(), /Server Error/);
    assert.equal(screen.all((n) => n.tag === 'form').length, 0, 'no form over settings that were never read');
    assert.equal(screen.all((n) => n.tag === 'button' && n.textContent.includes('Save')).length, 0);
    assert.equal(screen.all((n) => n.tag === 'input').length, 0);

    screen.button('Retry').props.onClick({});
    await flush();

    assert.equal(byId(screen, 'tv-seconds').value, '15');
    assert.equal(screen.button('Save').disabled, true);
    assert.deepEqual(saves(calls), []);
    screen.unmount();
});

test('without a donation link the QR switch is drawn off and disabled, and a save still sends show_qr as null', async () => {
    const { screen, calls } = await mount(storeDouble(answer({}, { has_donation_link: false })));
    const qr = byId(screen, 'tv-qr');

    assert.equal(qr.props.checked, undefined, 'drawn off');
    assert.equal(qr.disabled, true);
    assert.match(screen.text(), /Add a donation link first; the code appears once one is set\./);
    assert.ok(links(screen).includes('masjid.donation'));
    assert.match(screen.text(), /Donation QR code: not shown, no donation link yet/);
    assert.equal(screen.button('Save').disabled, true, 'the drawing is not a change');

    // A change event that reached the handler anyway changes nothing.
    change(qr, true);
    change(qr, false);
    type(byId(screen, 'tv-seconds'), '20');
    await flush();
    submit(form(screen));
    await flush();

    assert.deepEqual(saves(calls), [{ ...allNull, carousel_interval_seconds: 20 }]);
    screen.unmount();
});

test('an organisation with no Donation screen is not sent to one', async () => {
    const { screen } = await mount(storeDouble(answer({}, { has_donation_link: false })), { hidden: ['/masjid/donation', '/masjid/announcements'] });

    assert.deepEqual(links(screen), []);
    assert.match(screen.text(), /Your organisation has no donation link, so there is no code to show\./);
    assert.doesNotMatch(screen.text(), /Add a donation link first/);
    screen.unmount();
});

test('a school sees no prayer switch, and its save leaves show_prayer_panel as null', async () => {
    const { screen, calls } = await mount(storeDouble(answer({}, { organisation_name: 'Al-Razi School', is_masjid: false })));

    assert.equal(shown(screen, 'tv-prayer'), false);
    assert.doesNotMatch(screen.text(), /Prayer/);
    assert.equal(byId(screen, 'tv-title').props.placeholder, 'Al-Razi School');

    flip(byId(screen, 'tv-slides'));
    await flush();
    assert.match(screen.text(), /The screen then shows only the title at the top\./);
    submit(form(screen));
    await flush();

    assert.deepEqual(saves(calls), [{ ...allNull, is_enabled: false }]);
    assert.match(screen.text(), /Announcement slides: paused/);
    assert.match(screen.text(), /The screen shows only the title: Al-Razi School/);
    screen.unmount();
});

test('a masjid pausing its slides is told the screen then shows prayer times only', async () => {
    const { screen } = await mount();

    assert.doesNotMatch(screen.text(), /The screen then shows/);
    flip(byId(screen, 'tv-slides'));
    await flush();
    assert.match(screen.text(), /The screen then shows prayer times only\./);
    assert.ok(links(screen).includes('masjid.announcements'));
    screen.unmount();
});

test('the limits and the hints are the server\'s, and a problem is shown beside its field and turns Save off', async () => {
    const { screen, calls } = await mount();
    const seconds = byId(screen, 'tv-seconds');

    assert.equal(seconds.props.min, 3);
    assert.equal(seconds.props.max, 120);
    assert.equal(seconds.props.placeholder, '10');
    assert.equal(byId(screen, 'tv-title').props.maxlength, 60);
    assert.equal(byId(screen, 'tv-caption').props.maxlength, 40);
    assert.equal(byId(screen, 'tv-caption').props.placeholder, 'Scan to Donate');
    assert.match(screen.text(), /Between 3 and 120\. Leave blank for 10\./);
    assert.match(screen.text(), /Leave blank for “Scan to Donate”\./);

    type(seconds, '2');
    await flush();
    assert.equal(byId(screen, 'tv-seconds-error').textContent, 'Seconds per slide must be between 3 and 120.');
    assert.equal(byId(screen, 'tv-seconds').props['aria-invalid'], 'true');
    assert.equal(screen.button('Save').disabled, true);
    submit(form(screen));
    await flush();
    assert.deepEqual(saves(calls), []);

    type(seconds, '3');
    await flush();
    assert.equal(shown(screen, 'tv-seconds-error'), false, 'the lowest allowed value is not a problem');
    assert.equal(screen.button('Save').disabled, false);
    screen.unmount();
});

test('text a number field cannot read is a problem, never a blank that would save as automatic', async () => {
    const { screen, calls } = await mount(storeDouble(answer({ carousel_interval_seconds: 15 })));
    const seconds = byId(screen, 'tv-seconds');

    // What a browser hands over for "abc" in a number field: an empty value, flagged as bad input.
    (seconds as any).validity = { badInput: true };
    type(seconds, '');
    await flush();

    assert.equal(byId(screen, 'tv-seconds-error').textContent, 'Seconds per slide must be a whole number.');
    assert.equal(screen.button('Save').disabled, true);
    submit(form(screen));
    await flush();
    assert.deepEqual(saves(calls), []);

    // Really blank is automatic, and can be saved.
    (seconds as any).validity = { badInput: false };
    type(seconds, '');
    await flush();
    submit(form(screen));
    await flush();
    assert.deepEqual(saves(calls), [{ ...allNull, carousel_interval_seconds: null }]);
    screen.unmount();
});

test('a refused save says the server\'s words on the page, keeps what was typed, and shows no success', async () => {
    const dbl = storeDouble(answer(), {
        save: () => Promise.reject(httpError(422, { status: 'failed', data: { header_title: ['Keep this to one line.'] } })),
    });
    const { screen, swal } = await mount(dbl);

    type(byId(screen, 'tv-title'), 'Welcome');
    await flush();
    submit(form(screen));
    await flush();

    assert.match(screen.text(), /Not saved\. Keep this to one line\./);
    assert.equal(byId(screen, 'tv-title').value, 'Welcome');
    assert.equal(swal.dialogs.length, 0);
    assert.equal(screen.button('Save').disabled, false, 'the same form can be sent again');
    assert.match(screen.text(), /Title at the top: Al-Noor Centre/, 'the list still says what is stored');
    screen.unmount();
});

test('a save that fails without an answer is said on the page too', async () => {
    const { screen } = await mount(storeDouble(answer(), { save: () => Promise.reject(new Error('Network Error')) }));

    flip(byId(screen, 'tv-slides'));
    await flush();
    submit(form(screen));
    await flush();

    assert.match(screen.text(), /Not saved\. Network Error/);
    assert.equal((byId(screen, 'tv-slides') as any).checked, false);
    screen.unmount();
});

test('while a save is in flight the button is busy, and a second submit sends nothing', async () => {
    const pending = deferred();
    const { screen, calls } = await mount(storeDouble(answer(), { save: () => pending.promise }));

    type(byId(screen, 'tv-caption'), 'Give today');
    await flush();
    submit(form(screen));
    await flush();

    assert.equal(screen.button('Saving').disabled, true);
    submit(form(screen));
    await flush();
    assert.equal(saves(calls).length, 1, 'one save, however many submits');

    pending.resolve(answer({ donate_caption: 'Give today' }));
    await flush();
    assert.equal(screen.button('Save').disabled, true);
    assert.match(screen.text(), /Donation QR code: shown, with the words “Give today”/);
    screen.unmount();
});

test('the page has no dead "Add New" button', async () => {
    const { screen } = await mount();

    assert.equal(screen.all((n) => n.tag === 'button').length, 1, 'Save, and nothing else');
    screen.unmount();
});

test('an administrator who switches organisation mid-load sees the new one, and the old one\'s late failure changes nothing', async () => {
    // Organisation 7's load is still in flight when the administrator switches to organisation 8.
    const first = deferred<any>();
    const second = deferred<any>();
    const answers = [first.promise, second.promise];
    const masjidStore = vue.reactive({ masjid: { id: 7, name: 'Al-Noor Centre' }, orgType: 'masjid' });
    const { screen, calls } = await mount(storeDouble(answer(), { load: () => answers.shift()! }), { masjidStore });

    masjidStore.masjid = { id: 8, name: 'Second Centre' };
    await flush();
    assert.equal(calls.filter((call) => call.name === 'load').length, 2, 'the switch loads the new organisation');

    second.resolve(answer({ header_title: 'Second Centre board' }, { organisation_name: 'Second Centre' }));
    await flush();
    assert.match(screen.text(), /Title at the top: Second Centre board/, 'drawn from the new organisation\'s answer');
    assert.equal(shown(screen, 'tv-slides'), true, 'the new organisation\'s form is on the page');

    // The request for the organisation they left now fails. It must not take the page down.
    first.reject(httpError(500, { status: 'failed', data: 'An error occurred while processing your request.' }));
    await flush();

    assert.equal(shown(screen, 'tv-slides'), true, 'the form is still there');
    assert.doesNotMatch(screen.text(), /Could not load|Retry/);
});
