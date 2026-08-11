/**
 * The tenant's vertical as the ADMIN payload carries it (`Masjid::ADMIN_APPENDS`
 * → `Masjid::getVerticalAttribute()`, backed by `config/verticals.php`).
 *
 * Manara runs Masjids, Schools and Community organizations on one core, so the
 * admin SPA renders each tenant's own vocabulary instead of hardcoded masjid
 * words. The block is admin-only: the public/mobile API does not serve it, which
 * is why every consumer here has to tolerate its absence.
 */

export type OrgType = 'masjid' | 'school' | 'community';

/** The vertical every unknown or absent value degrades to, exactly as PHP's `Masjid::orgType()` does. */
export const DEFAULT_ORG_TYPE: OrgType = 'masjid';

/** Terminology keys `config/verticals.php` ships today. */
export type TerminologyKey =
    | 'organization'
    | 'members'
    | 'staff'
    | 'programs'
    | 'groups'
    | 'giving';

/**
 * A vertical's terminology pack.
 *
 * Deliberately partial: a pack served by an older backend may not carry a key
 * this build already renders, and that has to degrade to a readable label
 * rather than a blank one — see `useMasjidStore().term()`.
 */
export type Terminology = Partial<Record<TerminologyKey, string>>;

export type Vertical = {
    org_type: OrgType;
    label: string;
    plural: string;
    terminology: Terminology;
};

/**
 * Mirrors the `masjid` pack in `config/verticals.php`.
 *
 * Used when a payload carries no `vertical` block at all — a response cached
 * from before T-002, or an endpoint that does not append it — so an older
 * payload can never blank the UI of the tenants that already exist, all of
 * which are masjids. Keep in sync with the PHP config.
 */
export const MASJID_TERMINOLOGY: Record<TerminologyKey, string> = {
    organization: 'Masjid',
    members: 'Congregants',
    staff: 'Imams & Staff',
    programs: 'Programs',
    groups: 'Halaqat',
    giving: 'Donations',
};
