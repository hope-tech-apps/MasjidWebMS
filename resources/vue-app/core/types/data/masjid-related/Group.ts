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
 */
export type GroupContact = {
    id: number;
    first_name: string;
    last_name: string;
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
    created_at: string;
    updated_at: string;
    contact: GroupContact | null;
    /** The ward this guardian edge points at; null on every participant row. */
    guardianOf: GroupContact | null;
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
