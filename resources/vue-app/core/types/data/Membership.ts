import { Masjid } from "@/core/types/data/Masjid";

/**
 * One row of `masjid_user` as `/api/admin/user` serves it (S4 of
 * docs/multi-tenant-admin-design.md) — this human administers this organisation.
 *
 * **This array is a list of GRANTS, not a list of rows.** The server sends what
 * `App\Support\TenantResolver::grantsFor()` would bind, which is gated by
 * `config/tenancy.php`'s `multi_membership`. While that flag is false — which is
 * production today — every admin who can exist has exactly ONE grant, so
 * everything the SPA builds on this array (the switcher, the rehydrate check,
 * the mismatch notice) stays dark. A membership row naming any other
 * organisation is inert server-side: naming its masjid in a URL is the same 403
 * it was before the pivot existed, and a switcher that offered it would be
 * offering a tenant the resolver will refuse.
 *
 * Every consumer must tolerate the whole field being ABSENT: a build of this SPA
 * can be served by a backend that predates S4, and a missing `memberships` has
 * to read as "this principal switches nothing", never as "this principal has
 * nothing".
 */
export type Membership = {
    /** `masjid_user.id` — the membership, not the organisation. */
    id?: number;

    /**
     * The organisation this grant names. The ONE field the SPA cannot work
     * without: it is what a switch selects and what the server echoes back.
     */
    masjid_id: number;

    /**
     * `pivot.role`, ADVISORY ONLY (design § Roles). Authorization stays on the
     * global `users.type` bridge, so nothing here may gate a screen — it is
     * shown to the user and otherwise ignored.
     */
    role?: string | null;

    /** The one membership the resolver falls back to when nothing names an organisation. */
    is_default?: boolean;

    /**
     * The organisation itself, so the switcher can name and picture it. Only
     * `id` and `name` are relied on; a payload that omits the block falls back
     * to a readable placeholder rather than an empty menu row.
     */
    masjid?: Pick<Masjid, 'id' | 'name'> & Partial<Pick<Masjid, 'logo' | 'org_type'>> | null;
};

/**
 * The organisations this principal may act on, normalised.
 *
 * `memberships` absent (an older backend) and `memberships: []` (a principal the
 * server granted nothing) are different facts and are kept different: this
 * returns an empty array for both, and callers that need to tell them apart ask
 * `Array.isArray(user?.memberships)` themselves. Rows without a usable
 * `masjid_id` are dropped — a switcher entry that cannot name an organisation
 * can only produce a 403.
 */
export function grantedMemberships(memberships: Membership[] | undefined | null): Membership[] {
    if (!Array.isArray(memberships)) return [];

    return memberships.filter(membership => Number.isInteger(Number(membership?.masjid_id)));
}

/** The organisation's name for the chrome; never blank, so a menu row is always readable. */
export function membershipLabel(membership: Membership): string {
    return membership.masjid?.name || `Organisation #${membership.masjid_id}`;
}
