// Organisation capabilities — mirrors config/capabilities.php.
//
// Layer 1 of the access model: what an ORGANISATION has. A SuperAdmin decides;
// the organisation's administrators can use all of it. The admin masjid payload
// carries `capabilities` (key -> has it). A key a payload lacks reads as "not
// had", and a SuperAdmin is never gated by one — they set organisations up.
export type CapabilityKey = 'web_pages' | 'jummah_lunch' | 'crm' | 'assistant';

export type CapabilityInfo = {
    key: CapabilityKey;
    label: string;
    description: string;
    enabled: boolean;
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
};

/** One organisation a login belongs to, as the SuperAdmin's user screens see it. */
export type UserOrganisation = {
    masjid_id: number;
    name: string;
    access: TeamAccess | null;
    is_owner: boolean;
    /** What that organisation has — only on the single-user payload. */
    capabilities?: CapabilityKey[];
};

export type TeamPayload = {
    people: TeamMember[];
    capabilities: CapabilityInfo[];
    can_add: TeamAccess[];
};
