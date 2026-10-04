/**
 * What the office's "Student details" panel says about one student, worked out from the
 * roster the page already holds. No request is made for it: a student's guardians are the
 * roster's own guardian entries that name that student.
 *
 * The rule this file exists for: ONLY AN ENTRY THE SCHOOL STANDS BEHIND, AND THAT IS STILL
 * IN THE CLASS, IS OFFERED AS SOMEONE TO CALL. A guardian entry a public registration form
 * wrote is a claim typed by somebody who was not signed in, so it is listed by name and
 * address as plain text and never as a tap-to-call or email link: on a busy morning the
 * first number the office taps must not be an unverified claimant's. An entry that has left
 * the class is listed by name only. A student who has left the class shows no link at all.
 *
 * So the three lists are built here, once, and the panel draws them: the screen holds no
 * second copy of "which rows may be called".
 *
 * Only relative imports: this file runs under `npm run test:spa` (node, no `@/` alias).
 */

/** The person a roster row points at, narrowed to what the panel reads. */
export type RosterPerson = {
    id?: number;
    first_name?: string | null;
    last_name?: string | null;
    email?: string | null;
    phone?: string | null;
};

/** One roster row, narrowed to what the panel reads. `GroupMembership` fits it. */
export type RosterRow = {
    id: number;
    group_id?: number;
    contact_id: number;
    role: string;
    guardian_of_contact_id: number | null;
    left_on: string | null;
    provenance: string;
    consent_scope?: string | null;
    consent_granted_at?: string | null;
    contact?: RosterPerson | null;
};

/** A confirmed guardian who is still in the class: the only kind with links. */
export type ReachableGuardian = {
    id: number;
    name: string;
    /** The number as the office typed it, or null when none is on file. */
    phone: string | null;
    /** `tel:` link, or null when there is no number or it holds no digit to dial. */
    phoneHref: string | null;
    email: string | null;
    /** `mailto:` link, or null when there is no address or it is not one plain address. */
    emailHref: string | null;
    /** The consent state, in the roster table's own words. */
    consent: string;
    /** The day the consent was given (YYYY-MM-DD), when one is recorded. */
    consentDay: string | null;
};

/** A claim from a registration form. Text only: the type has no link to draw. */
export type UnconfirmedGuardian = { id: number; name: string; address: string };

/** A guardian entry that has left the class. Name and day only. */
export type DepartedGuardian = { id: number; name: string; leftOn: string };

/** A guardian named beside a student who has left. Name only. */
export type FormerGuardian = { id: number; name: string; confirmed: boolean };

export type StudentDetails = {
    /** The student's own row carries a leaving date. */
    studentHasLeft: boolean;
    confirmed: ReachableGuardian[];
    unconfirmed: UnconfirmedGuardian[];
    departed: DepartedGuardian[];
    /** Filled ONLY when the student has left, and then the three lists above are empty. */
    former: FormerGuardian[];
};

export const NO_PHONE = 'no phone on file';
export const NO_EMAIL = 'no email on file';
export const NO_ADDRESS = 'no address on file';

const text = (value: string | null | undefined): string => (typeof value === 'string' ? value.trim() : '');

/** "First Last", or a dash when the row has lost its person. */
export function personName(person: RosterPerson | null | undefined): string {
    if (!person) return '—';
    return `${text(person.first_name)} ${text(person.last_name)}`.trim() || '—';
}

/**
 * Has anybody at the organisation stood behind this row? Read the way the roster reads it:
 * anything that is not exactly `confirmed` is a claim, so a value this build does not know
 * is listed as unconfirmed and never as someone to call.
 */
export function isConfirmedRow(row: RosterRow): boolean {
    return row.provenance === 'confirmed';
}

/** A student is a `member` row. A guardian entry and a legacy `leader` row are not. */
export function isStudentRow(row: RosterRow | null | undefined): boolean {
    return row?.role === 'member';
}

/**
 * The student row a guardian entry points at, so the child's name in the "Guardian of"
 * cell opens the same panel. Null when the roster holds no student row for that person.
 */
export function studentRowFor<T extends RosterRow>(roster: readonly T[], contactId: number | null | undefined): T | null {
    if (contactId === null || contactId === undefined) return null;
    return roster.find(row => isStudentRow(row) && row.contact_id === contactId) ?? null;
}

/**
 * A `tel:` link for a number, or null. Keeps the digits and one leading plus, which is
 * what a dialler takes; a value with no digit in it ("ask at the desk") is not a number
 * and gets no link.
 */
export function dialHref(phone: string | null | undefined): string | null {
    const raw = text(phone);
    if (raw === '') return null;

    const digits = raw.replace(/\D/g, '');
    if (digits === '') return null;

    return `tel:${raw.startsWith('+') ? '+' : ''}${digits}`;
}

/**
 * A `mailto:` link for ONE plain address, or null. Anything that could carry a second
 * recipient or a header (a space, a comma, `?`, `&`) is shown as text instead of linked.
 */
export function mailHref(email: string | null | undefined): string | null {
    const raw = text(email);
    if (!/^[^\s@?&,;:<>"%]+@[^\s@?&,;:<>"%]+$/.test(raw)) return null;

    return `mailto:${raw}`;
}

/** The consent state as the roster's guardians table words it. Absence is "Not given". */
export function consentWords(scope: string | null | undefined): string {
    if (scope === 'media') return 'Photos & notes';
    if (scope === 'feed') return 'Notes only';
    return 'Not given';
}

/** A stored calendar day, read off the string and never through a timezone. */
export function storedDay(iso: string | null | undefined): string {
    return text(iso).slice(0, 10);
}

/**
 * A stored day for reading ("Sep 5, 2026"). The parts are read off the string, because
 * `new Date('2026-09-05T00:00:00Z')` draws the 4th for everyone west of UTC.
 */
export function storedDayLabel(iso: string | null | undefined, locale?: string): string {
    const day = storedDay(iso);
    const [y, m, d] = day.split('-').map(Number);
    if (!y || !m || !d) return day || '—';

    return new Date(y, m - 1, d).toLocaleDateString(locale, { year: 'numeric', month: 'short', day: 'numeric' });
}

/**
 * The panel's guardian lists for one student row.
 *
 * Every guardian entry that names this student lands in exactly one list:
 *   - the entry has a leaving date ................. `departed` (name, day)
 *   - not confirmed, still in the class ............ `unconfirmed` (name, address as text)
 *   - confirmed, still in the class ................ `confirmed` (the only list with links)
 * and when the student's own row has left, all of them go to `former` (names only) and the
 * three lists are empty, so nothing about a student who is gone can be tapped.
 *
 * A guardian of a sibling in the same class is not listed: the entry names its child.
 */
export function studentDetails(roster: readonly RosterRow[], student: RosterRow): StudentDetails {
    const entries = roster.filter(row =>
        row.role === 'guardian'
        && row.guardian_of_contact_id === student.contact_id
        && (row.group_id === undefined || student.group_id === undefined || row.group_id === student.group_id));

    const details: StudentDetails = {
        studentHasLeft: text(student.left_on) !== '',
        confirmed: [],
        unconfirmed: [],
        departed: [],
        former: [],
    };

    for (const entry of entries) {
        const name = personName(entry.contact);

        if (details.studentHasLeft) {
            details.former.push({ id: entry.id, name, confirmed: isConfirmedRow(entry) });
        } else if (text(entry.left_on) !== '') {
            details.departed.push({ id: entry.id, name, leftOn: storedDay(entry.left_on) });
        } else if (!isConfirmedRow(entry)) {
            const address = text(entry.contact?.email) || text(entry.contact?.phone) || NO_ADDRESS;
            details.unconfirmed.push({ id: entry.id, name, address });
        } else {
            const phone = text(entry.contact?.phone) || null;
            const email = text(entry.contact?.email) || null;

            details.confirmed.push({
                id: entry.id,
                name,
                phone,
                phoneHref: dialHref(phone),
                email,
                emailHref: mailHref(email),
                consent: consentWords(entry.consent_scope),
                consentDay: entry.consent_scope && entry.consent_granted_at ? storedDay(entry.consent_granted_at) : null,
            });
        }
    }

    return details;
}

/**
 * The Member Directory deep link: `?contact={id}`.
 *
 * The id is read from an address anybody can type, so only a plain positive whole number
 * is taken: not "5abc", not "1e3", not a negative, not a list of values.
 */
export const CONTACT_QUERY_KEY = 'contact';

export function contactIdFromQuery(value: unknown): number | null {
    if (typeof value !== 'string' || !/^[1-9]\d{0,15}$/.test(value)) return null;

    const id = Number(value);
    return Number.isSafeInteger(id) ? id : null;
}

/** The query "Open full record" navigates with. */
export function fullRecordQuery(contactId: number): Record<string, string> {
    return { [CONTACT_QUERY_KEY]: String(contactId) };
}

/** The same query with the deep link taken out, so a refresh does not open the record again. */
export function withoutContactQuery<T extends Record<string, unknown>>(query: T): Omit<T, typeof CONTACT_QUERY_KEY> {
    const { [CONTACT_QUERY_KEY]: _dropped, ...rest } = query;
    return rest;
}

/** What the Member Directory says when a linked record cannot be shown. */
export const RECORD_NOT_OPENED = 'That record could not be opened.';

/** The four things the screen does around a linked record. Handed in, so the order below is the only order. */
export type LinkedRecordSteps<T> = {
    /** Ask the server for the record. Null, or a throw, means it cannot be shown. */
    fetch: (id: number) => Promise<T | null | undefined>;
    /** Take the id out of the address. */
    dropLink: () => void;
    /** Show the record the server returned. */
    open: (record: T) => void | Promise<void>;
    /** Say that it could not be opened. */
    refuse: () => void;
    /** Is the screen that asked still the one on show? Absent means yes. */
    stillHere?: () => boolean;
};

/**
 * Open one person's record from a link, FETCH FIRST.
 *
 * A link carries only an id, and the id is whatever somebody typed into an address bar:
 * it can name a record that was deleted, or another organisation's (a 404 by design). So
 * nothing is shown until the server has answered, and what is shown is what it returned.
 * A refusal or a failure of any kind opens nothing and says one sentence.
 *
 * Either way the id then leaves the address, so a refresh neither opens the record again
 * nor repeats the refusal. If the office left the screen while the answer was on its way,
 * nothing at all happens: the answer belongs to a page that is no longer there.
 *
 * Resolves true when the record was opened.
 */
export async function openLinkedRecord<T>(id: number, steps: LinkedRecordSteps<T>): Promise<boolean> {
    let record: T | null = null;
    try {
        record = (await steps.fetch(id)) ?? null;
    } catch {
        record = null;
    }

    if (steps.stillHere && !steps.stillHere()) return false;

    steps.dropLink();

    if (record === null) {
        steps.refuse();
        return false;
    }

    await steps.open(record);
    return true;
}
