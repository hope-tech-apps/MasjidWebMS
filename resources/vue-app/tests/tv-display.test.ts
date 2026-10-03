/**
 * The TV Display screen's form (views/dashboard/tvDisplay.ts), the guards in its view and
 * store, and its place in the router and the sidebar. The server validates and stores; these
 * pin what the screen sends it, above all that a setting nobody changed is sent as null
 * ("automatic") so a save changes nothing else on the board. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { formFrom, formProblems, sameForm, saveBody, screenSummary } from '../views/dashboard/tvDisplay.ts';
import type { TvDisplayContext, TvDisplayEffective, TvDisplayForm, TvDisplaySettings } from '../views/dashboard/tvDisplay.ts';

const root = new URL('../', import.meta.url);
const read = (rel: string) => readFileSync(new URL(rel, root), 'utf8');
/** Source with its comments taken out, for a "must not contain" check. */
const code = (source: string) => source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/<!--[\s\S]*?-->/g, '').replace(/^\s*\/\/.*$/gm, '');

const neverSaved: TvDisplaySettings = {
    is_enabled: null, header_title: null, carousel_interval_seconds: null, show_prayer_panel: null, show_qr: null, donate_caption: null,
};
const allNull = { ...neverSaved };
const limits = { header_title_max: 60, donate_caption_max: 40, carousel_interval_min: 3, carousel_interval_max: 120 };
const untouched: TvDisplayForm = { slides: true, prayerPanel: true, qr: true, title: '', caption: '', seconds: '' };

const context = (over: Partial<TvDisplayContext> = {}): TvDisplayContext => ({
    organisation_name: 'Al-Noor Centre', is_masjid: true, has_donation_link: true,
    defaults: { carousel_interval_seconds: 10, donate_caption: 'Scan to Donate' }, limits, updated_at: null, ...over,
});
const effective = (over: Partial<TvDisplayEffective> = {}): TvDisplayEffective => ({
    is_enabled: true, header_title: null, carousel_interval_seconds: 10, show_prayer_panel: true, show_qr: true, donate_caption: 'Scan to Donate', ...over,
});

// ------------------------------------------------------------------------------------------- the form

test('an organisation that never saved opens with every switch on and every field blank, and saving it sends six nulls', () => {
    assert.deepEqual(formFrom(neverSaved), untouched);
    assert.deepEqual(saveBody(formFrom(neverSaved)), allNull);
    assert.deepEqual(Object.keys(saveBody(formFrom(neverSaved))).sort(), Object.keys(allNull).sort(), 'exactly the six keys');
});

test('a switch that is on is sent as null, never true, and one that is off is sent as false', () => {
    assert.deepEqual(saveBody({ ...untouched, slides: false }), { ...allNull, is_enabled: false });
    assert.deepEqual(saveBody({ ...untouched, prayerPanel: false }), { ...allNull, show_prayer_panel: false });
    assert.deepEqual(saveBody({ ...untouched, qr: false }), { ...allNull, show_qr: false });

    const on = saveBody({ ...untouched, slides: true, prayerPanel: true, qr: true });
    for (const key of ['is_enabled', 'show_prayer_panel', 'show_qr'] as const) assert.equal(on[key], null, key);
    assert.ok(!Object.values(saveBody(untouched)).includes(true), 'no key is ever true');
});

test('a switch that was turned off opens off, and a stored true opens the same as automatic', () => {
    assert.deepEqual(
        formFrom({ ...neverSaved, is_enabled: false, show_prayer_panel: false, show_qr: false }),
        { ...untouched, slides: false, prayerPanel: false, qr: false },
    );
    assert.deepEqual(formFrom({ ...neverSaved, is_enabled: true, show_prayer_panel: true, show_qr: true }), untouched);
});

test('stored values come back into the form and are sent back as they were', () => {
    const stored: TvDisplaySettings = {
        is_enabled: false, header_title: 'Al-Noor Centre', carousel_interval_seconds: 15, show_prayer_panel: false, show_qr: false, donate_caption: 'Give today',
    };
    assert.deepEqual(formFrom(stored), { slides: false, prayerPanel: false, qr: false, title: 'Al-Noor Centre', caption: 'Give today', seconds: '15' });
    assert.deepEqual(saveBody(formFrom(stored)), stored);
});

test('blank text and blank seconds are sent as null, text is trimmed, and seconds are sent as an integer', () => {
    assert.deepEqual(saveBody({ ...untouched, title: '   ', caption: '', seconds: '  ' }), allNull);
    assert.deepEqual(
        saveBody({ ...untouched, title: '  Al-Noor Centre ', caption: ' Give today  ', seconds: ' 15 ' }),
        { ...allNull, header_title: 'Al-Noor Centre', donate_caption: 'Give today', carousel_interval_seconds: 15 },
    );
    assert.equal(saveBody({ ...untouched, seconds: '3' }).carousel_interval_seconds, 3);
    // A number input hands the form a number, not text.
    assert.equal(saveBody({ ...untouched, seconds: 120 as unknown as string }).carousel_interval_seconds, 120);
});

test('seconds that are not a whole number are never sent as null: they go as typed, for the server to refuse', () => {
    assert.equal(saveBody({ ...untouched, seconds: 'abc' }).carousel_interval_seconds, 'abc');
    assert.equal(saveBody({ ...untouched, seconds: '10.5' }).carousel_interval_seconds, '10.5');
});

// --------------------------------------------------------------------------------------- the problems

test('seconds are refused below the lowest and above the highest, and allowed on both', () => {
    const at = (seconds: string) => formProblems({ ...untouched, seconds }, limits);

    assert.deepEqual(at('2'), { seconds: 'Seconds per slide must be between 3 and 120.' });
    assert.deepEqual(at('3'), {});
    assert.deepEqual(at('120'), {});
    assert.deepEqual(at('121'), { seconds: 'Seconds per slide must be between 3 and 120.' });
    assert.deepEqual(at(''), {}, 'blank is automatic, not a problem');
});

test('seconds that are not a whole number are refused, with their own sentence', () => {
    assert.deepEqual(formProblems({ ...untouched, seconds: '10.5' }, limits), { seconds: 'Seconds per slide must be a whole number.' });
    assert.deepEqual(formProblems({ ...untouched, seconds: 'abc' }, limits), { seconds: 'Seconds per slide must be a whole number.' });
});

test('the range in the sentence comes from the limits the server sent', () => {
    const other = { ...limits, carousel_interval_min: 5, carousel_interval_max: 60 };
    assert.deepEqual(formProblems({ ...untouched, seconds: '4' }, other), { seconds: 'Seconds per slide must be between 5 and 60.' });
    assert.deepEqual(formProblems({ ...untouched, seconds: '61' }, other), { seconds: 'Seconds per slide must be between 5 and 60.' });
    assert.deepEqual(formProblems({ ...untouched, seconds: '5' }, other), {});
});

test('a title or caption is allowed at its limit and refused one character over, counted after trimming', () => {
    assert.deepEqual(formProblems({ ...untouched, title: 'a'.repeat(60), caption: 'b'.repeat(40) }, limits), {});
    assert.deepEqual(formProblems({ ...untouched, title: `  ${'a'.repeat(60)}  ` }, limits), {});
    assert.deepEqual(formProblems({ ...untouched, title: 'a'.repeat(61) }, limits), { title: 'The title can be at most 60 characters. You have 61.' });
    assert.deepEqual(
        formProblems({ ...untouched, caption: 'b'.repeat(41) }, limits),
        { caption: 'The words under the QR code can be at most 40 characters. You have 41.' },
    );
    // Characters, as the server counts them: sixty letters outside the basic plane are sixty, not 120.
    assert.deepEqual(formProblems({ ...untouched, title: '𝒜'.repeat(60) }, limits), {});
});

test('a line break in the title or the caption is refused', () => {
    assert.deepEqual(formProblems({ ...untouched, title: 'Al-Noor\nCentre' }, limits), { title: 'The title must be on one line.' });
    assert.deepEqual(formProblems({ ...untouched, caption: 'Give\r\ntoday' }, limits), { caption: 'The words under the QR code must be on one line.' });
    // A tab pasted from a spreadsheet is refused here too: the server refuses every control character.
    assert.deepEqual(formProblems({ ...untouched, title: 'Al-Noor\tCentre' }, limits), { title: 'The title must be on one line.' });
});

test('every problem is reported at once, each under its own field', () => {
    assert.deepEqual(Object.keys(formProblems({ ...untouched, title: 'a'.repeat(61), caption: 'b\nc', seconds: '0' }, limits)).sort(), ['caption', 'seconds', 'title']);
    assert.deepEqual(formProblems(untouched, limits), {});
});

// ---------------------------------------------------------------------------------- nothing to save

test('a form that would save the same thing has nothing to save', () => {
    assert.equal(sameForm(formFrom(neverSaved), formFrom(neverSaved)), true);
    assert.equal(sameForm({ ...untouched, title: 'Al-Noor ' }, { ...untouched, title: 'Al-Noor' }), true, 'a trailing space is not a change');
    assert.equal(sameForm({ ...untouched, seconds: 15 as unknown as string }, { ...untouched, seconds: '15' }), true);

    assert.equal(sameForm({ ...untouched, slides: false }, untouched), false);
    assert.equal(sameForm({ ...untouched, prayerPanel: false }, untouched), false);
    assert.equal(sameForm({ ...untouched, qr: false }, untouched), false);
    assert.equal(sameForm({ ...untouched, title: 'Al-Noor' }, untouched), false);
    assert.equal(sameForm({ ...untouched, caption: 'Give' }, untouched), false);
    assert.equal(sameForm({ ...untouched, seconds: '15' }, untouched), false);
});

// -------------------------------------------------------------------------------- on the screen now

test('a masjid with a donation link: its name, the slides and their pace, prayer times, and the code with its words', () => {
    assert.deepEqual(screenSummary(effective(), context()), [
        'Title at the top: Al-Noor Centre',
        'Announcement slides: on, a new slide every 10 seconds',
        'Prayer times: shown',
        'Donation QR code: shown, with the words “Scan to Donate”',
    ]);
    assert.deepEqual(
        screenSummary(effective({ header_title: 'Welcome', carousel_interval_seconds: 20, show_prayer_panel: false, show_qr: false }), context()),
        // Both hidden: the board's right-hand panel is then an empty box, and the summary says so.
        ['Title at the top: Welcome', 'Announcement slides: on, a new slide every 20 seconds', 'Prayer times: hidden', 'Donation QR code: hidden',
            'The right side of the screen is an empty panel: no prayer times and no donation code'],
    );
});

test('a masjid without a donation link is told there is no link yet, not that the code is hidden', () => {
    assert.deepEqual(screenSummary(effective({ show_qr: false }), context({ has_donation_link: false })), [
        'Title at the top: Al-Noor Centre',
        'Announcement slides: on, a new slide every 10 seconds',
        'Prayer times: shown',
        'Donation QR code: not shown, there is no donation link',
    ]);
});

test('a school is told nothing about prayer times', () => {
    const lines = screenSummary(
        effective({ show_prayer_panel: false, show_qr: false }),
        context({ organisation_name: 'Al-Razi School', is_masjid: false, has_donation_link: false }),
    );
    assert.deepEqual(lines, [
        'Title at the top: Al-Razi School',
        'Announcement slides: on, a new slide every 10 seconds',
        'Donation QR code: not shown, there is no donation link',
        // A school with no donation link has nothing for the right-hand panel at all.
        'The right side of the screen is an empty panel: no prayer times and no donation code',
    ]);
});

test('a paused board says so, and says what is left on it: prayer times alone, or only the title', () => {
    assert.deepEqual(screenSummary(effective({ is_enabled: false }), context()), [
        'Announcement slides: paused',
        'The screen shows prayer times only',
    ]);
    assert.deepEqual(
        screenSummary(effective({ is_enabled: false, show_prayer_panel: false, header_title: 'Welcome' }), context({ is_masjid: false })),
        ['Announcement slides: paused', 'The screen shows only the title: Welcome'],
    );
});

// ------------------------------------------------------------------------------- the store, as source

test('the store reads with GET and saves with ApiService.post on /tv-display, never put or patch, and keeps the server\'s data', () => {
    const store = code(read('stores/masjid/tvDisplayStore.ts'));

    assert.match(store, /return `\/api\/admin\/masjids\/\$\{id\}\/tv-display`;/);
    assert.match(store, /await ApiService\.get\(base\(\)\)/);
    assert.match(store, /await ApiService\.post\(base\(\), body\)/, 'a plain object, which ApiService.post sends as JSON');
    assert.doesNotMatch(store, /ApiService\.(put|patch|changeRecords)\(/, 'put and patch change the global Content-Type');
    assert.doesNotMatch(store, /URLSearchParams|FormData/, 'a form body cannot say null');
    assert.match(store, /const data = res\.data\?\.data;/);
    assert.match(store, /!data\?\.settings \|\| !data\?\.effective \|\| !data\?\.context\) throw new Error\(failure\)/, 'an answer without its parts is a failure, not a blank form');
});

// -------------------------------------------------------------------------------- the view, as source

const view = read('views/dashboard/TvDisplayView.vue');
const template = view.slice(0, view.indexOf('<script setup'));

test('the view offers no Save before a load has succeeded, and save() refuses until then', () => {
    const loading = template.indexOf(`v-if="loadState === 'loading'"`);
    const failed = template.indexOf(`v-else-if="loadState === 'failed' || !payload"`);
    const ready = template.indexOf('<template v-else>');
    const submit = template.indexOf('type="submit"');

    assert.ok(loading > 0 && failed > loading && ready > failed && submit > ready, 'loading, then failed, then the form with its Save');
    assert.equal((template.match(/type="submit"/g) ?? []).length, 1, 'one Save, inside the loaded branch');
    assert.equal((template.match(/<form /g) ?? []).length, 1);
    assert.ok(template.indexOf('<form ') > ready);
    assert.match(template, /<button type="submit" class="btn btn-success" :disabled="!canSave">/);

    assert.match(view, /const canSave = computed\(\(\) => loadState\.value === 'ready' && !!payload\.value && !saving\.value/);
    assert.match(view, /async function save\(\) \{\s*if \(!canSave\.value\) return;/, 'save() refuses until the settings have loaded');
    // 'ready' is set only after the GET answered and the form was seeded from it.
    assert.match(view, /await store\.load\(\);\s*if \(ticket !== loadTicket\) return;\s*seed\(\);\s*loadState\.value = 'ready';/);
    assert.equal((view.match(/loadState\.value = 'ready'/g) ?? []).length, 1);
});

test('the QR switch is drawn off without a donation link, and that drawing never changes form.qr', () => {
    const qr = template.slice(template.indexOf('id="tv-qr"'), template.indexOf('for="tv-qr"'));

    assert.match(qr, /:checked="form\.qr && payload\.context\.has_donation_link"/);
    assert.match(qr, /:disabled="!payload\.context\.has_donation_link"/);
    assert.match(qr, /@change="setQr"/);
    assert.doesNotMatch(qr, /v-model/, 'a v-model would write the drawn value back into the form');
    assert.match(view, /function setQr\(event: Event\) \{\s*if \(!payload\.value\?\.context\.has_donation_link\) return;\s*form\.value\.qr = /);
    assert.equal((code(view).match(/form\.value\.qr\s*=/g) ?? []).length, 1, 'form.qr is written in one place only');
});

test('the prayer switch is rendered only for a masjid', () => {
    assert.match(template, /<div v-if="payload\.context\.is_masjid" class="mt-3">\s*<div class="form-check form-switch">\s*<input id="tv-prayer" v-model="form\.prayerPanel"/);
    assert.equal((template.match(/tv-prayer"/g) ?? []).length, 2, 'the input and its label, nowhere else');
});

test('the view builds its body with the helpers, redraws from the server\'s answer, and has no dead header button', () => {
    assert.match(view, /await store\.save\(changedBody\(form\.value, loaded\.value\)\);/);
    assert.match(view, /form\.value = formFrom\(store\.payload\.settings, store\.payload\.context\.defaults\);/);
    assert.match(view, /formProblems\(form\.value, payload\.value\.context\.limits\)/);
    assert.match(view, /screenSummary\(payload\.value\.effective, payload\.value\.context\)/);
    assert.match(template, /<PageDataContainer title="TV Display" :hideButton="true">/);
    assert.doesNotMatch(view, /vee-validate|yup/);
});

test('a link to another page is offered only where the sidebar offers that page, and both pages exist under those names', () => {
    assert.match(view, /menuItemState\(item, authStore\.user\?\.type, masjidStore\.masjid, masjidStore\.orgType\) !== 'hidden'/);
    assert.match(template, /<router-link v-if="announcementsReachable" class="small" :to="\{ name: 'masjid\.announcements' \}">/);
    assert.match(template, /<router-link v-if="!payload\.context\.has_donation_link && donationReachable" class="small" :to="\{ name: 'masjid\.donation' \}">/);
    assert.match(view, /reachable\('\/masjid\/announcements'\)/);
    assert.match(view, /reachable\('\/masjid\/donation'\)/);

    assert.match(read('router/routes/announcementsManagementRoutes.ts'), /path: 'announcements',\s*name: 'masjid\.announcements',/);
    assert.match(read('router/routes/dashboardLayoutRoutes.ts'), /path: 'donation',\s*name: 'masjid\.donation',/);
    const menu = read('core/constants/dashboardAsideMenuItems.ts');
    for (const to of ["to: '/masjid/announcements'", "to: '/masjid/donation'"]) assert.ok(menu.includes(to), to);
});

test('the limits, placeholders and hints come from the server\'s answer, not from numbers typed into the page', () => {
    assert.match(template, /id="tv-title"[^>]*:placeholder="payload\.context\.organisation_name"/);
    // The lengths are checked by formProblems against the server's limits, in characters; a maxlength attribute counts UTF-16 units.
    assert.doesNotMatch(template, /maxlength/);
    assert.match(view, /formProblems\(form\.value, payload\.value\.context\.limits\)/);
    assert.match(template, /id="tv-caption"[^>]*:placeholder="payload\.context\.defaults\.donate_caption"/);
    // The speeds are a list built from the server's defaults and limits (secondsChoices), not typed into the page.
    assert.match(view, /secondsChoices\(loaded\.value\.seconds, payload\.value\.context\)/);
    assert.match(template, /<option v-for="choice in secondsOptions" :key="choice\.value" :value="choice\.value">\{\{ choice\.label \}\}<\/option>/);
    assert.doesNotMatch(code(template), /\b(60|40|120)\b/, 'no limit is hard-coded');
    assert.doesNotMatch(code(template), /Scan to Donate/);
});

test('every control has a label, and the page says organisation and uses no em dash', () => {
    for (const id of ['tv-slides', 'tv-prayer', 'tv-qr', 'tv-title', 'tv-caption', 'tv-seconds']) {
        assert.ok(template.includes(`id="${id}"`) && template.includes(`for="${id}"`), id);
    }
    assert.equal((template.match(/role="switch"/g) ?? []).length, 3);

    for (const source of [view, read('views/dashboard/tvDisplay.ts')]) {
        assert.doesNotMatch(source, /—/);
        assert.doesNotMatch(source, /organization/i);
    }
});

// ------------------------------------------------------------------------------- the wiring, as source

test('the route is /masjid/tv-display for administrators, with a page title and no gate', () => {
    const layout = read('router/routes/dashboardLayoutRoutes.ts');
    const at = layout.indexOf("path: 'tv-display'");
    const route = layout.slice(at, layout.indexOf('},', layout.indexOf('component:', at)));

    assert.ok(at > 0);
    assert.match(route, /name: 'masjid\.tvDisplay'/);
    assert.match(route, /auth: true/);
    assert.match(route, /allowedUsers: \['SuperAdmin', 'MasjidAdmin'\]/);
    assert.match(route, /pageTitle: 'TV Display'/);
    assert.match(route, /component: \(\) => import\("@\/views\/dashboard\/TvDisplayView\.vue"\)/);
    assert.doesNotMatch(route, /requiresModule|requiresCapability|requiresAnyCapability|requiresCrm/);
    assert.equal((layout.match(/path: 'tv-display'/g) ?? []).length, 1);
});

test('the sidebar entry sits directly after Broadcasts, for administrators, with no module and no capability', () => {
    const menu = read('core/constants/dashboardAsideMenuItems.ts');
    const at = menu.indexOf("to: '/masjid/tv-display'");
    const item = menu.slice(menu.lastIndexOf('\n    {', at), menu.indexOf('\n    },', at));

    assert.match(item, /title: "TV Display"/);
    assert.match(item, /svg_icon: `<svg /);
    assert.match(item, /allowed_types: \['SuperAdmin', 'MasjidAdmin'\]/);
    assert.doesNotMatch(code(item), /requiresModule|requiresCapability|requiresOrgTypes|requiresCrm|requiresAssistant/);
    assert.equal((menu.match(/to: '\/masjid\/tv-display'/g) ?? []).length, 1, 'one entry, and it is not duplicated');

    const titles = [...menu.matchAll(/^        title: "([^"]+)"/gm)].map((m) => m[1]);
    assert.equal(titles[titles.indexOf('Broadcasts') + 1], 'TV Display');
});

test('the sidebar entry has its icon, and both route types name the page', () => {
    assert.match(read('components/dashboard/DashboardAside.vue'), /'\/masjid\/tv-display': 'bi-tv'/);
    assert.ok(read('core/types/config/SystemRoutes.ts').includes("'/masjid/tv-display' |"));
    assert.ok(read('core/types/config/BackendApiRoutes.ts').includes('`/api/admin/masjids/${string}/tv-display` |'));
    assert.doesNotMatch(read('core/types/data/Capability.ts'), /tv_display|tv-display/, 'no module key and no capability was added');
});

test('the store never shows or saves one organisation\'s settings as another\'s', () => {
    const store = read('stores/masjid/tvDisplayStore.ts');

    // A load answered after a switch of organisation is dropped, not shown.
    assert.match(store, /const id = masjidStore\.masjid\?\.id \?\? null;\s*payload\.value = null;\s*loadedFor\.value = null;\s*const res = await ApiService\.get\(base\(\)\);\s*\/\/[^\n]*\n\s*if \(\(masjidStore\.masjid\?\.id \?\? null\) !== id\) return;/);
    // A save is refused unless the settings on screen were loaded for the organisation it would be sent to.
    assert.match(store, /if \(loadedFor\.value === null \|\| loadedFor\.value !== id\) \{\s*throw new Error\("The organisation changed\. Reload this page, then save\."\);/);
    const save = store.slice(store.indexOf('async function save'));
    assert.ok(save.indexOf('throw new Error("The organisation changed') < save.indexOf('ApiService.post('), 'refused BEFORE anything is sent');

    // And the screen lets only its newest load change the page.
    assert.match(view, /const ticket = \+\+loadTicket;/);
    assert.equal((view.match(/if \(ticket !== loadTicket\) return;/g) ?? []).length, 2, 'after a success and after a failure');
});

test('an empty right-hand panel is said, on the summary and before the save', async () => {
    const { rightSideEmpty, RIGHT_SIDE_EMPTY, screenSummary } = await import('../views/dashboard/tvDisplay.ts');

    // Slides on, and neither prayer times nor the donation code: the board draws an empty box there.
    assert.equal(rightSideEmpty(true, false, false), true);
    assert.equal(rightSideEmpty(true, true, false), false);
    assert.equal(rightSideEmpty(true, false, true), false);
    // A paused board has no right-hand panel at all.
    assert.equal(rightSideEmpty(false, false, false), false);

    const context = { organisation_name: 'A School', is_masjid: false, has_donation_link: false,
        defaults: { carousel_interval_seconds: 10, donate_caption: 'Scan to Donate' }, limits, updated_at: null };
    const school = screenSummary({ is_enabled: true, header_title: null, carousel_interval_seconds: 10, show_prayer_panel: false, show_qr: false, donate_caption: 'Scan to Donate' }, context);
    assert.equal(school[school.length - 1], RIGHT_SIDE_EMPTY);

    const withCode = screenSummary({ is_enabled: true, header_title: null, carousel_interval_seconds: 10, show_prayer_panel: false, show_qr: true, donate_caption: 'Scan to Donate' }, { ...context, has_donation_link: true });
    assert.ok(!withCode.includes(RIGHT_SIDE_EMPTY));

    // The page works it out from the form as it stands, with the two derived conditions applied.
    assert.match(view, /rightSideEmpty\(\s*form\.value\.slides,\s*payload\.value\.context\.is_masjid && form\.value\.prayerPanel,\s*payload\.value\.context\.has_donation_link && form\.value\.qr,\s*\)/);
    assert.match(view, /<p v-if="emptyRightSide" id="tv-right-side-note"/);
});

test('the speeds offered are the even ones inside the limits, the usual one is the blank choice, and an odd stored one is kept and marked', async () => {
    const { EVEN_SLIDE_SECONDS, secondsChoices, formFrom: from, screenSummary: summary } = await import('../views/dashboard/tvDisplay.ts');
    const ctx = { defaults: { carousel_interval_seconds: 10, donate_caption: 'Scan to Donate' }, limits };

    // Each one divides 40 or is a multiple of it: the board redraws every 40 seconds and restarts the slide clock.
    for (const seconds of EVEN_SLIDE_SECONDS) assert.ok(40 % seconds === 0 || seconds % 40 === 0, `${seconds}`);

    assert.deepEqual(secondsChoices('', ctx), [
        { value: '5', label: '5 seconds' },
        { value: '', label: '10 seconds, the usual speed' },
        { value: '20', label: '20 seconds' },
        { value: '40', label: '40 seconds' },
        { value: '80', label: '80 seconds' },
        { value: '120', label: '120 seconds (2 minutes)' },
    ]);
    // A speed already stored that is not an even one is never dropped from the list.
    assert.deepEqual(secondsChoices('15', ctx).find((choice) => choice.value === '15'), { value: '15', label: '15 seconds (uneven on the screen)' });
    assert.equal(secondsChoices('20', ctx).length, 6, 'an even one is not listed twice');
    // The limits are the server's.
    assert.deepEqual(secondsChoices('', { ...ctx, limits: { ...limits, carousel_interval_min: 10, carousel_interval_max: 40 } }).map((choice) => choice.value), ['', '20', '40']);

    // A stored speed equal to the usual one is shown as the usual choice, so the list has one entry for it.
    assert.equal(from({ ...allNull, carousel_interval_seconds: 10 }, ctx.defaults).seconds, '');
    assert.equal(from({ ...allNull, carousel_interval_seconds: 20 }, ctx.defaults).seconds, '20');
    assert.equal(from({ ...allNull, carousel_interval_seconds: 10 }).seconds, '10', 'without the defaults nothing is assumed');

    // And the summary does not promise an even rhythm the board will not keep.
    const lines = summary({ is_enabled: true, header_title: null, carousel_interval_seconds: 60, show_prayer_panel: true, show_qr: true, donate_caption: 'Scan to Donate' },
        { organisation_name: 'Al-Noor Centre', is_masjid: true, has_donation_link: true, ...ctx, updated_at: null });
    assert.equal(lines[1], 'Announcement slides: on, a new slide about every 60 seconds (uneven on the screen)');
});
