/**
 * Manara Studio's wire shapes, typed from the controllers that serve them
 * (docs/manara-studio-w1.md S1-S5, S8). Each type names its source so a change on
 * the server has one obvious place to follow it here.
 *
 * The answer sections mirror `UpdateStudioDraftRequest::sectionRules()`
 * (app/Http/Requests/Admin/Studio/UpdateStudioDraftRequest.php): every key a
 * section may hold is listed, because the server refuses any key it does not
 * know rather than storing it where nothing reads it.
 */
import type { MasjidDomain, MasjidDomainCheck, MasjidDomainRequest } from "@/core/types/data/MasjidDomain";
import type { OrgType, Terminology } from "@/core/types/data/Vertical";

/** `StudioDraft::STEPS` (app/Models/StudioDraft.php). */
export type StudioStepKey = 'foundation' | 'features' | 'layout' | 'generate';

/** `StudioDraft::ANSWER_SECTIONS`. */
export type StudioSectionKey = 'identity' | 'prayer' | 'brand' | 'content' | 'features' | 'layout' | 'platforms' | 'domain';

export type StudioPlatform = 'ios' | 'android' | 'tvos' | 'web';

export type StudioAccountMode = 'managed' | 'byo';

export type StudioIdentity = {
    org_type?: OrgType | null;
    name?: string | null;
    email?: string | null;
    phone?: string | null;
    address?: string | null;
    country_id?: number | null;
    city_id?: number | null;
    latitude?: number | null;
    longitude?: number | null;
    timezone?: string | null;
    user_id?: number | null;
    admin?: { name?: string | null; email?: string | null; phone?: string | null } | null;
    slug?: string | null;
    /** Public copy, published verbatim (R12). */
    description?: string | null;
    /** Internal only; never leaves the draft (R12). */
    vibe?: string | null;
    donation_link?: string | null;
    donation_title?: string | null;
    donation_message?: string | null;
    facebook_url?: string | null;
    youtube_url?: string | null;
    instagram_url?: string | null;
    whatsapp_url?: string | null;
    whatsapp_number?: string | null;
};

export type StudioSalah = 'fajr' | 'dhuhr' | 'asr' | 'maghrib' | 'isha';

export type StudioPrayer = {
    method?: string | null;
    madhab?: string | null;
    high_latitude_rule?: string | null;
    iqama_type?: string | null;
    /** Minutes after adhan, 0-180. */
    iqama?: Partial<Record<StudioSalah, number | null>> | null;
    /** HH:MM */
    jumaa_iqama?: string | null;
    /** false is "the client has not given iqama times". */
    iqama_given?: boolean | null;
};

export type StudioInkKey = 'onPrimary' | 'onSecondary' | 'onAccent';

export type StudioColourKey = 'primary_color' | 'secondary_color' | 'accent_color' | 'background_color';

export type StudioBrand = Partial<Record<StudioColourKey, string | null>> & {
    /** Candidates sampled from the logo, #rrggbb, at most 16. */
    extracted?: string[] | null;
    ink_overrides?: Partial<Record<StudioInkKey, string | null>> | null;
};

export type StudioContent = {
    about?: string | null;
    mission?: string | null;
    vision?: string | null;
};

export type StudioFeatures = {
    /** Served catalogue key => on/off (R9). */
    capabilities?: Record<string, boolean> | null;
};

export type StudioLayout = {
    preset?: string | null;
    approved_at?: string | null;
};

export type StudioPlatforms = {
    platforms?: StudioPlatform[] | null;
    /** Account modes only. Store credentials are never part of a draft (R7). */
    apps?: Partial<Record<'ios' | 'android' | 'web', { account_mode?: StudioAccountMode | null } | null>> | null;
};

export type StudioDomain = {
    custom?: { host?: string | null; zone_apex?: string | null } | null;
};

export type StudioAnswers = {
    identity: StudioIdentity;
    prayer: StudioPrayer;
    brand: StudioBrand;
    content: StudioContent;
    features: StudioFeatures;
    layout: StudioLayout;
    platforms: StudioPlatforms;
    domain: StudioDomain;
};

/** One pair of `PaletteContrast::report()` (app/Support/Studio/PaletteContrast.php). */
export type StudioPalettePair = {
    key: string;
    foreground: string | null;
    background: string | null;
    ratio: number | null;
    required: number;
    passes: boolean;
    blocking: boolean;
    ink_source: 'manual' | 'auto' | 'design_tokens' | null;
};

export type StudioPaletteReport = {
    valid: boolean;
    blocking_failures: string[];
    pairs: StudioPalettePair[];
    tokens: { color: Partial<Record<StudioInkKey, string>> };
    aspect_warning: boolean;
};

export type StudioDraftLogo = {
    original_name: string;
    mime_type: string;
    size_bytes: number;
    width: number | null;
    height: number | null;
    sha256: string;
    /** The authenticated endpoint, relative; fetched with the bearer as a blob. */
    url: string;
};

/** `StudioDraftResource::toArray()` (app/Http/Resources/Admin/StudioDraftResource.php). */
export type StudioDraft = {
    id: number;
    status: 'draft' | 'provisioned';
    current_step: StudioStepKey;
    schema_version: number;
    lock_version: number;
    name: string | null;
    org_type: OrgType | null;
    /** Sections as stored. A section cleared to nothing may arrive as `[]`. */
    answers: Partial<Record<StudioSectionKey, unknown>>;
    logo: StudioDraftLogo | null;
    palette: StudioPaletteReport | null;
    provisioned_masjid_id: number | null;
    provisioned_at: string | null;
    created_at: string | null;
    updated_at: string | null;
};

/** One row of `StudioDraftsController::index()`. */
export type StudioDraftRow = {
    id: number;
    status: 'draft' | 'provisioned';
    name: string | null;
    org_type: OrgType | null;
    current_step: StudioStepKey;
    has_logo: boolean;
    provisioned_masjid_id: number | null;
    updated_at: string | null;
};

/**
 * `StudioDomainCheckController::check()`, typed once, where S7 typed it with its
 * Cloudflare cases and `zone_status` (core/types/data/MasjidDomain.ts).
 */
export type StudioDomainCheck = MasjidDomainCheck;

/** The Identity panel's live answer about the Manara address, shared with the Domain panel. */
export type StudioSlugCheck = {
    state: 'none' | 'checking' | 'available' | 'taken' | 'invalid';
    host: string | null;
    takenBy: number | null;
    message: string | null;
};

/** The domain check's body (`StudioDomainCheckRequest`), the same as S7's add-a-domain body. */
export type StudioDomainCheckRequest = MasjidDomainRequest;

/** `OnboardingController::options()`, the parts Studio reads. */
export type StudioOptionValue = { value: string; label: string };

export type StudioVerticalOption = {
    org_type: OrgType;
    label: string;
    plural: string;
    terminology: Terminology;
};

export type StudioOptions = {
    verticals: StudioVerticalOption[];
    default_org_type: OrgType;
    prayer: {
        methods: StudioOptionValue[];
        madhabs: StudioOptionValue[];
        high_latitude_rules: StudioOptionValue[];
    };
    countries: { id: number; name: string }[];
};

/** One entry of `CapabilityCatalogue::entry()` (app/Support/CapabilityCatalogue.php). */
export type StudioCatalogueEntry = {
    key: string;
    kind: 'module' | 'grant';
    label: string;
    description: string;
    turns_on: string;
    writer: string;
    default_for_org_type: boolean | null;
    offered_by_default: boolean;
    default_at_creation: boolean;
    visibility: string;
    preselect_with: string[];
    where: string | null;
    surface: string | null;
    app: { items: string[]; tab: boolean };
};

/** `StudioCatalogueController::show()`. */
export type StudioCatalogue = {
    org_type: OrgType;
    groups: { key: string; label: string; entries: StudioCatalogueEntry[] }[];
};

/** One preset of `LayoutPresets::optionsPayload()` (app/Support/Studio/LayoutPresets.php). */
export type StudioLayoutPreset = {
    key: string;
    label: string;
    summary: string;
    is_default: boolean;
    theme_layout: Record<string, unknown> | null;
    pages: {
        slug: string;
        title: string;
        show_in_menu: boolean;
        show_as_button: boolean;
        sections: { slot: string; type: string | null; layout: string | null }[];
    }[];
};

/** One advisory row of `StudioPreview::platformContrast()`. */
export type StudioPlatformContrastRow = {
    key: string;
    foreground: string;
    background: string;
    ratio: number;
    aa_normal: boolean;
    aa_large: boolean;
    blocking: false;
};

/** One placeholder of a planned section (StarterSite::placeholder). `hint_text` is admin-facing and never published. */
export type StudioPlanPlaceholder = {
    field: string;
    kind: string;
    hint: string;
    hint_text: string;
    essential: boolean;
    source?: string;
    open: boolean;
};

/** One section of a starter plan (StarterSite::section, the `links` key removed by plan()). */
export type StudioPlanSection = {
    slot: string;
    section_type: string;
    /** The name an admin sees in the page builder. */
    title: string;
    is_active: boolean;
    has_renderer: boolean;
    content: Record<string, unknown>;
    placeholders: StudioPlanPlaceholder[];
    refs: { field: string; page?: string; form_template?: string }[];
};

/** One page of a starter plan (StarterSite::plan). */
export type StudioPlanPage = {
    slug: string;
    title: string;
    order: number;
    is_active: boolean;
    show_in_menu: boolean;
    show_as_button: boolean;
    meta_description: string | null;
    sections: StudioPlanSection[];
};

/** `StudioPreview::build()` (app/Support/Studio/StudioPreview.php). */
export type StudioPreview = {
    org: { name: string; org_type: OrgType; host: string | null };
    platforms: StudioPlatform[];
    palette: StudioPaletteReport | null;
    web_tokens: Record<string, string> | null;
    platform_contrast: StudioPlatformContrastRow[] | null;
    app: {
        ios: {
            tabs: string[];
            /** `parts`: which of an item's modules are on (AppMenu::sections, `parts: array<string, bool>`). */
            sections: { key: string; items: { key: string; legacy_feature_id: number | null; parts?: Record<string, boolean> }[] }[];
        };
        android: { tabs: string[] };
    };
    web: {
        preset: string;
        locale: string;
        pages: StudioPlanPage[];
        theme_layout: Record<string, unknown> | null;
        preset_source: 'draft' | 'default';
        approved: boolean;
    };
    tvos: {
        /** 'dark' | 'light' (TvConfigController::THEME). */
        theme: string;
        header_title: string;
        carousel_interval_seconds: number;
        show_prayer_panel: boolean;
        show_qr: boolean;
        donate_caption: string;
        announcement_selection: string;
    };
};

export type StudioSaveState = 'idle' | 'saving' | 'saved' | 'error' | 'conflict';

/** `ProvisionContext::$capabilitiesApplied`: the departures `CapabilityWriter::applyAtCreation` wrote, and the keys it left at their defaults. */
export type StudioCapabilitiesApplied = {
    changed: { key: string; enabled: boolean }[];
    unchanged: string[];
};

/** One section `StarterSite::applyTo` wrote inactive, with why it waits (admin-facing hints). */
export type StudioInactiveSection = {
    page: string;
    slot: string;
    section_type: string;
    title: string;
    hints: string[];
};

/** `StarterSite::applyTo()`'s report. */
export type StudioStarterSiteResult = {
    preset: string;
    created: string[];
    skipped: string[];
    sections_active: number;
    sections_inactive: StudioInactiveSection[];
    placeholders_open: number;
};

/** `StudioProvisionResult::$afterCommit`: what ran after the organisation was committed. */
export type StudioProvisionAfterCommit = {
    invites_sent: number;
    invites_failed: number;
    warnings: string[];
};

/**
 * The 201 body's `data` of POST /api/admin/studio/drafts/{id}/provision
 * (StudioProvisionController::body): the wizard's `{masjid_id, masjid,
 * app_publishing}` plus what Studio added. `capabilities_applied` is optional
 * here on purpose: a backend older than S8 ignores the draft's feature map and
 * never sends it, and the results screen must then say so rather than succeed
 * (core/studio/provision.ts readProvisionOutcome).
 */
export type StudioProvisionResult = {
    masjid_id: number;
    masjid: { id: number; name: string } & Record<string, unknown>;
    app_publishing: {
        enabled_platforms: string[] | null;
        ios_account_mode: StudioAccountMode | null;
        android_account_mode: StudioAccountMode | null;
        web_account_mode: StudioAccountMode | null;
        has_asc_key: boolean;
        has_play_service_account: boolean;
    };
    draft_id: number;
    brand_assets?: {
        logo_url: string | null;
        favicon_url: string | null;
        touch_icon_url: string | null;
        share_image_url: string | null;
    };
    capabilities_applied?: StudioCapabilitiesApplied | null;
    starter_site?: StudioStarterSiteResult | null;
    web?: {
        host: string;
        status: string;
        waiting_on: string | null;
        live_url: string | null;
        manual_steps: string[];
    } | null;
    domains?: MasjidDomain[];
    after_commit?: StudioProvisionAfterCommit;
};
