/**
 * The parent portal keeps one session per school (core/helpers/familySessions.ts).
 * A parent with children at two schools signs in to both; neither sign-in may
 * replace the other, and a request may carry only the token of the school its
 * URL names.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    FAMILY_SESSIONS_KEY,
    LEGACY_FAMILY_KEYS,
    authFailureMasjidId,
    dropSlot,
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
    assert.equal(tokenForUrl(s, 'https://school.example.test/api/family/masjids/7/me'), 'tok-7');
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

test('a legacy session does not overwrite a slot already stored for that school', () => {
    const s = memoryStorage({
        [FAMILY_SESSIONS_KEY]: JSON.stringify({ '7': slot(7, 'newer') }),
        [LEGACY_FAMILY_KEYS.token]: 'legacy-tok',
        [LEGACY_FAMILY_KEYS.contact]: JSON.stringify(contact(7)),
        [LEGACY_FAMILY_KEYS.masjid]: '7',
    });

    assert.equal(readSlots(s)['7'].token, 'newer');
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
