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
//   module  Default ON for every org type. The payload carries `modules_off`,
//           the modules a SuperAdmin switched off; a key it does not name — or a
//           payload with no `modules_off` at all, from an older backend — reads
//           as ON. Menu items and routes use `requiresModule`, NEVER
//           `requiresCapability`: that check's strict `=== true` would hide every
//           default-on screen the moment the SPA shipped ahead of the backend.
//
// A SuperAdmin is never gated by a grant or a module on the server; the menu
// shows them what the organisation has and lists the rest as switched off.
export type CapabilityKey = 'web_pages' | 'jummah_lunch' | 'crm' | 'assistant' | 'school_calendar' | 'form_editing';

/** The default-on modules, in catalogue order (Masjid::MODULE_KEYS). */
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
] as const;

export type ModuleKey = typeof MODULE_KEYS[number];

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
    org: { id: number; name: string; org_type: string };
    groups: CapabilityGroup[];
    history: CapabilityChange[];
};
