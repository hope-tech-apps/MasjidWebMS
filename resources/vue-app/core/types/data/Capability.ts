import { OrgType } from "@/core/types/data/Vertical";

// Organisation capabilities — mirrors config/capabilities.php.
//
// Layer 1 of the access model: what an ORGANISATION has. A SuperAdmin decides;
// the organisation's administrators can use all of it. The catalogue holds two
// kinds of entry, and the SPA reads them from two different payload fields:
//
//   grant   Opt-in. The admin masjid payload carries `capabilities` (key -> has
//           it), and a key a payload lacks reads as "not had". Menu items and
//           routes use `requiresCapability` / `requiresAnyCapability`.
//
//   module  A screen an organisation has until a SuperAdmin switches it off. Most
//           are ON for every org type; a few (the masjid-only screens) are
//           offered to masjids only, and a SuperAdmin can switch one ON for a
//           school or community organisation (MODULE_DEFAULTS). The payload
//           carries `modules_off`, the offered modules a SuperAdmin switched off,
//           and `modules_on`, the not-offered modules a SuperAdmin switched on. A
//           key `modules_off` does not name — or a payload with no `modules_off`
//           at all, from an older backend — reads as ON. Menu items and routes
//           use `requiresModule`, NEVER `requiresCapability`: that check's strict
//           `=== true` would hide every default-on screen the moment the SPA
//           shipped ahead of the backend.
//
// A SuperAdmin is never gated by a grant or a module on the server; the menu
// shows them what the organisation has and lists the rest as switched off.
export type CapabilityKey = 'web_pages' | 'jummah_lunch' | 'crm' | 'assistant' | 'school_calendar' | 'form_editing';

/** The modules, in catalogue order (Masjid::MODULE_KEYS). CapabilityTsMirrorTest pins it. */
export const MODULE_KEYS = [
    'website',
    'announcements',
    'events',
    'about_us',
    'gallery',
    'push_notifications',
    'contact_requests',
    'programs',
    'zakat',
    'broadcasts',
    'flyer_studio',
    'impact_report',
    'prayer_times',
    'splash',
    'services',
    'donation_link',
    'giving',
    'properties',
    'appointment_requests',
] as const;

export type ModuleKey = typeof MODULE_KEYS[number];

/**
 * Which org types a module is offered to before a SuperAdmin decides — a copy of
 * Masjid::MODULE_DEFAULTS (and of each entry's `defaults` in
 * config/capabilities.php), in MODULE_KEYS order, one key per line.
 * CapabilityTsMirrorTest parses this block, so keep that layout.
 *
 * Reference only. Nothing in the SPA decides visibility from it: the payload's
 * `modules_off` / `modules_on` and the panel's `offered_by_default` are the
 * server's answer for one organisation, and an older backend that sends none of
 * them must render exactly today's screens.
 */
export const MODULE_DEFAULTS: Record<ModuleKey, Record<OrgType, boolean>> = {
    website: { masjid: true, school: true, community: true },
    announcements: { masjid: true, school: true, community: true },
    events: { masjid: true, school: true, community: true },
    about_us: { masjid: true, school: true, community: true },
    gallery: { masjid: true, school: true, community: true },
    push_notifications: { masjid: true, school: true, community: true },
    contact_requests: { masjid: true, school: true, community: true },
    programs: { masjid: true, school: true, community: true },
    zakat: { masjid: true, school: true, community: true },
    broadcasts: { masjid: true, school: true, community: true },
    flyer_studio: { masjid: true, school: true, community: true },
    impact_report: { masjid: true, school: true, community: true },
    prayer_times: { masjid: true, school: true, community: true },
    splash: { masjid: true, school: false, community: false },
    services: { masjid: true, school: false, community: false },
    donation_link: { masjid: true, school: false, community: false },
    giving: { masjid: true, school: false, community: false },
    properties: { masjid: true, school: false, community: false },
    appointment_requests: { masjid: true, school: true, community: true },
};

export type CapabilityInfo = {
    key: CapabilityKey;
    label: string;
    description: string;
    enabled: boolean;
};

/** A module switched off for an organisation, as the Team and user payloads name it. */
export type SwitchedOffScreen = {
    key: ModuleKey | string;
    label: string;
};

// Layer 2: what one person in the organisation can do.
//   admin         everything the organisation has
//   jummah_lunch  the Friday lunch board and nothing else
//   teacher       their own classes (managed on the Teachers screen)
export type TeamAccess = 'admin' | 'jummah_lunch' | 'teacher';

export type TeamMember = {
    user_id: number;
    name: string;
    email: string;
    phone: string | null;
    access: TeamAccess;
    is_owner: boolean;
    is_you: boolean;
    classes: number | null;
    last_sign_in_at: string | null;
    removable: boolean;
};

/** Human names for the catalogue keys (mirror config/capabilities.php labels). */
export const CAPABILITY_LABELS: Record<CapabilityKey, string> = {
    web_pages: 'Website pages',
    jummah_lunch: 'Friday lunch ordering',
    crm: 'Members, classes & giving',
    assistant: 'Manara Assistant',
    school_calendar: 'School calendar',
    form_editing: 'Edit sign-up forms',
};

/** One organisation a login belongs to, as the SuperAdmin's user screens see it. */
export type UserOrganisation = {
    masjid_id: number;
    name: string;
    access: TeamAccess | null;
    is_owner: boolean;
    /** Archived (soft-deleted): it grants nothing now, but still blocks an access change. */
    archived?: boolean;
    /** What that organisation has — only on the single-user payload. */
    capabilities?: CapabilityKey[];
    /** The modules switched off there — only on the single-user payload, and absent from an older backend. */
    modules_off?: SwitchedOffScreen[];
};

export type TeamPayload = {
    people: TeamMember[];
    capabilities: CapabilityInfo[];
    can_add: TeamAccess[];
    /** The modules switched off for this organisation. Absent from an older backend. */
    screens_off?: SwitchedOffScreen[];
};

// ---------------------------------------------------------------------------
// The SuperAdmin's switch panel: GET /api/admin/masjids/{id}/capabilities
// (MasjidsController::capabilities). Labels, descriptions and group order come
// from config/capabilities.php, never from this file.
// ---------------------------------------------------------------------------

/** Which endpoint flips an entry: PATCH capabilities/{key}, or the CRM / Assistant switch. */
export type CapabilityWriter = 'capability' | 'crm' | 'assistant';

export type CapabilityEntry = {
    key: CapabilityKey | ModuleKey | string;
    label: string;
    description: string;
    kind: 'grant' | 'module';
    writer: CapabilityWriter;
    enabled: boolean;
    /** What an organisation of this type has before a SuperAdmin overrides it; null for column-backed entries. */
    default_for_org_type: boolean | null;
    /** A SuperAdmin's explicit decision is stored for this organisation. */
    overridden: boolean;
    /** Active sections on active pages that show this module's data; null when no section type depends on it. */
    in_use: number | null;
    /**
     * Whether this organisation's type is offered the module before a SuperAdmin decides
     * (Masjid::MODULE_DEFAULTS); a `false` row is how the module gets switched ON for it.
     * Absent from an older backend, where every module is offered.
     */
    offered_by_default?: boolean | null;
    /**
     * For a module that lives on tabs of the Details screen rather than a sidebar item: the
     * tab names without the screen's (prayer_times). The panel prints
     * "{Details sidebar title} › {where}". A module needs a sidebar item or a `where`, or its
     * row cannot be placed.
     */
    where?: string | null;
    /** What is live for this organisation right now, as sentences (App\Support\ModuleFacts). */
    facts?: string[];
};

export type CapabilityGroup = {
    key: string;
    label: string;
    entries: CapabilityEntry[];
};

export type CapabilityChange = {
    id: number;
    capability: string;
    label: string;
    enabled_before: boolean;
    enabled_after: boolean;
    override_before: boolean | null;
    /** Null once the user who made the change is gone. */
    actor_name: string | null;
    created_at: string;
};

export type OrganisationCapabilities = {
    /**
     * `donation_link_set`: whether the apps have a donation link to show on Donate while
     * Giving is off (they say "No donation options are available right now" without one).
     * Absent from an older backend.
     */
    org: { id: number; name: string; org_type: string; donation_link_set?: boolean };
    groups: CapabilityGroup[];
    history: CapabilityChange[];
};
