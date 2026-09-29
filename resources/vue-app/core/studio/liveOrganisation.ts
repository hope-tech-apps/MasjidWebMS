/**
 * The pure half of Studio's live-organisation screen (docs/manara-studio-w2.md
 * S9, views/dashboard/super/studio/StudioOrganisationView.vue): what each
 * snapshot section is called, which admin screen writes it, its data as the
 * short term/detail rows LiveSectionCard lists, and the escaped HTML of the
 * confirm dialogs.
 *
 * Every value is the snapshot's (OrganisationSnapshot::of). The name of an
 * organisation type or a prayer choice is the server's, read from
 * /onboarding/options (`options`), never copied here; without the options the
 * raw value is shown. The platforms section carries has_* flags and account
 * modes only, so no credential can be printed from here.
 */
import { escapeHtml } from "@/core/plugins/swalSanitize";
import { accountModeLabel, platformLabel } from "@/core/studio/platforms";
import type { StudioOptions, StudioOptionValue } from "@/core/types/data/Studio";
import type {
    StudioOrganisationContent,
    StudioOrganisationDomain,
    StudioOrganisationIdentity,
    StudioOrganisationLayout,
    StudioOrganisationPlatforms,
    StudioOrganisationPrayer,
    StudioOrganisationSectionKey,
    StudioOrganisationSnapshot,
} from "@/core/types/data/StudioOrganisation";

/** `OrganisationSnapshot::EDIT_IN_STUDIO`: the section is changed on the live screen itself. */
export const EDIT_IN_STUDIO = 'studio';

export const SECTION_TITLES: Record<StudioOrganisationSectionKey, string> = {
    identity: 'Identity',
    prayer: 'Prayer',
    brand: 'Brand',
    content: 'About',
    features: 'Features',
    layout: 'Layout',
    platforms: 'Platforms',
    domain: 'Domain',
    apps: 'Apps',
};

/** The admin screen each read-only section's `edit_in` opens, by the name its button gives it. */
export const EDIT_SCREENS: Record<Exclude<StudioOrganisationSectionKey, 'brand' | 'features'>, string> = {
    identity: 'Details',
    prayer: 'Prayer settings',
    content: 'About',
    layout: 'Pages',
    platforms: 'Organisation screen',
    domain: 'Organisation screen',
    apps: 'Organisation screen',
};

/**
 * Whether an `edit_in` path is one of the organisation dashboard's screens
 * (`/masjid/…`). Those act on the dashboard's CURRENT organisation, so the
 * dashboard is switched to this one before the link is followed; the
 * SuperAdmin screens (`/dashboard/super/…`) name the organisation in the path.
 */
export function editsInOrganisationDashboard(path: string): boolean {
    return path === '/masjid' || path.startsWith('/masjid/') || path.startsWith('/masjid#');
}

export type SectionRow = { term: string; detail: string };

const NOT_SET = 'Not set';

function text(value: unknown): string {
    if (typeof value === 'number' && Number.isFinite(value)) return String(value);
    return typeof value === 'string' && value.trim() ? value.trim() : NOT_SET;
}

/** Long copy, cut at a word near `max` characters. */
function excerpt(value: string | null | undefined, max = 160): string {
    const clean = (value ?? '').replace(/\s+/g, ' ').trim();
    if (!clean) return NOT_SET;
    if (clean.length <= max) return clean;
    const cut = clean.slice(0, max);
    const space = cut.lastIndexOf(' ');
    return `${(space > max * .6 ? cut.slice(0, space) : cut).trimEnd()}…`;
}

function onOff(value: boolean): string {
    return value ? 'On' : 'Off';
}

/** The server's name for an option value, or the value itself. */
function optionName(choices: StudioOptionValue[] | undefined, value: string | null | undefined): string {
    if (!value) return NOT_SET;
    return choices?.find((choice) => choice.value === value)?.label ?? value;
}

/** An organisation type's own label from /onboarding/options, or the type as it came. */
export function orgTypeName(options: StudioOptions | null, orgType: string | null | undefined): string {
    if (!orgType) return '';
    return options?.verticals.find((vertical) => vertical.org_type === orgType)?.label ?? orgType;
}

const SOCIAL_NAMES: Record<string, string> = {
    facebook_url: 'Facebook',
    instagram_url: 'Instagram',
    youtube_url: 'YouTube',
    whatsapp_url: 'WhatsApp',
};

function identityRows(identity: StudioOrganisationIdentity, options: StudioOptions | null): SectionRow[] {
    const social = Object.entries(identity.social ?? {})
        .filter(([, url]) => typeof url === 'string' && url.trim() !== '')
        .map(([key]) => SOCIAL_NAMES[key] ?? key);
    const located = identity.latitude !== null && identity.longitude !== null;

    return [
        { term: 'Name', detail: text(identity.name) },
        { term: 'Type', detail: orgTypeName(options, identity.org_type) || NOT_SET },
        { term: 'Website address', detail: text(identity.slug) },
        { term: 'Description', detail: excerpt(identity.description) },
        { term: 'Email', detail: text(identity.email) },
        { term: 'Phone', detail: text(identity.phone) },
        { term: 'Address', detail: text(identity.address) },
        { term: 'Timezone', detail: text(identity.timezone) },
        { term: 'Location', detail: located ? `${identity.latitude}, ${identity.longitude}` : NOT_SET },
        { term: 'Website link', detail: text(identity.website_link) },
        { term: 'Donation link', detail: text(identity.donation_link) },
        { term: 'Social links', detail: social.length ? social.join(', ') : 'None' },
        { term: 'Directory', detail: identity.listed ? 'Listed' : 'Not listed' },
    ];
}

/** The Jumu'ah times on record: the shifts' when there are any, else the athans, else the one iqama. */
function jumaaTimes(jumaa: StudioOrganisationPrayer['jumaa']): string {
    if (!jumaa) return NOT_SET;
    const shifts = (jumaa.shifts ?? []).map((shift) => shift?.time).filter((time): time is string => !!time);
    if (shifts.length) return shifts.join(', ');
    const athans = (jumaa.athans ?? []).filter((time) => typeof time === 'string' && time !== '');
    if (athans.length) return athans.join(', ');
    return text(jumaa.iqama);
}

function prayerRows(prayer: StudioOrganisationPrayer, options: StudioOptions | null): SectionRow[] {
    const calculation = prayer.calculation;
    const iqama = prayer.iqama;

    return [
        { term: 'Prayer times', detail: onOff(prayer.module_on) },
        { term: 'Calculation method', detail: calculation ? optionName(options?.prayer.methods, calculation.method) : 'Not set up' },
        { term: 'Madhab', detail: calculation ? optionName(options?.prayer.madhabs, calculation.madhab) : 'Not set up' },
        { term: 'High-latitude rule', detail: calculation ? optionName(options?.prayer.high_latitude_rules, calculation.high_latitude_rule) : 'Not set up' },
        {
            term: 'Iqama',
            detail: iqama
                ? `${iqama.iqama_type === 'minutes_after_adhan' ? 'Minutes after the adhan' : 'Set times'}, ${iqama.show_iqama_times ? 'shown' : 'hidden'}`
                : 'Not set up',
        },
        { term: "Jumu'ah", detail: jumaaTimes(prayer.jumaa) },
    ];
}

function contentRows(content: StudioOrganisationContent): SectionRow[] {
    return [
        { term: 'About', detail: excerpt(content.about) },
        { term: 'Mission', detail: excerpt(content.mission) },
        { term: 'Vision', detail: excerpt(content.vision) },
    ];
}

function layoutRows(layout: StudioOrganisationLayout): SectionRow[] {
    const pages = layout.pages ?? [];
    const active = pages.filter((page) => page.is_active).length;
    const inMenu = pages.filter((page) => page.is_active && page.show_in_menu).length;

    return [
        { term: 'Website', detail: onOff(layout.website_on) },
        {
            term: 'Pages',
            detail: pages.length ? `${pages.length} (${active} active, ${inMenu} in the menu)` : 'None yet',
        },
        ...pages.map((page) => ({
            term: page.title || page.slug,
            detail: [
                `/${page.slug}`,
                page.is_active ? 'active' : 'inactive',
                page.is_active && page.show_in_menu ? 'in the menu' : null,
                `${page.sections} ${page.sections === 1 ? 'section' : 'sections'}`,
            ].filter(Boolean).join(' · '),
        })),
    ];
}

function platformRows(platforms: StudioOrganisationPlatforms): SectionRow[] {
    const onFile = (value: boolean) => (value ? 'On file' : 'Not on file');

    return [
        {
            term: 'Platforms',
            detail: platforms.enabled_platforms.length ? platforms.enabled_platforms.map(platformLabel).join(', ') : 'None on record',
        },
        { term: 'iOS account', detail: accountModeLabel(platforms.ios_account_mode) },
        { term: 'Android account', detail: accountModeLabel(platforms.android_account_mode) },
        { term: 'Web account', detail: accountModeLabel(platforms.web_account_mode) },
        { term: 'App Store Connect key', detail: onFile(platforms.has_asc_key) },
        { term: 'Play service account', detail: onFile(platforms.has_play_service_account) },
        { term: 'OneSignal key', detail: onFile(platforms.has_onesignal_key) },
        { term: 'OneSignal app', detail: platforms.has_onesignal_app ? 'Set up' : 'Not set up' },
    ];
}

function domainRows(domains: StudioOrganisationDomain[]): SectionRow[] {
    return domains.map((domain) => ({
        term: domain.host,
        detail: [
            domain.kind === 'custom' ? 'Own domain' : 'Manara address',
            domain.status.replace(/_/g, ' '),
            domain.served ? 'served' : null,
        ].filter(Boolean).join(' · '),
    }));
}

/**
 * One read-only section's rows. Brand and Features are edited on the live
 * screen itself, and Apps has nothing yet (S17), so each has none here.
 */
export function sectionRows(key: StudioOrganisationSectionKey, snapshot: StudioOrganisationSnapshot, options: StudioOptions | null): SectionRow[] {
    const sections = snapshot.sections;

    switch (key) {
        case 'identity': return identityRows(sections.identity.data, options);
        case 'prayer': return prayerRows(sections.prayer.data, options);
        case 'content': return contentRows(sections.content.data);
        case 'layout': return layoutRows(sections.layout.data);
        case 'platforms': return platformRows(sections.platforms.data);
        case 'domain': return domainRows(sections.domain.data ?? []);
        default: return [];
    }
}

/** What a read-only section says when it has no rows. */
export const EMPTY_SECTION_TEXT: Partial<Record<StudioOrganisationSectionKey, string>> = {
    domain: 'No web address is on record for this organisation.',
    apps: 'Apps are generated from Studio in a later release. Until then they are managed on the organisation screen.',
};

/** The one escaper for dialog HTML (the global SweetAlert2 sanitizer's), not a second copy. */
export { escapeHtml };

/**
 * A confirm dialog's body: each sentence a paragraph, then a list, every piece
 * escaped (labels and colours are data, and SweetAlert renders `html` as HTML).
 */
export function dialogHtml(sentences: string[], items: string[] = []): string {
    const paragraphs = sentences.map((sentence) => `<p class="text-start">${escapeHtml(sentence)}</p>`).join('');
    const list = items.length ? `<ul class="text-start mb-0">${items.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : '';
    return paragraphs + list;
}
