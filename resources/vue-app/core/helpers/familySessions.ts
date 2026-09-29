/**
 * The parent portal's sessions, one per school.
 *
 * A parent can have a Contact at two schools. Those are two identities that
 * share nothing (DECISIONS.md:2323-2336: family identities stay per
 * organisation), so signing in to school B must not replace school A's session,
 * and a request for school A must never carry school B's token.
 *
 * This module is the whole of that rule, kept free of axios, pinia and the `@/`
 * alias so `npm run test:spa` can run it under plain node:
 *
 *   - `MANARA_FAMILY_SESSIONS` holds `{ "<masjidId>": { token, contact } }`.
 *     Each school is its own slot; writing or dropping one leaves the rest.
 *   - The request interceptor asks `tokenForUrl` which token a URL may carry.
 *     The answer comes from the school id inside the URL, never from "the
 *     session that signed in last", so switching schools cannot send the wrong
 *     token: there is no ambient current token to send.
 *   - The old single-session triple (MANARA_FAMILY_TOKEN, _CONTACT,
 *     _MASJID_ID) is adopted into its slot once and then removed, so a parent
 *     already signed in when this ships is not signed out.
 *
 * Storage is passed in, and read fresh on every call: the portal can be open in
 * two tabs, and the second tab's sign-in has to be visible to the first tab's
 * next request.
 */

export interface FamilyContact {
    id: number;
    masjid_id: number;
    first_name: string | null;
    last_name: string | null;
    login_email: string | null;
}

export interface FamilySlot {
    token: string;
    contact: FamilyContact;
}

/** Keyed by school id as a string of digits. */
export type FamilySlots = Record<string, FamilySlot>;

export type StorageLike = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

export const FAMILY_SESSIONS_KEY = 'MANARA_FAMILY_SESSIONS';

/** The single-session keys this replaced. Read once for migration, then removed. */
export const LEGACY_FAMILY_KEYS = {
    token: 'MANARA_FAMILY_TOKEN',
    contact: 'MANARA_FAMILY_CONTACT',
    masjid: 'MANARA_FAMILY_MASJID_ID',
};

const isSlot = (v: any): v is FamilySlot =>
    !!v && typeof v.token === 'string' && v.token !== '' && !!v.contact && typeof v.contact === 'object';

const isMasjidKey = (k: string) => /^\d+$/.test(k);

/** Whatever is stored, minus anything that is not a well-formed slot. */
function parseSlots(raw: string | null): FamilySlots {
    if (!raw) return {};

    try {
        const parsed = JSON.parse(raw);
        const out: FamilySlots = {};

        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
            for (const [id, slot] of Object.entries(parsed)) {
                if (isMasjidKey(id) && isSlot(slot)) out[id] = slot;
            }
        }

        return out;
    } catch {
        return {};
    }
}

function safeGet(storage: StorageLike, key: string): string | null {
    try {
        return storage.getItem(key);
    } catch {
        return null;
    }
}

function writeSlots(storage: StorageLike, slots: FamilySlots): void {
    if (Object.keys(slots).length === 0) {
        storage.removeItem(FAMILY_SESSIONS_KEY);
        return;
    }

    storage.setItem(FAMILY_SESSIONS_KEY, JSON.stringify(slots));
}

/**
 * Move a legacy single session into its slot, then remove the legacy keys.
 *
 * When a slot is already stored for that school, the legacy keys are still
 * adopted if their token differs from it. The migration removes the legacy keys
 * and this bundle never writes them, so a legacy triple that is present again
 * was written since — by a tab still running the previous bundle, signing in
 * again (after a revoked token, say). That sign-in is newer than the stored
 * slot; keeping the slot would sign the next request with the revoked token and
 * bounce the parent to sign-in. The same token needs no rewrite.
 */
function adoptLegacy(storage: StorageLike, slots: FamilySlots): FamilySlots {
    const token = safeGet(storage, LEGACY_FAMILY_KEYS.token);
    const contactRaw = safeGet(storage, LEGACY_FAMILY_KEYS.contact);
    const masjid = safeGet(storage, LEGACY_FAMILY_KEYS.masjid);

    if (token === null && contactRaw === null && masjid === null) return slots;

    let next = slots;

    try {
        const contact = contactRaw ? JSON.parse(contactRaw) : null;
        const candidate = { token: token ?? '', contact };

        if (masjid && isMasjidKey(masjid) && isSlot(candidate) && slots[masjid]?.token !== candidate.token) {
            next = { ...slots, [masjid]: candidate };
            writeSlots(storage, next);
        }
    } catch {
        /* an unreadable legacy contact is a session that cannot be used */
    }

    try {
        storage.removeItem(LEGACY_FAMILY_KEYS.token);
        storage.removeItem(LEGACY_FAMILY_KEYS.contact);
        storage.removeItem(LEGACY_FAMILY_KEYS.masjid);
    } catch {
        /* storage refusing removal: the next read tries again */
    }

    return next;
}

/** Every stored session, migrating a legacy one on the way. */
export function readSlots(storage: StorageLike): FamilySlots {
    return adoptLegacy(storage, parseSlots(safeGet(storage, FAMILY_SESSIONS_KEY)));
}

/**
 * Store one school's session. Reads storage first, so a session another tab
 * added since this page loaded is kept, not overwritten.
 */
export function putSlot(storage: StorageLike, masjidId: string | number, slot: FamilySlot): FamilySlots {
    const id = String(masjidId);

    if (!isMasjidKey(id) || !isSlot(slot)) {
        throw new Error('A family session needs a school id, a token and a contact.');
    }

    const next = { ...readSlots(storage), [id]: slot };
    writeSlots(storage, next);

    return next;
}

/** Drop one school's session and leave the others alone. */
export function dropSlot(storage: StorageLike, masjidId: string | number): FamilySlots {
    const id = String(masjidId);
    const { [id]: _gone, ...rest } = readSlots(storage);
    writeSlots(storage, rest);

    return rest;
}

const FAMILY_URL = /^(?:https?:\/\/[^/]+)?\/api\/family\/masjids\/(\d+)(?=[/?#]|$)/i;

/** The school a family API URL addresses, or null for any other URL. */
export function masjidIdOfUrl(url: string | undefined | null): string | null {
    const m = typeof url === 'string' ? FAMILY_URL.exec(url) : null;

    return m ? m[1] : null;
}

/** The origin of an absolute http(s) URL, or null for anything else. */
export function originOf(value: string | undefined | null): string | null {
    if (typeof value !== 'string' || !/^https?:\/\//i.test(value)) return null;

    try {
        return new URL(value).origin;
    } catch {
        return null;
    }
}

/**
 * Whether a URL points at the portal's own server. A path (`/api/...`) is
 * relative to it by construction; an absolute URL is the portal's only when its
 * PARSED origin equals `portalOrigin`. Parsed, not compared as text: a prefix
 * test calls `https://app.example.org.evil.com/...` and
 * `https://app.example.org@evil.com/...` local when the portal is
 * `https://app.example.org`. When the portal's origin is not known, no absolute
 * URL is local.
 */
function isPortalUrl(url: string, portalOrigin: string | null): boolean {
    if (url.startsWith('/') && !url.startsWith('//')) return true;

    const origin = originOf(url);

    return origin !== null && portalOrigin !== null && origin === portalOrigin;
}

/**
 * The bearer token a request may carry: the token of the school its URL names,
 * and nothing otherwise. A public directory read, or an address that is not a
 * family route, gets none; so does a school the parent has not signed in to; so
 * does any absolute URL that is not on `portalOrigin`, however family-shaped its
 * path, so a URL handed to the client by a response can never take a token off
 * the portal's own server.
 */
export function tokenForUrl(
    storage: StorageLike,
    url: string | undefined | null,
    portalOrigin: string | null = null,
): string | null {
    if (typeof url !== 'string' || !isPortalUrl(url, portalOrigin)) return null;

    const id = masjidIdOfUrl(url);

    return id ? (readSlots(storage)[id]?.token ?? null) : null;
}

/**
 * The school whose session a failed request should end: the one the request
 * addressed, read off the request itself. Not "the school on screen" — a
 * response can arrive after the parent has switched schools, and it must end the
 * school it came from, not the one they are looking at now. `fallback` is used
 * only when the error carries no family URL.
 */
export function authFailureMasjidId(error: any, fallback: string | number): string | null {
    const status = error?.response?.status;

    if (status !== 401 && status !== 403) return null;

    return masjidIdOfUrl(error?.config?.url) ?? String(fallback);
}

/**
 * Where a family route sends the visitor: `true` to stay, or the sign-in path
 * for the school in the URL. Only routes marked `meta.family` need a session,
 * and it must be a session for THAT school.
 */
export function familyRouteRedirect(
    slots: FamilySlots,
    masjidId: string | number,
    requiresFamily: boolean,
    intended?: string,
): true | string {
    if (!requiresFamily) return true;

    if (slots[String(masjidId)]) return true;

    // A parent who opened a link to one specific page (the weekly report) and has no
    // session is sent to sign in and then back to it. Only the pages FAMILY_NEXT_PAGES
    // names are carried; anything else is dropped, never echoed into the query.
    const next = intended !== undefined && familyNextPath(masjidId, intended) === intended ? intended : null;

    return next ? `/family/${masjidId}/sign-in?next=${encodeURIComponent(next)}` : `/family/${masjidId}/sign-in`;
}

/** 'YYYY-MM-DD' that is a real calendar day. (Helpers import nothing, so `node --test` can load each alone.) */
function isRealIsoDay(iso: string): boolean {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
    if (!m) return false;

    const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));

    return d.getUTCFullYear() === Number(m[1]) && d.getUTCMonth() === Number(m[2]) - 1 && d.getUTCDate() === Number(m[3]);
}

/**
 * The path a signed-out visitor to the weekly report is to be sent back to after sign-in: the
 * report's own path plus the ONE query it may carry, `?week=YYYY-MM-DD` (the week the Friday
 * email reported), when that is a real date. Any other query is dropped, never carried.
 */
export function familyReturnTarget(path: string, week: unknown): string {
    return typeof week === 'string' && isRealIsoDay(week) ? `${path}?week=${week}` : path;
}

/**
 * The pages a sign-in may hand a parent on to. ONE: the printable weekly report the Friday
 * email links to (T-003.3), optionally with `?week=YYYY-MM-DD` naming the week the email
 * reported (a link opened on the Sunday after must still show that week, not the new one).
 * It is an allowlist of PATH SHAPES for THIS school on purpose: `?next=` is user-controlled
 * input, and a redirect target read straight from a query is an open redirect and a way to
 * land a parent on another school's screen with this one's session. The single query shape
 * allowed is a real calendar date; nothing else is ever accepted, so nothing else needs to
 * be checked.
 */
export function familyNextPath(masjidId: string | number, next: unknown): string {
    const home = `/family/${masjidId}`;

    if (typeof next !== 'string') return home;

    const m = /^\/family\/(\d+)\/classes\/(\d+)\/report(?:\?week=(\d{4}-\d{2}-\d{2}))?$/.exec(next);

    if (!m || m[1] !== String(masjidId)) return home;
    if (m[3] !== undefined && !isRealIsoDay(m[3])) return home;

    return next;
}
