/**
 * The teacher shell's school picker, as pure functions.
 *
 * A teacher can belong to several schools, and every teacher request names the
 * school in its URL (`/api/teacher/masjids/{id}/…`), which the server verifies
 * against the teacher's own memberships. So a selection here is only a CLAIM: it
 * comes from the server-granted `memberships[]` on `/api/teacher/user` and is
 * never guessed. These are the decisions the shell makes about that claim, kept
 * free of Vue and axios so they run under `npm run test:spa`:
 *
 *   - which schools the picker offers, and whether it renders at all;
 *   - which school to land in;
 *   - whether a 403 means "your school list changed" (refetch and reload) or is
 *     some other refusal;
 *   - whether the school the server bound is the one this tab selected;
 *   - whether a refresh-and-reload would be a loop.
 *
 * See docs/multi-tenant-admin-design.md §5 and the admin twin, tenantSwitchStore.
 */

/** The refusal ResolveMasjidTenant::FORBIDDEN_MESSAGE sends, verbatim. */
export const TENANT_FORBIDDEN_MESSAGE = 'You are not authorized to access this masjid.';

/** One school the picker can offer. */
export interface SchoolChoice {
    id: number;
    name: string;
    isDefault: boolean;
}

/** The parts of a `memberships[]` row this file reads. Everything else is ignored. */
interface MembershipLike {
    masjid_id?: unknown;
    is_default?: unknown;
    masjid?: { name?: unknown } | null;
}

/**
 * The schools this teacher may open, from `memberships[]`.
 *
 * Absent (an older backend) and empty (a teacher granted nothing) both read as
 * "nothing to pick between". A row without a usable id is dropped: an entry that
 * cannot name a school can only produce a 403. Sorted by name, so the menu does
 * not reorder itself when the default changes; ties by id.
 */
export function schoolChoices(memberships: unknown): SchoolChoice[] {
    if (!Array.isArray(memberships)) return [];

    const choices: SchoolChoice[] = [];
    const seen = new Set<number>();

    for (const row of memberships as MembershipLike[]) {
        const id = Number(row?.masjid_id);
        if (!Number.isInteger(id) || id <= 0 || seen.has(id)) continue;
        seen.add(id);

        const name = typeof row?.masjid?.name === 'string' && row.masjid.name.trim() !== ''
            ? row.masjid.name.trim()
            // Never blank: a menu row must always be readable.
            : `School #${id}`;

        choices.push({ id, name, isDefault: row?.is_default === true || row?.is_default === 1 });
    }

    return choices.sort((a, b) => a.name.localeCompare(b.name) || a.id - b.id);
}

/** The picker renders only with something to choose between: a one-school teacher sees no change. */
export function canPickSchool(choices: SchoolChoice[]): boolean {
    return choices.length > 1;
}

/**
 * The school to open on: the one this browser last used if the server still
 * grants it, else the default, else the lowest id. Null when there is nothing to
 * open. A stored id the server no longer grants (a removed school, another
 * person's id left in a shared browser) is never honoured.
 */
export function landingSchoolId(stored: unknown, choices: SchoolChoice[]): number | null {
    const wanted = Number(stored);
    if (Number.isInteger(wanted) && choices.some((choice) => choice.id === wanted)) return wanted;

    const fallback = choices.find((choice) => choice.isDefault)
        ?? [...choices].sort((a, b) => a.id - b.id)[0];

    return fallback ? fallback.id : null;
}

/**
 * The school a teacher lands in right after SIGNING IN.
 *
 * `browserStored` is what this browser last used (localStorage survives an expired
 * token, which sends the teacher to sign-in without signing them out). If the
 * server still grants that school it wins, so a two-school teacher who was working
 * in their second school is not thrown back to the default one by a 401. Otherwise
 * the login's own default (`user.masjid`), which is what sign-in always used. A
 * stored id the server does not grant, another person's left in a shared browser,
 * is never honoured (landingSchoolId).
 */
export function signInSchoolId(browserStored: unknown, memberships: unknown, defaultSchoolId: unknown): number | null {
    const landing = landingSchoolId(browserStored, schoolChoices(memberships));
    if (landing !== null) return landing;

    const own = Number(defaultSchoolId);

    return Number.isInteger(own) && own > 0 ? own : null;
}

/**
 * The id to switch to, or null when there is nothing to do: not one of the
 * granted schools (fail closed, the server would refuse it anyway) or already
 * the current one.
 */
export function switchTarget(current: unknown, target: unknown, choices: SchoolChoice[]): number | null {
    const id = Number(target);
    if (!Number.isInteger(id) || !choices.some((choice) => choice.id === id)) return null;

    return Number(current) === id ? null : id;
}

/**
 * Whether a refusal means the teacher's list of schools changed underneath them.
 *
 * The resolver answers a school outside the teacher's memberships with 403 and
 * exactly this message (and never says WHICH check refused). Other 403s exist in
 * this realm — `teacher.leads` ("You do not lead this class.") and
 * `teacher.teaches` — and are about a class, not a school; reloading on those
 * would loop.
 */
export function isOutsideMembershipsRefusal(status: unknown, message: unknown): boolean {
    return status === 403 && message === TENANT_FORBIDDEN_MESSAGE;
}

/**
 * Whether a refetch-and-reload is allowed now, or would be a loop.
 *
 * After a refusal the shell refetches `/teacher/user`, rehydrates the selection
 * and reloads. If the server keeps refusing the school it just chose (a bug, a
 * revoked account), reloading again forever helps nobody, so a second reload
 * inside the window is refused and the notice stays up instead.
 */
export function mayReloadAfterRefusal(lastReloadAtMs: unknown, nowMs: number, windowMs = 15000): boolean {
    const last = Number(lastReloadAtMs);
    if (!Number.isFinite(last) || last <= 0) return true;

    return nowMs - last > windowMs;
}

/**
 * The school id a response says the server bound, or null.
 *
 * `X-Tenant-Id` (EchoResolvedTenant): a positive integer, or the literal
 * `unbound`, which — like an absent header (a cross-origin deploy cannot read it,
 * an older backend does not send it) — is "no echo", never a disagreement.
 */
export function echoedSchoolId(headers: unknown): number | null {
    const bag: any = headers;
    const raw = typeof bag?.get === 'function'
        ? bag.get('x-tenant-id')
        : (bag?.['x-tenant-id'] ?? bag?.['X-Tenant-Id']);

    if (raw === null || raw === undefined || raw === '') return null;

    const id = Number(raw);

    return Number.isInteger(id) && id > 0 ? id : null;
}

/**
 * The server bound one school while this tab believes it is in another. Null in
 * every ordinary case: no echo, no selection, or both agree.
 */
export function schoolMismatch(echoed: number | null, selected: unknown): { server: number; selected: number } | null {
    const chosen = Number(selected);
    if (echoed === null || !Number.isInteger(chosen) || chosen <= 0 || echoed === chosen) return null;

    return { server: echoed, selected: chosen };
}
