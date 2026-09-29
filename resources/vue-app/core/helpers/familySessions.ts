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
 * A slot already stored for that school wins: it is newer than the legacy keys
 * by construction, since the legacy keys are no longer written.
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

        if (masjid && isMasjidKey(masjid) && isSlot(candidate) && !slots[masjid]) {
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

/**
 * The bearer token a request may carry: the token of the school its URL names,
 * and nothing otherwise. A public directory read, or an address that is not a
 * family route, gets none; so does a school the parent has not signed in to.
 */
export function tokenForUrl(storage: StorageLike, url: string | undefined | null): string | null {
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
): true | string {
    if (!requiresFamily) return true;

    return slots[String(masjidId)] ? true : `/family/${masjidId}/sign-in`;
}
