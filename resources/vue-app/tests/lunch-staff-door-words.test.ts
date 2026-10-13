/**
 * The lunch-staff door answers in sentences; the office has to be able to read them.
 *
 * With one login able to hold a role at each organisation, the door that gives
 * someone lunch access can now refuse for reasons that are the whole explanation:
 * the address is a teacher's at this organisation, or adding an existing login is
 * switched off. Those refusals come as validation does, a sentence per field and
 * NO top-level `message`, and the form used to show axios's "Request failed with
 * status code 422" for them. The real board is mounted here and the door's real
 * bodies are played to it (they are pinned on the PHP side, in
 * tests/Feature/OneLoginTwoRolesDoorsTest.php).
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as vue from 'vue';
import { click, flush, httpError, mountSfc, type } from './support/mountSfc.ts';
import { modulesFor } from './support/batch3Modules.ts';

const doc = (globalThis as any).document;
doc.addEventListener ??= () => {};
doc.removeEventListener ??= () => {};
doc.body ??= { style: {} };
(globalThis as any).window ??= { addEventListener() {}, removeEventListener() {} };

const file = 'views/dashboard/JummahLunchView.vue';
const PERSON = { id: 5, name: 'Practice Person', email: 'person@example.test', phone: null, invited: false };

/** The board as an office sees it, with the staff door scripted. */
async function office(door: { createStaff?: (form: any) => Promise<void>; updateStaff?: (id: number, form: any) => Promise<void> }) {
    const toasts: any[] = [];
    const store: any = vue.reactive({
        menus: [], currentMenu: null, orders: [], staff: [PERSON], serviceOptions: [],
        isLunchStaff: () => false, organisationId: () => 1,
        fetchMenus: async () => {}, fetchMenu: async () => {}, fetchOrders: async () => {},
        fetchServices: async () => {}, fetchStaff: async () => {}, fetchServiceOptions: async () => {},
        createStaff: door.createStaff ?? (async () => {}), updateStaff: door.updateStaff ?? (async () => {}),
    });

    const screen = await mountSfc(file, {}, await modulesFor(file, {
        'vue-router': { useRoute: () => ({ params: {}, query: {} }), useRouter: () => ({ replace: async () => {}, resolve: () => ({ href: '/' }) }) },
        '@/stores/masjid/jummahLunchStore': { useJummahLunchStore: () => store, MENU_KIND_CATALOGUE: 'catalogue', MENU_KIND_DATED: 'dated', PAID_VIA_OPTIONS: [] },
        sweetalert2: { default: { fire: async (options: any) => { toasts.push(options); return {}; } } },
    }));
    await flush();

    const field = (id: string) => screen.all((n) => n.props.id === id)[0];
    const said = (id: string) => screen.all((n) => n.props.id === `${id}-error`)[0];

    return { screen, toasts, field, said };
}

/** The door's refusal of an address, exactly as the server sends it: no top-level message. */
const refusedAddress = (sentence: string) => httpError(422, { status: 'failed', data: { email: [sentence] } });

test('an address the door will not add: its sentence is under the email field, announced, and tied to the field', async () => {
    for (const sentence of [
        'person@example.test is already a teacher at this organisation. One login can hold one role at an organisation.',
        'Adding an existing login to a second organisation is switched off.',
        'The email has already been taken.',
    ]) {
        const s = await office({ createStaff: async () => { throw refusedAddress(sentence); } });

        click(s.screen.button('+ Give someone access'));
        await flush();
        type(s.field('jl-staff-name'), 'Practice Person');
        type(s.field('jl-staff-email'), 'person@example.test');
        click(s.screen.button('Save'));
        await flush();

        const error = s.said('jl-staff-email');
        assert.ok(error, sentence);
        assert.equal(error.textContent, sentence, 'the door\'s words, not a summary of them');
        assert.equal(error.props.role, 'alert');
        assert.equal(s.field('jl-staff-email').props['aria-invalid'], 'true');
        assert.equal(s.field('jl-staff-email').props['aria-describedby'], 'jl-staff-email-error');
        assert.match(String(s.field('jl-staff-email').props.class), /is-invalid/);

        // The other fields are not accused, the form is still open, and no toast says "status code 422".
        assert.equal(s.said('jl-staff-name'), undefined);
        assert.equal(s.field('jl-staff-name').props['aria-invalid'], undefined);
        assert.match(s.screen.text(), /Give someone lunch access/);
        assert.deepEqual(s.toasts, []);
        assert.doesNotMatch(s.screen.text(), /status code/);
        s.screen.unmount();
    }
});

test('a person who also belongs to another organisation: the sentence is under the name it is about', async () => {
    const sentence = 'This person\'s name and phone are shared with another Manara organisation; ask them or Manara support to change them.';
    const s = await office({ updateStaff: async () => { throw httpError(422, { status: 'failed', message: sentence, data: { name: [sentence] } }); } });

    click(s.screen.all((n) => n.tag === 'button' && n.textContent === 'Edit')[0]);
    await flush();
    type(s.field('jl-staff-name'), 'Another Name');
    click(s.screen.button('Save'));
    await flush();

    assert.equal(s.said('jl-staff-name').textContent, sentence);
    assert.equal(s.said('jl-staff-email'), undefined);
    assert.deepEqual(s.toasts, []);
    s.screen.unmount();
});

test('the sentence goes when the form is tried again or opened again', async () => {
    let refuse = true;
    const s = await office({ createStaff: async () => { if (refuse) throw refusedAddress('The email has already been taken.'); } });

    click(s.screen.button('+ Give someone access'));
    await flush();
    click(s.screen.button('Save'));
    await flush();
    assert.ok(s.said('jl-staff-email'));

    // Opened again: a fresh form.
    click(s.screen.button('Cancel'));
    click(s.screen.button('+ Give someone access'));
    await flush();
    assert.equal(s.said('jl-staff-email'), undefined);

    // Tried again and accepted: closed, with the success it always showed.
    refuse = false;
    click(s.screen.button('Save'));
    await flush();
    assert.doesNotMatch(s.screen.text(), /Give someone lunch access/);
    assert.equal(s.toasts.length, 1);
    assert.equal(s.toasts[0].icon, 'success');
    s.screen.unmount();
});

test('a refusal that is not about a field is still said where it always was', async () => {
    // A capability switched off answers 403 with a message and no field.
    const s = await office({ createStaff: async () => { throw httpError(403, { status: 'error', message: 'Friday lunch ordering is not switched on for this organisation.' }); } });

    click(s.screen.button('+ Give someone access'));
    await flush();
    click(s.screen.button('Save'));
    await flush();

    assert.equal(s.toasts.length, 1);
    assert.equal(s.toasts[0].icon, 'error');
    assert.equal(s.toasts[0].title, 'Friday lunch ordering is not switched on for this organisation.');
    assert.equal(s.said('jl-staff-email'), undefined);
    s.screen.unmount();
});
