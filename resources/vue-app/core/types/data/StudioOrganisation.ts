/**
 * Studio opening an organisation that already exists (docs/manara-studio-w2.md
 * S9), typed from the server that answers it: GET /api/admin/studio/
 * organisations/{id}, `OrganisationSnapshot::of()` (app/Support/Studio/
 * OrganisationSnapshot.php). Each section type names the method that builds it,
 * so a change on the server has one obvious place to follow it here.
 *
 * The live organisation's preview is W1's `StudioPreview` (POST …/organisations/
 * {id}/preview answers the draft preview's shape), and a feature entry is the
 * catalogue's own `StudioCatalogueEntry` with the organisation's effective value.
 */
import type { StudioAccountMode, StudioCatalogueEntry, StudioColourKey, StudioWebsiteLocale } from "@/core/types/data/Studio";
import type { OrgType } from "@/core/types/data/Vertical";

/** `OrganisationSnapshot::SECTIONS`: Studio's section order, plus `apps`. */
export type StudioOrganisationSectionKey =
    'identity' | 'prayer' | 'brand' | 'content' | 'features' | 'layout' | 'platforms' | 'domain' | 'apps';

/** `OrganisationSnapshot::identity()`. */
export type StudioOrganisationIdentity = {
    name: string | null;
    org_type: OrgType;
    slug: string | null;
    description: string | null;
    /** W2 S12: null is "not chosen", which renders English. */
    website_locale: StudioWebsiteLocale | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    timezone: string | null;
    latitude: number | null;
    longitude: number | null;
    website_link: string | null;
    donation_link: string | null;
    /** `StarterFacts::SOCIAL_TYPES` keys. */
    social: Partial<Record<'facebook_url' | 'instagram_url' | 'youtube_url' | 'whatsapp_url', string | null>>;
    /** Whether it is listed in the public directory. */
    listed: boolean;
};

/** `OrganisationSnapshot::prayer()`. Each block is null until that settings row exists. */
export type StudioOrganisationPrayer = {
    module_on: boolean;
    calculation: { method: string | null; madhab: string | null; high_latitude_rule: string | null } | null;
    iqama: {
        iqama_type: string | null;
        show_iqama_times: boolean;
        fajr: string | number | null;
        dhuhr: string | number | null;
        asr: string | number | null;
        maghrib: string | number | null;
        isha: string | number | null;
    } | null;
    jumaa: {
        iqama: string | null;
        athans: string[] | null;
        shifts: { time?: string | null }[] | null;
    } | null;
};

/** `OrganisationSnapshot::brand()`. The four image URLs are each the newest in its media collection. */
export type StudioOrganisationBrand = {
    colours: Record<StudioColourKey, string | null> | null;
    theme_layout: Record<string, unknown> | null;
    has_theme: boolean;
    logo_url: string | null;
    favicon_url: string | null;
    touch_icon_url: string | null;
    share_image_url: string | null;
    /** Whether any of the favicon, touch icon and share image exists (S8's confirm dialog reads it). */
    has_derivatives: boolean;
};

/** `OrganisationSnapshot::content()`. */
export type StudioOrganisationContent = {
    about: string | null;
    mission: string | null;
    vision: string | null;
};

/**
 * One entry of `OrganisationSnapshot::features()`: the catalogue's entry for
 * this organisation type plus its EFFECTIVE value (moduleIsOff for a module,
 * hasCapability for a grant), and whether a SuperAdmin decided it.
 */
export type StudioOrganisationFeatureEntry = StudioCatalogueEntry & {
    enabled: boolean;
    decided: boolean;
};

export type StudioOrganisationFeatureGroup = {
    key: string;
    label: string;
    entries: StudioOrganisationFeatureEntry[];
};

/** One page of `OrganisationSnapshot::layout()`. `sections` is a count. */
export type StudioOrganisationPage = {
    id: number;
    slug: string;
    title: string;
    is_active: boolean;
    show_in_menu: boolean;
    sections: number;
};

export type StudioOrganisationLayout = {
    website_on: boolean;
    theme_layout: Record<string, unknown> | null;
    pages: StudioOrganisationPage[];
};

/** `OrganisationSnapshot::platforms()`: account modes and has_* flags, never a credential or an identifier. */
export type StudioOrganisationPlatforms = {
    enabled_platforms: string[];
    ios_account_mode: StudioAccountMode | null;
    android_account_mode: StudioAccountMode | null;
    web_account_mode: StudioAccountMode | null;
    has_asc_key: boolean;
    has_play_service_account: boolean;
    has_onesignal_key: boolean;
    has_onesignal_app: boolean;
};

/** One row of `OrganisationSnapshot::domain()`. */
export type StudioOrganisationDomain = {
    host: string;
    kind: string;
    status: string;
    source: string | null;
    served: boolean;
};

/** `edit_in` is `'studio'` for features and brand, otherwise the SPA path of the screen that writes it. */
export type StudioOrganisationSection<T> = { data: T; edit_in: string };

export type StudioOrganisationSnapshot = {
    org: { id: number; name: string; org_type: OrgType; slug: string | null };
    sections: {
        identity: StudioOrganisationSection<StudioOrganisationIdentity>;
        prayer: StudioOrganisationSection<StudioOrganisationPrayer>;
        brand: StudioOrganisationSection<StudioOrganisationBrand>;
        content: StudioOrganisationSection<StudioOrganisationContent>;
        features: StudioOrganisationSection<StudioOrganisationFeatureGroup[]>;
        layout: StudioOrganisationSection<StudioOrganisationLayout>;
        platforms: StudioOrganisationSection<StudioOrganisationPlatforms>;
        domain: StudioOrganisationSection<StudioOrganisationDomain[]>;
        /** Filled by a later slice (S17). */
        apps: StudioOrganisationSection<null>;
    };
};

/**
 * The bulk PATCH's `meta` (`CapabilityWriter::apply`): the keys whose
 * effective value moved, and those that already had the value sent. Every key
 * sent wrote one ledger row either way.
 */
export type StudioCapabilitiesOutcome = {
    changed: string[];
    unchanged: string[];
};

/** `BrandAssets::regenerate()`, answered by POST …/brand-assets/regenerate (S8). */
export type StudioBrandAssets = {
    logo_url: string | null;
    favicon_url: string | null;
    touch_icon_url: string | null;
    share_image_url: string | null;
};
