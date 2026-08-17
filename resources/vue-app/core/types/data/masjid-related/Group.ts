/**
 * Groups — the org -> group -> member scoping level of the core.
 *
 * ONE primitive, three verticals: a School's classroom, a Masjid's halaqa, a
 * Community org's volunteer team. Nothing in this file names the concept: what a
 * group is CALLED is the tenant's vocabulary, served as `meta.group_label` and
 * read in the SPA through `useMasjidStore().term('groups')`. See
 * .claude/rules/verticals.md and .claude/rules/groups.md.
 *
 * The literal unions below mirror `Group::KINDS`, `GroupMembership::ROLES` and
 * `GroupMembership::CONSENT_SCOPES`, which are PHP constants rather than DB
 * enums. They are STRUCTURAL names, never admin-facing labels — a `leader` is
 * "Teacher" in a school and "Ustadh" in a halaqa, and that is presentation.
 */

/** Mirrors `Group::KINDS`. */
export type GroupKind = 'general' | 'class' | 'halaqa' | 'team';

/** Mirrors `GroupMembership::ROLES`. */
export type GroupRole = 'leader' | 'member' | 'guardian';

/** Mirrors `GroupMembership::CONSENT_SCOPES`. `media` covers `feed`. */
export type ConsentScope = 'feed' | 'media';

/**
 * The contact a membership points at. Groups reference people, they never
 * duplicate them — this is the CRM `Contact` row, narrowed to the columns the
 * group endpoints eager-load.
 *
 * `email` and `phone` are NOT decoration. Two roster rows can carry the same two
 * names over the same child and be two different humans; the address is the only
 * rendered byte that separates them, and the roster tables draw it for exactly
 * that reason. Both are nullable — a contact created by an importer or a paper
 * form may hold neither, and the screen has to say so out loud rather than
 * render an empty cell.
 */
export type GroupContact = {
    id: number;
    first_name: string;
    last_name: string;
    email?: string | null;
    phone?: string | null;
};

/**
 * WHERE A PENDING CLAIM CAME FROM — `RosterClaimIdentity::origin()`.
 *
 * Every way the evidence can be missing has its own name, because "nothing" is
 * what the roster screen used to render and it is what made two claims over one
 * child indistinguishable:
 *
 *  - `confirmed` — staff stand behind the row; there is nothing left to judge.
 *  - `registration` — a public signup asserted it and the payer is on record.
 *  - `registration_payer_unavailable` — the signup is on record and its payer is
 *    not. A merge force-deletes an absorbed contact and `registrations.contact_id`
 *    is `nullOnDelete`, so an ordinary de-duplication degrades the one field the
 *    office is asked to judge.
 *  - `unrecorded` — nothing on record says where the claim came from: the signup
 *    was purged, or a merge dropped a staff-confirmed edge back to a claim.
 */
export type ClaimOriginState =
    | 'confirmed'
    | 'registration'
    | 'registration_payer_unavailable'
    | 'unrecorded';

export type ClaimOrigin = {
    state: ClaimOriginState;
    registration_id: number | null;
    payer: {
        id: number;
        first_name: string | null;
        last_name: string | null;
        email: string | null;
    } | null;
};

/**
 * THE DECISION-BEARING HALF OF A ROSTER ROW — served by the roster index, and
 * the thing that makes confirming a judgement rather than a count.
 *
 * `fingerprint` is echoed back on confirm so the server can tell that the row
 * being agreed to is still the row that was drawn (a merge re-points a pending
 * claim's `contact_id` and the id does not move). Null on a confirmed row.
 *
 * `contested` marks the shape rendering alone cannot fix: another guardian row
 * claims the SAME child under the SAME displayed name. Those are excluded from
 * the bulk sweep by the server and have to be decided one at a time.
 */
export type RosterClaim = {
    fingerprint: string | null;
    contested: boolean;
    rival_claim_ids: number[];
    origin: ClaimOrigin;
};

export type Group = {
    id: number;
    masjid_id: number;
    name: string;
    slug: string;
    kind: GroupKind;
    description: string | null;
    is_active: boolean;
    starts_on: string | null;
    ends_on: string | null;
    created_at: string;
    updated_at: string;
    deleted_at?: string | null;
    /**
     * Roster size an admin recognizes: `withCount` alias for PARTICIPANTS only,
     * so a parent linked to a child is not counted as a second student. Present
     * on the index endpoint, absent from show/store/update.
     */
    participants_count?: number;
};

/** Shape submitted by the create/edit form (server stamps masjid_id + timestamps). */
export type GroupPayload = {
    name: string;
    slug: string;
    kind: GroupKind;
    description: string;
    is_active: boolean;
    starts_on: string;
    ends_on: string;
};

/**
 * One roster row.
 *
 * `guardian_of_contact_id` + `guardianOf` are the explicit guardian EDGE: a
 * guardian row names the ward it is attached to, because `role = guardian` alone
 * cannot answer "may this adult see this child's record?" the moment a group
 * holds two children of one parent. One row = one (guardian, ward, group) edge.
 */
export type GroupMembership = {
    id: number;
    masjid_id: number;
    group_id: number;
    contact_id: number;
    role: GroupRole;
    guardian_of_contact_id: number | null;
    joined_at: string | null;
    /**
     * Consent lives on the guardian edge, and its ABSENCE means no consent. It is
     * never meaningful on a participant row — a member IS the person, and nobody
     * consents on their behalf.
     */
    consent_granted_at: string | null;
    consent_scope: ConsentScope | null;
    /**
     * ON WHOSE AUTHORITY THIS ROW EXISTS — mirrors `GroupMembership::PROVENANCES`.
     *
     * `confirmed` means a staff member stands behind it and it grants what a
     * roster row has always granted. `self_asserted` means a public registration
     * form claimed it, with no session and no proof of control of any address: it
     * lists the person and opens nothing until the office confirms it. Read
     * DEFENSIVELY here as everywhere — anything that is not exactly `confirmed`
     * is a pending claim, so a value this build does not know about renders as
     * unconfirmed rather than as a grant.
     */
    provenance: string;
    confirmed_at: string | null;
    confirmed_by_user_id: number | null;
    source_registration_id: number | null;
    created_at: string;
    updated_at: string;
    contact: GroupContact | null;
    /**
     * The ward this guardian edge points at; null on every participant row.
     *
     * SNAKE CASE, and this is not cosmetic. Eloquent's `$snakeAttributes` is on,
     * so `with('guardianOf')` serialises as `guardian_of`. This type declared
     * `guardianOf`, the roster template read `membership.guardianOf`, and the
     * "Guardian of" column therefore rendered `—` on every row — measured on the
     * wire. The screen that exists to answer "guardian of WHOM" was not naming
     * the child at all.
     */
    guardian_of: GroupContact | null;
    /** The staff member who confirmed it, when the endpoint eager-loads them. */
    confirmed_by?: { id: number; name: string } | null;
    /** The signup that asserted a claim; `sourceRegistration`, snake-cased. */
    source_registration?: {
        id: number;
        offering_id: number;
        contact_id: number | null;
    } | null;
    /**
     * Served by the roster index only. Optional in the type because `store()`
     * answers with a bare membership, and a screen must not read a missing
     * `claim` as a settled one.
     */
    claim?: RosterClaim;
};

/** Shape submitted when adding someone to a roster. */
export type GroupMembershipPayload = {
    contact_id: number;
    role: GroupRole;
    /** Required for a guardian row, prohibited on any other role. */
    guardian_of_contact_id: number | null;
    joined_at: string;
};

/**
 * `meta` on the groups endpoints. `group_label` is the tenant's word for a group
 * and the vocabularies ride along so the pickers render from the server's
 * constants instead of a second copy in TypeScript.
 */
export type GroupsMeta = {
    group_label: string;
    kinds: GroupKind[];
    roles: GroupRole[];
};
