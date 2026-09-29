/**
 * The parent portal keeps one session per school (core/helpers/familySessions.ts).
 * A parent with children at two schools signs in to both; neither sign-in may
 * replace the other, and a request may carry only the token of the school its
 * URL names.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    FAMILY_SESSIONS_KEY,
    LEGACY_FAMILY_KEYS,
    authFailureMasjidId,
    dropSlot,
    familyNextPath,
    familyReturnTarget,
    familyRouteRedirect,
    masjidIdOfUrl,
    putSlot,
    readSlots,
    tokenForUrl,
} from '../core/helpers/familySessions.ts';

/** A Storage that behaves like localStorage: string values, null when absent. */
function memoryStorage(seed: Record<string, string> = {}) {
    const data = new Map<string, string>(Object.entries(seed));

    return {
        getItem: (k: string) => (data.has(k) ? data.get(k)! : null),
        setItem: (k: string, v: string) => void data.set(k, String(v)),
        removeItem: (k: string) => void data.delete(k),
        keys: () => [...data.keys()],
    };
}

const contact = (masjidId: number, name = 'Amal') => ({
    id: masjidId * 10,
    masjid_id: masjidId,
    first_name: name,
    last_name: 'Parent',
    login_email: `${name.toLowerCase()}@example.test`,
});

const slot = (masjidId: number, token = `tok-${masjidId}`) => ({ token, contact: contact(masjidId) });

test('two schools coexist: signing in to B keeps A', () => {
    const s = memoryStorage();

    putSlot(s, 7, slot(7));
    putSlot(s, 9, slot(9));

    const slots = readSlots(s);
    assert.deepEqual(Object.keys(slots).sort(), ['7', '9']);
    assert.equal(slots['7'].token, 'tok-7');
    assert.equal(slots['9'].token, 'tok-9');
});

test('signing in to the same school again replaces only that slot', () => {
    const s = memoryStorage();

    putSlot(s, 7, slot(7, 'old'));
    putSlot(s, 9, slot(9));
    putSlot(s, 7, slot(7, 'fresh'));

    assert.equal(readSlots(s)['7'].token, 'fresh');
    assert.equal(readSlots(s)['9'].token, 'tok-9');
});

test('a request carries the token of the school its URL names, never the other', () => {
    const s = memoryStorage();
    putSlot(s, 7, slot(7));
    putSlot(s, 9, slot(9));

    assert.equal(tokenForUrl(s, '/api/family/masjids/7/groups'), 'tok-7');
    assert.equal(tokenForUrl(s, '/api/family/masjids/9/groups/3/threads?page=2'), 'tok-9');
    assert.equal(tokenForUrl(s, '/api/family/masjids/9'), 'tok-9');
    assert.equal(tokenForUrl(s, 'https://school.example.test/api/family/masjids/7/me', 'https://school.example.test'), 'tok-7');
});

test('an absolute URL carries a token only when its PARSED origin is the portal\'s', () => {
    const s = memoryStorage();
    putSlot(s, 7, slot(7));
    const portal = 'https://app.example.org';

    assert.equal(tokenForUrl(s, `${portal}/api/family/masjids/7/me`, portal), 'tok-7');
    assert.equal(tokenForUrl(s, `${portal}:443/api/family/masjids/7/me`, portal), 'tok-7', 'a default port is the same origin');

    // Family-shaped paths on somebody else's server.
    assert.equal(tokenForUrl(s, 'https://evil.example/api/family/masjids/7/me', portal), null);
    // A text-prefix test passes both of these; a parsed origin does not.
    assert.equal(tokenForUrl(s, 'https://app.example.org.evil.com/api/family/masjids/7/me', portal), null);
    assert.equal(tokenForUrl(s, 'https://app.example.org@evil.com/api/family/masjids/7/me', portal), null);
    assert.equal(tokenForUrl(s, 'http://app.example.org/api/family/masjids/7/me', portal), null, 'another scheme is another origin');
    assert.equal(tokenForUrl(s, '//evil.example/api/family/masjids/7/me', portal), null, 'a protocol-relative URL is not a path');
});

test('with no known portal origin, no absolute URL is the portal\'s (relative paths still are)', () => {
    const s = memoryStorage();
    putSlot(s, 7, slot(7));

    assert.equal(tokenForUrl(s, 'https://evil.example/api/family/masjids/7/me'), null);
    assert.equal(tokenForUrl(s, 'https://evil.example/api/family/masjids/7/me', null), null);
    assert.equal(tokenForUrl(s, '/api/family/masjids/7/me'), 'tok-7');
});

test('a school the parent has not signed in to gets no token, and no other school lends one', () => {
    const s = memoryStorage();
    putSlot(s, 7, slot(7));

    assert.equal(tokenForUrl(s, '/api/family/masjids/9/groups'), null);
    // Id prefixes must not match: school 70 is not school 7.
    assert.equal(tokenForUrl(s, '/api/family/masjids/70/groups'), null);
});

test('URLs that are not family routes carry no token', () => {
    const s = memoryStorage();
    putSlot(s, 7, slot(7));

    assert.equal(tokenForUrl(s, '/api/mobile/masjids/7'), null);
    assert.equal(tokenForUrl(s, '/api/admin/masjids/7/contacts'), null);
    assert.equal(tokenForUrl(s, '/x/api/family/masjids/7/me'), null);
    assert.equal(tokenForUrl(s, undefined), null);
    assert.equal(masjidIdOfUrl('/api/family/masjids/abc/me'), null);
});

test('signing out of one school keeps the other, and leaves no masjid key behind', () => {
    const s = memoryStorage();
    putSlot(s, 7, slot(7));
    putSlot(s, 9, slot(9));

    dropSlot(s, 7);

    const slots = readSlots(s);
    assert.deepEqual(Object.keys(slots), ['9']);
    assert.equal(tokenForUrl(s, '/api/family/masjids/7/me'), null);
    assert.equal(tokenForUrl(s, '/api/family/masjids/9/me'), 'tok-9');

    dropSlot(s, 9);
    assert.deepEqual(s.keys(), [], 'the last sign-out removes the storage entry entirely');
});

test('signing out of a school that is not signed in is a no-op', () => {
    const s = memoryStorage();
    putSlot(s, 7, slot(7));

    dropSlot(s, 8);

    assert.deepEqual(Object.keys(readSlots(s)), ['7']);
});

test('a stale slot is dropped on 401 or 403, from the school the failed request addressed', () => {
    const s = memoryStorage();
    putSlot(s, 7, slot(7));
    putSlot(s, 9, slot(9));

    for (const status of [401, 403]) {
        const failed = authFailureMasjidId({ response: { status }, config: { url: '/api/family/masjids/7/groups' } }, 9);
        assert.equal(failed, '7', 'the request addressed school 7, so school 7 ends, though school 9 is on screen');
    }

    dropSlot(s, authFailureMasjidId({ response: { status: 401 }, config: { url: '/api/family/masjids/7/me' } }, 9)!);

    assert.deepEqual(Object.keys(readSlots(s)), ['9'], 'school 9 is untouched by school 7 expiring');
});

test('other failures do not end a session', () => {
    for (const status of [404, 410, 422, 429, 500, undefined]) {
        assert.equal(
            authFailureMasjidId({ response: { status }, config: { url: '/api/family/masjids/7/me' } }, 7),
            null,
        );
    }
    assert.equal(authFailureMasjidId(new Error('network'), 7), null);
});

test('an error with no family URL falls back to the school the caller was showing', () => {
    assert.equal(authFailureMasjidId({ response: { status: 401 }, config: { url: '/somewhere/else' } }, 12), '12');
    assert.equal(authFailureMasjidId({ response: { status: 401 } }, 12), '12');
});

test('the route guard checks the slot for the URL school', () => {
    const slots = { '7': slot(7) };

    assert.equal(familyRouteRedirect(slots, '7', true), true);
    assert.equal(familyRouteRedirect(slots, '9', true), '/family/9/sign-in', 'a session for 7 does not open 9');
    assert.equal(familyRouteRedirect(slots, '9', false), true, 'sign-in and invite screens need no session');
    assert.equal(familyRouteRedirect({}, 7, true), '/family/7/sign-in');
});

test('another tab is seen: reads go to storage, and a write keeps what another tab added', () => {
    const s = memoryStorage();

    putSlot(s, 7, slot(7)); // this tab
    // A second tab signs in to school 9 directly in storage.
    s.setItem(FAMILY_SESSIONS_KEY, JSON.stringify({ '7': slot(7), '9': slot(9) }));

    assert.equal(tokenForUrl(s, '/api/family/masjids/9/me'), 'tok-9');

    putSlot(s, 11, slot(11)); // this tab signs in to a third school
    assert.deepEqual(Object.keys(readSlots(s)).sort(), ['11', '7', '9']);
});

test('a session stored before this change is adopted into its school and the old keys are removed', () => {
    const s = memoryStorage({
        [LEGACY_FAMILY_KEYS.token]: 'legacy-tok',
        [LEGACY_FAMILY_KEYS.contact]: JSON.stringify(contact(7)),
        [LEGACY_FAMILY_KEYS.masjid]: '7',
    });

    const slots = readSlots(s);

    assert.equal(slots['7'].token, 'legacy-tok');
    assert.equal(tokenForUrl(s, '/api/family/masjids/7/me'), 'legacy-tok');
    assert.equal(tokenForUrl(s, '/api/family/masjids/9/me'), null);
    assert.deepEqual(s.keys(), [FAMILY_SESSIONS_KEY], 'the legacy triple is gone');
});

test('a legacy triple written AFTER the slot (a pre-deploy tab signing in again) replaces that school\'s stale slot', () => {
    // The slot holds a revoked token; a tab still on the previous bundle signed
    // in again and wrote a fresh legacy triple. Keeping the slot would sign the
    // next request with the revoked token and bounce the parent to sign-in.
    const s = memoryStorage({
        [FAMILY_SESSIONS_KEY]: JSON.stringify({ '7': slot(7, 'revoked'), '9': slot(9) }),
        [LEGACY_FAMILY_KEYS.token]: 'fresh-legacy',
        [LEGACY_FAMILY_KEYS.contact]: JSON.stringify(contact(7)),
        [LEGACY_FAMILY_KEYS.masjid]: '7',
    });

    const slots = readSlots(s);

    assert.equal(slots['7'].token, 'fresh-legacy');
    assert.equal(slots['9'].token, 'tok-9', 'the other school is untouched');
    assert.equal(tokenForUrl(s, '/api/family/masjids/7/me'), 'fresh-legacy');
    assert.deepEqual(s.keys(), [FAMILY_SESSIONS_KEY], 'the legacy triple is gone');
});

test('a legacy triple with the slot\'s own token changes nothing but is removed', () => {
    const s = memoryStorage({
        [FAMILY_SESSIONS_KEY]: JSON.stringify({ '7': slot(7, 'same') }),
        [LEGACY_FAMILY_KEYS.token]: 'same',
        [LEGACY_FAMILY_KEYS.contact]: JSON.stringify(contact(7)),
        [LEGACY_FAMILY_KEYS.masjid]: '7',
    });

    assert.equal(readSlots(s)['7'].token, 'same');
    assert.deepEqual(s.keys(), [FAMILY_SESSIONS_KEY]);
});

test('an unusable legacy triple never replaces a good slot', () => {
    const s = memoryStorage({
        [FAMILY_SESSIONS_KEY]: JSON.stringify({ '7': slot(7, 'good') }),
        [LEGACY_FAMILY_KEYS.token]: 'half',
        [LEGACY_FAMILY_KEYS.contact]: 'not json',
        [LEGACY_FAMILY_KEYS.masjid]: '7',
    });

    assert.equal(readSlots(s)['7'].token, 'good');
    assert.deepEqual(s.keys(), [FAMILY_SESSIONS_KEY]);
});

test('a half-signed-out legacy triple is discarded, not adopted', () => {
    // The old signOut removed the token and contact and left the masjid key.
    const s = memoryStorage({ [LEGACY_FAMILY_KEYS.masjid]: '7' });

    assert.deepEqual(readSlots(s), {});
    assert.deepEqual(s.keys(), []);
});

test('malformed storage reads as signed out, never as a session', () => {
    for (const raw of ['not json', '[]', 'null', '{"7":{"token":"","contact":{}}}', '{"x":{"token":"t","contact":{}}}', '{"7":{"token":"t"}}']) {
        assert.deepEqual(readSlots(memoryStorage({ [FAMILY_SESSIONS_KEY]: raw })), {}, raw);
    }
});

test('a storage that throws reads as signed out', () => {
    const broken = {
        getItem: () => { throw new Error('denied'); },
        setItem: () => { throw new Error('denied'); },
        removeItem: () => { throw new Error('denied'); },
    };

    assert.deepEqual(readSlots(broken), {});
    assert.equal(tokenForUrl(broken, '/api/family/masjids/7/me'), null);
});

test('a session without a token, contact or school id is refused, not stored', () => {
    const s = memoryStorage();

    assert.throws(() => putSlot(s, 7, { token: '', contact: contact(7) }));
    assert.throws(() => putSlot(s, 7, { token: 't', contact: null as any }));
    assert.throws(() => putSlot(s, 'seven', slot(7)));
    assert.deepEqual(s.keys(), []);
});

test('sign-in hands a parent on to the weekly report of THIS school and nothing else', () => {
    assert.equal(familyNextPath('7', '/family/7/classes/3/report'), '/family/7/classes/3/report');
    assert.equal(familyNextPath(7, '/family/7/classes/31/report'), '/family/7/classes/31/report');

    // Everything else lands on the home screen: another school's screen, another page, a query
    // string, an off-site or protocol-relative target, a traversal, a non-string.
    const home = '/family/7';
    for (const bad of [
        '/family/9/classes/3/report',
        '/family/7/classes/3',
        '/family/7/classes/3/report?x=1',
        '/family/7/classes/3/report/../../..',
        '/family/7/classes/x/report',
        '//evil.example/family/7/classes/3/report',
        'https://evil.example/family/7/classes/3/report',
        'javascript:alert(1)',
        '/family/7/classes/3/report\n',
        '',
        null,
        undefined,
        ['/family/7/classes/3/report'],
        42,
    ]) {
        assert.equal(familyNextPath('7', bad), home, JSON.stringify(bad));
    }
});

test('a signed-out parent opening the report is sent to sign in and back to it; nothing else is carried', () => {
    const report = '/family/7/classes/3/report';

    assert.equal(
        familyRouteRedirect({}, '7', true, report),
        `/family/7/sign-in?next=${encodeURIComponent(report)}`,
    );
    // A session for THIS school stays put, and one for another school does not open it.
    assert.equal(familyRouteRedirect({ '7': slot(7) }, '7', true, report), true);
    assert.equal(familyRouteRedirect({ '9': slot(9) }, '7', true, report), `/family/7/sign-in?next=${encodeURIComponent(report)}`);
    // An intended path that is not on the allowlist is dropped rather than echoed into the query.
    assert.equal(familyRouteRedirect({}, '7', true, '/family/7/classes/3'), '/family/7/sign-in');
    assert.equal(familyRouteRedirect({}, '7', true, '/family/9/classes/3/report'), '/family/7/sign-in');
    assert.equal(familyRouteRedirect({}, '7', true, 'https://evil.example/'), '/family/7/sign-in');
    // Without an intended path, as before.
    assert.equal(familyRouteRedirect({}, '7', true), '/family/7/sign-in');
});

test('the report link may name the week it reported, and only as a real calendar date', () => {
    const report = '/family/7/classes/3/report';

    assert.equal(familyNextPath('7', `${report}?week=2026-10-04`), `${report}?week=2026-10-04`);
    assert.equal(familyNextPath(7, '/family/7/classes/31/report?week=2027-02-28'), '/family/7/classes/31/report?week=2027-02-28');

    // Anything else in that position lands on the home screen, as before: not a date, not a real date,
    // more query, a fragment, another school, a newline, an off-site target dressed as a week.
    const home = '/family/7';
    for (const bad of [
        `${report}?week=2026-02-30`,
        `${report}?week=2026-13-01`,
        `${report}?week=2026-10-4`,
        `${report}?week=`,
        `${report}?week=next`,
        `${report}?week=2026-10-04&x=1`,
        `${report}?x=1&week=2026-10-04`,
        `${report}?week=2026-10-04#top`,
        `${report}?week=2026-10-04\n`,
        `${report}?week=//evil.example`,
        '/family/9/classes/3/report?week=2026-10-04',
        '/family/7/classes/3?week=2026-10-04',
    ]) {
        assert.equal(familyNextPath('7', bad), home, JSON.stringify(bad));
    }
});

test('a signed-out parent opening Friday\'s report on Sunday is sent back to the SAME week, and nothing else in the query travels', () => {
    const report = '/family/7/classes/3/report';

    assert.equal(familyReturnTarget(report, '2026-10-04'), `${report}?week=2026-10-04`);
    // No week, an invalid one, a repeated key (an array) or a non-string: the bare path.
    for (const noWeek of [undefined, null, '', '2026-02-30', 'current', ['2026-10-04', '2026-10-11'], 4]) {
        assert.equal(familyReturnTarget(report, noWeek), report, JSON.stringify(noWeek));
    }

    const withWeek = `${report}?week=2026-10-04`;
    assert.equal(
        familyRouteRedirect({}, '7', true, withWeek),
        `/family/7/sign-in?next=${encodeURIComponent(withWeek)}`,
    );
    assert.equal(familyRouteRedirect({ '7': slot(7) }, '7', true, withWeek), true);
    // An invalid week is not echoed into the sign-in query.
    assert.equal(familyRouteRedirect({}, '7', true, `${report}?week=2026-02-30`), '/family/7/sign-in');
});

test('the report page and the family route hand the emailed week on, and the page falls back to the current week', () => {
    const view = readFileSync(new URL('../views/family/FamilyWeeklyReport.vue', import.meta.url), 'utf8');
    const routes = readFileSync(new URL('../router/routes/familyRoutes.ts', import.meta.url), 'utf8');

    // The initial read asks for the week the link named, not always 'current'.
    assert.match(view, /loadWeek\(run, weekFromQuery\(route\.query\.week\) \?\? 'current'\)/);
    assert.doesNotMatch(view, /loadWeek\(run, 'current'\)/);
    // The route guard carries the week through sign-in.
    assert.match(routes, /familyReturnTarget\(to\.path, to\.query\.week\)/);
});
