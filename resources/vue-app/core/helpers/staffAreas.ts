/**
 * A login that works in two areas, as pure functions.
 *
 * One person can be Friday-lunch staff at one organisation and a teacher at
 * another, with one address and one password. The SERVER decides, at every
 * request, what that login is at the organisation in the URL; nothing here is a
 * permission. What the SPA has to get right is smaller:
 *
 *   - which `/user` to ask first on a page load, so a reload stays in the area
 *     the tab was in (the server answers each `/user` in that area's own voice:
 *     `type`, `masjid` and `memberships[]` are the area's);
 *   - which other places the switcher offers, and what it calls them;
 *   - where to start when the area in the address bar no longer has this login.
 *
 * The whole test for "this login works in two areas" is that its user payload
 * carries `areas`. The key is ABSENT for every login with one kind of role, for
 * administrators, and always while the server's switch is off, so every function
 * here answers "nothing to do" for them and they make the same requests, in the
 * same order, that they always made.
 *
 * Free of Vue, axios and the `@/` alias so it runs under `npm run test:spa`.
 * See docs/multi-tenant-admin-design.md, § SPA ("one login, a role at each organisation").
 */

/** An area a scoped staff login works in. The value is the API prefix (`/api/{area}/…`) and the shell's first URL segment. */
export type StaffArea = 'teacher' | 'lunch';

/** One entry of `areas[]` on the user payload, as the server sends it. */
export interface StaffAreaEntry {
    area: StaffArea;
    masjid_id: number;
    masjid?: { id?: number; name?: string | null; org_type?: string | null } | null;
}

/** One place the switcher can offer. */
export interface StaffPlace {
    area: StaffArea;
    masjidId: number;
    /** The organisation's name. Never blank. */
    name: string;
    /** The role and the organisation, as the menu prints them: "Friday lunch · Burlington Masjid". */
    label: string;
    /** Where that area's shell starts. */
    home: string;
}

/** What each area is called, in the words the office screens already use for the two roles. */
const AREA_NAMES: Record<StaffArea, string> = {
    teacher: 'Teacher',
    lunch: 'Friday lunch',
};

/** A value that may be an area name (a stored key, a URL segment), or null. */
export function asStaffArea(value: unknown): StaffArea | null {
    return value === 'teacher' || value === 'lunch' ? value : null;
}

/** The area a login of this kind is in. Null for an administrator, a member and anything unknown. */
export function areaOfKind(kind: unknown): StaffArea | null {
    if (kind === 'Teacher') return 'teacher';
    if (kind === 'LunchStaff') return 'lunch';

    return null;
}

/** The area a page address names (`/teacher/…`, `/lunch/…`), or null. */
export function areaOfPath(path: unknown): StaffArea | null {
    if (typeof path !== 'string') return null;

    return asStaffArea(path.split(/[?#]/)[0].split('/')[1]);
}

/** What the area is called. */
export function areaName(area: StaffArea): string {
    return AREA_NAMES[area];
}

/** Where the area's shell starts. */
export function areaHome(area: StaffArea): string {
    return `/${area}`;
}

/** The route that says who the login is IN this area. */
export function areaUserEndpoint(area: StaffArea): string {
    return `/api/${area}/user`;
}

/** The route that ends the session, asked through this area. */
export function areaLogoutEndpoint(area: StaffArea): string {
    return `/api/${area}/logout`;
}

/** Whether a request was a sign-out sent through one of the two areas, whatever host it was sent to. */
export function isAreaSignOut(url: unknown): boolean {
    if (typeof url !== 'string') return false;

    const path = url.replace(/^[a-z][a-z0-9+.-]*:\/\/[^/]+/i, '').split(/[?#]/)[0];

    return path === '/api/teacher/logout' || path === '/api/lunch/logout';
}

/**
 * The area to sign out through instead, when this shell's own area would not
 * take the sign-out; or null.
 *
 * `/logout` sits behind each area's gate, and the gate admits a login only while
 * it holds a role of that area's kind. So somebody whose role in THIS area has
 * just been taken away (or whose second area closed because the server's switch
 * was turned off) presses Sign out and is answered 401 by the gate: nothing is
 * deleted on the server, and the token is still their session in the other area,
 * for up to eight hours, behind a sign-in page that says they left. The other
 * area's gate still admits them, and its sign-out ends every session they have.
 *
 * A session that is already dead is also a 401, and there is nothing to end
 * anywhere: the framework says `Unauthenticated.` for it, and only that one answer
 * is left alone (the server side pins both bodies, OneLoginTwoRolesRealmTest). Any
 * other 401 is tried. A wrong guess costs one refused request; a missed one leaves
 * a session alive that its owner believes is over. A login with one kind of role
 * is never refused by its own area's gate, so it signs out with the one request
 * it always made.
 */
export function areaToSignOutThrough(area: unknown, refusal: unknown): StaffArea | null {
    const here = asStaffArea(area);
    if (here === null) return null;

    const response = (refusal as { response?: { status?: unknown; data?: { message?: unknown } | null } } | null | undefined)?.response;
    if (response?.status !== 401) return null;
    if (response.data?.message === 'Unauthenticated.') return null;

    return here === 'teacher' ? 'lunch' : 'teacher';
}

/** The area a `/user` route belongs to, or null (the admin one). */
export function areaOfEndpoint(url: unknown): StaffArea | null {
    const match = typeof url === 'string' ? /^\/api\/(teacher|lunch)\/user$/.exec(url) : null;

    return match ? asStaffArea(match[1]) : null;
}

/** Whether a request went to this area's API (`/api/{area}/…`), whatever host it was sent to. */
export function isAreaRequest(url: unknown, area: StaffArea): boolean {
    if (typeof url !== 'string') return false;

    const path = url.replace(/^[a-z][a-z0-9+.-]*:\/\/[^/]+/i, '');

    return path.startsWith(`/api/${area}/`);
}

/**
 * The places this login works, from `areas[]`. Absent, empty and malformed all
 * read as "none". An entry that cannot name an area and an organisation is
 * dropped: a menu row that cannot be opened is worse than no row. The server's
 * order (by organisation id) is kept, so the menu does not reorder itself.
 */
export function staffPlaces(areas: unknown): StaffPlace[] {
    if (!Array.isArray(areas)) return [];

    const places: StaffPlace[] = [];
    const seen = new Set<string>();

    for (const entry of areas as Array<Partial<StaffAreaEntry> | null | undefined>) {
        const area = asStaffArea(entry?.area);
        const masjidId = Number(entry?.masjid_id);
        if (area === null || !Number.isInteger(masjidId) || masjidId <= 0) continue;

        const key = `${area}:${masjidId}`;
        if (seen.has(key)) continue;
        seen.add(key);

        const raw = entry?.masjid?.name;
        // Never blank: a menu row must always be readable.
        const name = typeof raw === 'string' && raw.trim() !== '' ? raw.trim() : `Organisation #${masjidId}`;

        places.push({ area, masjidId, name, label: `${AREA_NAMES[area]} · ${name}`, home: areaHome(area) });
    }

    return places;
}

/** Whether the payload says this login works in two areas. False for every login with one kind of role. */
export function hasStaffAreas(areas: unknown): boolean {
    return staffPlaces(areas).length > 0;
}

/** The places in the area this shell is NOT: what the switcher offers. Nothing when the shell's own area is unknown. */
export function otherPlaces(areas: unknown, current: unknown): StaffPlace[] {
    const here = asStaffArea(current);
    if (here === null) return [];

    return staffPlaces(areas).filter((place) => place.area !== here);
}

/**
 * The place this shell is showing, for the switcher's spoken name: the entry of
 * this area at the selected organisation, else this area's only entry. Null when
 * `areas` does not say (several schools and none selected).
 */
export function currentPlace(areas: unknown, current: unknown, masjidId: unknown): StaffPlace | null {
    const here = asStaffArea(current);
    if (here === null) return null;

    const mine = staffPlaces(areas).filter((place) => place.area === here);
    const selected = Number(masjidId);

    return mine.find((place) => place.masjidId === selected) ?? (mine.length === 1 ? mine[0] : null);
}

/**
 * The order boot asks the `/user` routes in.
 *
 * `todays` is the order every login has always been asked in (admin, teacher,
 * lunch). With no area to put first it is returned as it is, which is every
 * login with one kind of role. Otherwise that area is asked first and the rest
 * follow in today's order.
 */
export function userEndpointOrder(todays: readonly string[], first: StaffArea | null): string[] {
    if (first === null) return [...todays];

    const mine = areaUserEndpoint(first);

    return [mine, ...todays.filter((url) => url !== mine)];
}

/**
 * The area boot asks first: the one in the address bar, else the one this
 * browser was last in. Null when no area is stored, whatever the address says:
 * the stored key exists only for a login that works in two areas, so without it
 * the order is today's.
 */
export function firstAreaToAsk(stored: unknown, path: unknown): StaffArea | null {
    const remembered = asStaffArea(stored);
    if (remembered === null) return null;

    return areaOfPath(path) ?? remembered;
}

/**
 * After the first `/user` that answered: the area whose `/user` must be asked
 * instead, or null.
 *
 * A lunch login that an office makes a teacher while it is signed in has a live
 * token and no stored area. Today's order then reaches `/api/teacher/user`
 * before `/api/lunch/user`, the teacher route now answers, and the page is
 * `/lunch`, which admits lunch staff only: without this she would reload her
 * lunch board into "401 Unauthorized". So when the answer carries `areas`, the
 * address names an area, that area is one of hers, and it is not the one that
 * answered (nor one that already refused in this pass), that area is asked and
 * its answer kept. A login with one kind of role has no `areas` and is never
 * asked twice.
 */
export function areaToAskInstead(
    answer: { areas?: unknown } | null | undefined,
    path: unknown,
    answered: StaffArea | null,
    asked: readonly string[],
): StaffArea | null {
    const wanted = areaOfPath(path);
    if (wanted === null || wanted === answered) return null;
    if (asked.includes(areaUserEndpoint(wanted))) return null;

    return staffPlaces(answer?.areas).some((place) => place.area === wanted) ? wanted : null;
}

/**
 * Where the router should start instead of the address in the bar, or null.
 *
 * `storedAtBoot` is the stored area as it was when the page began to load. Set,
 * it means this browser was in a two-area session; the address names one area
 * and ANOTHER one answered, so the role behind the address has been taken away
 * (or the server's switch was turned off). She is started in the area that still
 * has her. Without the stored key nothing moves: a teacher who types `/lunch`
 * sees the page she always saw.
 */
export function movedAreaHome(storedAtBoot: unknown, path: unknown, answeredKind: unknown): string | null {
    if (asStaffArea(storedAtBoot) === null) return null;

    const wanted = areaOfPath(path);
    const answered = areaOfKind(answeredKind);
    if (wanted === null || answered === null || wanted === answered) return null;

    return areaHome(answered);
}

/**
 * Start the router in the area that answered, when it is not the one in the
 * address bar (movedAreaHome). Returns where it went, or null when nothing moved.
 *
 * Called by main.ts BEFORE the router is installed, and awaited. Rewriting the
 * address bar would not do it: createWebHistory() read the address when the router
 * module was imported, and that is where the router's first navigation goes. Once
 * this navigation has finished the router is no longer at its start, so installing
 * it does not navigate again. A navigation that fails is reported and swallowed:
 * the app must still mount, on the address it was given.
 *
 * A function of its own so that the move is EXECUTED by a test. Inline in main.ts
 * it was only ever read as text, and deleting it changed no test: a person whose
 * role was removed would have been reloaded onto "401 Unauthorized".
 */
export async function startInAreaThatAnswered(
    router: { replace(to: string): Promise<unknown> },
    storedAtBoot: unknown,
    path: unknown,
    answeredKind: unknown,
    report: (...args: unknown[]) => void = () => {},
): Promise<string | null> {
    const movedTo = movedAreaHome(storedAtBoot, path, answeredKind);
    if (movedTo === null) return null;

    try {
        await router.replace(movedTo);
    } catch (error) {
        report('Error: \n', error);
    }

    return movedTo;
}
