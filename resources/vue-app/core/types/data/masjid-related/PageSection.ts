export type SectionType =
    | 'page_title'
    | 'prayer_times'
    | 'text'
    | 'about_us'
    | 'image_text_grid'
    | 'grid_cards'
    | 'donation'
    | 'contact_form'
    | 'services_list'
    | 'announcements_list'
    | 'gallery'
    | 'events'
    | 'stats'
    | 'mission_vision'
    | 'cta'
    | 'form'
    | 'image'
    | 'link_list'
    | 'carousel'
    | 'embed'
    // Manara Schools (T-010). Offered to every tenant — the palette is global,
    // nothing filters section types by org_type. See App\Enums\SectionType.
    | 'staff_directory'
    | 'programs'
    | 'admissions_tuition';

// Base Section
export type PageSection = {
    id: number;
    page_id: number;
    section_type: SectionType;
    section_type_label: string;
    title: string | null;
    content: SectionContent;
    order: number;
    platforms?: string[]; // Placement-level visibility: ['web','mobile']
    is_active: boolean;
    settings: Record<string, any> | null;
    uses_external_data: boolean;
    created_at: string;
    updated_at: string;
    pages?: Array<{ id: number; title: string; slug: string }>; // For sections library
};

// Union type for all possible section content structures
export type SectionContent =
    | PageTitleSectionContent
    | PrayerTimesSectionContent
    | TextSectionContent
    | AboutUsSectionContent
    | ImageTextGridSectionContent
    | GridCardsSectionContent
    | DonationSectionContent
    | ContactFormSectionContent
    | ServicesListSectionContent
    | AnnouncementsListSectionContent
    | GallerySectionContent
    | EventsSectionContent
    | StatsSectionContent
    | MissionVisionSectionContent
    | CTASectionContent
    | FormSectionContent
    | ImageSectionContent
    | LinkListSectionContent
    | CarouselSectionContent
    | EmbedSectionContent
    | StaffDirectorySectionContent
    | ProgramsSectionContent
    | AdmissionsTuitionSectionContent;

// Individual Section Content Types

export type PageTitleSectionContent = {
    title: string;
    background_image_url: string | null;
};

export type PrayerTimesSectionContent = {
    title: string;
    subtitle: string;
    image_url: string | null;
};

export type TextSectionContent = {
    heading: string;
    content: string; // HTML content
    layout: 'single_column' | 'two_columns';
    background_color: string;
};

export type AboutUsSectionContent = {
    title: string;
    subtitle: string;
    text: string;
    image_url: string | null;
    button_text: string;
};

export type ImageTextGridSectionContent = {
    title: string;
    subtitle: string;
    text: string;
    main_image_url: string | null;
    header_image_url: string | null;
    footer_image_url: string | null;
    button_text: string;
    button_page_id: number | null;
    button_page_url?: string | null; // Auto-generated from button_page_id
    button_link: string | null;
    show_button: boolean;
    content_direction: 'ltr' | 'rtl';
    background_color: string;
};

export type GridCardItem = {
    title: string;
    text: string;
    image_url: string | null;
};

export type GridCardsSectionContent = {
    items_per_row: number;
    items: GridCardItem[];
};

export type DonationSectionContent = {
    heading: string;
    description: string;
    image_url: string | null;
    button_text: string;
    button_style: 'primary' | 'secondary';
    show_payment_icons: boolean;
};

export type ContactFormSectionContent = {
    title: string;
    subtitle: string;
    button_text: string;
    show_map: boolean;
};

export type ServicesListSectionContent = {
    title: string;
    subtitle: string;
    button_text: string;
    items_per_page: number;
};

export type AnnouncementsListSectionContent = {
    title: string;
    subtitle: string;
    button_text: string;
    items_per_page: number;
};

export type GallerySectionContent = {
    heading: string;
    description: string;
    layout: 'masonry' | 'grid';
    items_per_page: number;
    columns: number;
    enable_lightbox: boolean;
};

// Events are backend-driven (the `events` table): the section only carries the
// wording around the list and how many entries to show.
export type EventsSectionContent = {
    heading: string;
    description: string;
    items_per_page: number;
};

export type StatsSectionContent = {
    heading: string;
    stats: Array<{
        label: string;
        value: string;
        icon: string;
    }>;
    layout: 'horizontal' | 'vertical';
};

export type MissionVisionSectionContent = {
    heading: string;
    items: Array<{
        type: 'mission' | 'vision';
        title: string;
        content: string;
        icon_url: string | null;
    }>;
    layout: 'side_by_side' | 'stacked';
};

// A sign-up form section holds a REFERENCE, never the schema: the questions and every
// response live in the forms / form_responses tables, so detaching this section from a
// page cannot destroy a registration list. `title` and `intro` are page-level wording;
// the form's own intro, submit button and thank-you message belong to the form.
export type FormSectionContent = {
    form_id: number | null;
    title: string;
    intro: string;
};

export type CTASectionContent = {
    heading: string;
    description: string;
    button_text: string;
    button_link: string;
    button_style: 'primary' | 'secondary';
    background_image_url: string | null;
    background_color: string;
};

// A single standalone image — flyers, posters, decorative banners. `max_width` is a
// named size the renderer maps onto its own layout scale, not a pixel value.
export type ImageSectionContent = {
    image_url: string | null;
    alt_text: string;
    caption: string;
    max_width: 'full' | 'container' | 'narrow';
    background_color: string;
};

export type LinkListItem = {
    label: string;
    url: string;   // https:, mailto: and tel: are all valid here
    icon: string;  // bootstrap icon class, e.g. "bi-envelope"
    style: 'primary' | 'secondary' | 'outline';
};

export type LinkListSectionContent = {
    heading: string;
    description: string;
    links: LinkListItem[];
    layout: 'stack' | 'inline' | 'grid';
    background_color: string;
};

export type CarouselSlide = {
    image_url: string;
    title: string;
    caption: string;
    link_url: string;
    link_text: string;
};

export type CarouselSectionContent = {
    slides: CarouselSlide[];
    autoplay: boolean;
    interval_ms: number;
    show_arrows: boolean;
    show_dots: boolean;
    height: 'short' | 'medium' | 'tall';
};

// Section Type Info (for admin panel)
export type SectionTypeInfo = {
    value: SectionType;
    label: string;
    description: string;
    uses_external_data: boolean;
    default_content: SectionContent;
};

/**
 * A third-party widget framed into a page.
 *
 * `provider` picks the allowlist the URL is checked against server-side
 * (App\Support\EmbedProviders) — that check is the control, not this type. `iframe`
 * is READ-ONLY: the API adds it when serving a page (resolved src + the sandbox policy
 * for that provider) and it is null when a stored URL no longer validates. The editor
 * never sets it.
 */
export type EmbedProvider =
    | 'youtube'
    | 'google_maps'
    | 'google_form'
    | 'wix_widget'
    | 'site_page';

export type EmbedSectionContent = {
    provider: EmbedProvider | null;
    url: string;
    title: string;
    caption: string;
    height: number | null;
    aspect: string | null;   // "16:9" — wins over height when set
    fallback_text: string;
    background_color: string;
    iframe?: {
        src: string;
        sandbox: string;
        allow: string;
        default_aspect: string | null;
    } | null;
};

/* -------------------------------------------------------------------------
 * Manara Schools (T-010)
 * ---------------------------------------------------------------------- */

/**
 * One published person. This is EDITORIAL content typed into the section, not a
 * row from the contacts/CRM tables — those hold private records about families
 * and must never become a public page. `department` is a free grouping label
 * ("Administration", "Lower School"); the list stays flat because the section
 * image pipeline can only re-key one array index (`members.*.photo_url`).
 */
export type StaffMember = {
    name: string;
    role: string;
    department: string;
    credentials: string;
    bio: string;
    email: string;
    phone: string;
    photo_url: string | null;
};

export type StaffDirectorySectionContent = {
    heading: string;
    description: string;
    members: StaffMember[];
    layout: 'grid' | 'list';
    columns: number;
    /** Publish each person's email/phone. Defaults to false — opting in is a decision. */
    show_contact: boolean;
    background_color: string;
};

/**
 * One course of study. `level` (grade or age band) and `schedule` (meeting
 * pattern) are free text: "Pre-K", "Grades 1-2" and "Ages 4-6" are all real, and
 * no two schools band them the same way. `highlights` is the curriculum bullets.
 */
export type ProgramItem = {
    name: string;
    level: string;
    schedule: string;
    summary: string;
    highlights: string[];
    image_url: string | null;
    link_url: string;
    link_text: string;
};

export type ProgramsSectionContent = {
    heading: string;
    description: string;
    programs: ProgramItem[];
    layout: 'cards' | 'list' | 'accordion';
    columns: number;
    background_color: string;
};

/**
 * A priced option in the tuition table. `amount` and `period` are DISPLAY TEXT,
 * not numbers: a real schedule mixes "$8,000", "Included" and "Contact us" in one
 * table, and nothing in this app charges from these values. A string says what
 * the page says; a decimal would imply a machine reads it.
 */
export type TuitionTier = {
    name: string;
    badge: string;
    amount: string;
    period: string;
    note: string;
    includes: string[];
};

export type AdmissionsFee = {
    label: string;
    amount: string;
    note: string;
};

export type AdmissionsPaymentPlan = {
    label: string;
    detail: string;
};

export type AdmissionsStep = {
    title: string;
    description: string;
};

/**
 * The price list and how to apply — NOT the application itself. Collecting an
 * applicant's details is the `form` section type over the forms tables;
 * `button_page_id` joins the two, resolving to `button_page_url` on read so the
 * apply button points at a page in the same builder rather than a URL that rots.
 */
export type AdmissionsTuitionSectionContent = {
    heading: string;
    description: string;
    /** Eyebrow above the heading, e.g. "2026-2027 School Year". */
    school_year: string;
    tiers: TuitionTier[];
    fees: AdmissionsFee[];
    payment_plans: AdmissionsPaymentPlan[];
    steps: AdmissionsStep[];
    disclaimer: string;
    button_text: string;
    button_page_id: number | null;
    button_page_url?: string | null; // Auto-generated from button_page_id
    button_link: string | null;
    background_color: string;
};
