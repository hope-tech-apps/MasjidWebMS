// One organisation web address, as the SuperAdmin domain routes return it
// (Manara Studio W1, S7): GET/POST /api/admin/masjids/{masjid_id}/domains,
// POST .../{domain_id}/refresh and DELETE .../{domain_id}.
//
// Mirrors App\Models\MasjidDomain::toAdminArray(). Everything a screen needs to
// decide is computed on the server and read here as it arrives: `live_url`
// exists only once a probe has seen our site answer on the host (R24), and
// `manual_steps` are Studio's instructions, never restated in the SPA.

/** MasjidDomain::STATUSES */
export type MasjidDomainStatus =
    | 'pending'
    | 'awaiting_nameservers'
    | 'provisioning'
    | 'active'
    | 'manual'
    | 'failed'
    | 'reserved';

/** MasjidDomain::WAITING_ON */
export type MasjidDomainWaitingOn = 'token' | 'token_scope' | 'nameservers' | 'certificate' | 'capacity';

export type MasjidDomainKind = 'managed_subdomain' | 'custom';

export interface MasjidDomain {
    id: number;
    masjid_id: number;
    host: string;
    kind: MasjidDomainKind;
    zone_apex: string;
    status: MasjidDomainStatus;
    waiting_on: MasjidDomainWaitingOn | null;
    source: 'studio' | 'imported';
    nameservers: string[] | null;
    last_error: string | null;
    last_checked_at: string | null;
    next_check_at: string | null;
    verified_at: string | null;
    verified_by: 'cloudflare' | 'probe' | null;
    serving_confirmed_at: string | null;
    /** https://<host>, only once our site was seen answering on it. */
    live_url: string | null;
    manual_steps: string[];
    /** Whether DELETE would answer 204 rather than 409 (R28). */
    deletable: boolean;
}

/** What the list says about the platform's Cloudflare connection. */
export interface MasjidDomainsCloudflare {
    configured: boolean;
    pages_project: string;
    /** Null without a token, or when Cloudflare could not be read: "not known". */
    pages_domains_used: number | null;
    pages_domains_ceiling: number;
}

export interface MasjidDomainsPanel {
    cloudflare: MasjidDomainsCloudflare;
    domains: MasjidDomain[];
}

/** The body of POST .../domains and of POST /api/admin/studio/domains/check. */
export type MasjidDomainRequest =
    | { kind: 'managed_subdomain'; label: string }
    | { kind: 'custom'; host: string; zone_apex: string };

/** POST /api/admin/studio/domains/check. `zone_status` is present only with a token. */
export interface MasjidDomainCheck {
    host: string;
    available: boolean;
    taken_by_masjid_id: number | null;
    case: 'managed_subdomain' | 'zone_in_account' | 'zone_not_in_account' | 'unknown';
    zone_status?: string | null;
    token_configured: boolean;
    pages_domains_used: number | null;
    pages_domains_ceiling: number;
}

/**
 * The only two states the panel may show a green tick for: Cloudflare verified
 * the host and our site was seen on it, or the probe itself confirmed it with
 * no token. Anything else is not live, whatever else it says.
 */
export function isConfirmedServing(domain: MasjidDomain): boolean {
    return (domain.status === 'active' && domain.serving_confirmed_at !== null)
        || (domain.status === 'manual' && domain.verified_at !== null);
}
