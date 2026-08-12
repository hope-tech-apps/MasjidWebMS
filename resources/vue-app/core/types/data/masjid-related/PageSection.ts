import {
    BillingInterval,
    FeePlanKind,
    OfferingKind
} from '@/core/types/data/masjid-related/Offering';

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
    | 'admissions_tuition'
    // Manara Community (T-020). Offered to every tenant for the same reason —
    // the palette is global. See App\Enums\SectionType.
    | 'services_eligibility'
    | 'providers_directory'
    | 'impact_stats'
    // The registration front door (T-006g). Offered to every tenant like every
    // other type. See App\Enums\SectionType.
    | 'offering';

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
    | AdmissionsTuitionSectionContent
    | ServicesEligibilitySectionContent
    | ProvidersDirectorySectionContent
    | ImpactStatsSectionContent
    | OfferingSectionContent;

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
    /**
     * What the type is. Already carries `renderer_note` on the end when
     * `has_renderer` is false — the server appends it, so a surface that prints
     * only the description still tells the truth.
     */
    description: string;
    uses_external_data: boolean;
    /**
     * Whether the public SITE has a component that draws this type. False means
     * publishing it stores and serves the data and the page shows nothing where
     * it sits — the state `offering` is in until the Nuxt renderer ships.
     */
    has_renderer: boolean;
    /**
     * The one sentence explaining that, server-supplied and null when
     * `has_renderer` is true. Printed verbatim rather than re-worded per screen:
     * the palette, the section editor and the type description saying three
     * different things is exactly what this replaced.
     */
    renderer_note: string | null;
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

/* -------------------------------------------------------------------------
 * Manara Community (T-020)
 * ---------------------------------------------------------------------- */

/**
 * One service the organisation offers. The list stays FLAT — the section image
 * pipeline can only re-key one array index (`services.*.image_url`), so grouping
 * services into a nested tree would silently drop every uploaded photo.
 */
export type ServiceItem = {
    name: string;
    description: string;
    image_url: string | null;
};

/**
 * The card the eligibility block leads with — a free clinic's "Yellow Card", a
 * food pantry's referral pass. Its own block rather than one more bullet, so the
 * renderer can style it without guessing which criterion matters.
 */
export type EligibilityHighlight = {
    /** Small pill above the title, e.g. "Yellow Card". */
    badge: string;
    title: string;
    subtitle: string;
    body: string;
};

/**
 * Who qualifies. A single object, not a list: a page states its rules once.
 * `criteria` is plain strings — "Household income at or below 200% of the
 * Federal Poverty Level". Carries no image, so the one-array-level upload limit
 * that keeps `services` flat does not constrain it.
 */
export type EligibilityBlock = {
    heading: string;
    intro: string;
    criteria: string[];
    note: string;
    highlight: EligibilityHighlight;
};

export type ServicesEligibilitySectionContent = {
    heading: string;
    description: string;
    services: ServiceItem[];
    layout: 'cards' | 'list';
    columns: number;
    eligibility: EligibilityBlock;
    button_text: string;
    button_page_id: number | null;
    button_page_url?: string | null; // Auto-generated from button_page_id
    button_link: string | null;
    background_color: string;
};

/**
 * One published clinician. EDITORIAL content typed into the section, never a row
 * out of the contacts/CRM tables. `credential` is the post-nominal suffix ("MD",
 * "DO", "FNP-C") — free text, since no closed set covers every profession and
 * state. `department` is a free grouping label on a FLAT list, exactly as
 * `StaffMember.department` is: a departments[].providers[] tree would nest the
 * photo two array levels deep and every upload would vanish silently.
 */
export type ProviderItem = {
    name: string;
    credential: string;
    specialty: string;
    department: string;
    photo_url: string | null;
};

export type ProvidersDirectorySectionContent = {
    heading: string;
    description: string;
    providers: ProviderItem[];
    layout: 'grid' | 'list';
    columns: number;
    background_color: string;
};

/**
 * One headline number. `value` is DISPLAY TEXT and never a number to format: the
 * figures a clinic puts in front of funders read "6,000+", "$6.3M" and "1 in 4",
 * and the rounding, the plus sign and the currency are part of the claim.
 * Formatting a stored decimal here would change a published, audited figure.
 */
export type ImpactStat = {
    value: string;
    label: string;
    description: string;
};

export type ImpactStatsSectionContent = {
    heading: string;
    description: string;
    /** Reporting-period caption for the whole block, e.g. "In 2025". */
    period: string;
    stats: ImpactStat[];
    layout: 'row' | 'grid';
    columns: number;
    background_color: string;
};

/* -------------------------------------------------------------------------
 * The registration front door (T-006g)
 * ---------------------------------------------------------------------- */

/**
 * One way to pay, as the PUBLIC payload states it — a narrower shape than the
 * admin `FeePlan` in `@/core/types/data/masjid-related/Offering`, which is the
 * point: the public copy carries no `masjid_id`, no `offering_id` and no
 * timestamps.
 *
 * `id` is here because POST /api/v1/offerings/{slug}/register takes
 * `fee_plan_id` — it is the only internal id the public payload publishes.
 *
 * BOTH amounts are INTEGER MINOR UNITS and both are computed server-side.
 * `amount_minor` is one charge; `total_minor` is the whole commitment
 * (`amount x installment_count` for an installment plan). Nothing in a browser
 * may derive one from the other — the server's `listTotalFor()` is the only
 * implementation, and it is the same one that snapshots what a family is
 * actually charged.
 */
export type PublicFeePlan = {
    id: number;
    kind: FeePlanKind;
    label: string;
    /** Lowercase ISO-4217 as stored (`usd`). Never render an amount without it. */
    currency: string;
    /** INTEGER MINOR UNITS — one charge. */
    amount_minor: number;
    /** INTEGER MINOR UNITS — the whole commitment. Server-computed. */
    total_minor: number;
    billing_interval: BillingInterval | null;
    installment_count: number | null;
    /** false is the free path: confirmed in-request, no Stripe leg, never a $0 session. */
    requires_payment: boolean;
};

/**
 * The intake questions and the wording drawn around them. No id and no slug: a
 * registration is posted to the OFFERING's endpoint, so neither is needed. No
 * fee rule either — money for an offering comes from its fee plans and nowhere
 * else.
 */
export type PublicOfferingForm = {
    name: string;
    description: string | null;
    schema: Record<string, any> | null;
    settings: {
        submitButtonLabel: string;
        successTitle: string | null;
        successBody: string | null;
        successNextSteps: string[];
        intro: string | null;
    };
};

/**
 * What an anonymous visitor is served about one offering — by
 * `GET /api/v1/offerings/{slug}` and, identically, inlined under
 * `content.offering` when a page carrying an `offering` section is fetched.
 * `App\Support\OfferingPublicPayload` builds both.
 *
 * `registration_state` is the field to switch on, not `is_open`: a FULL offering
 * is still accepting sign-ups (they are waitlisted, not refused), and an
 * offering whose intake form has been deleted is closed even though `is_open`
 * reports true. The server decides all of that in one place on purpose.
 *
 * There is no `capacity` and no registrant count here, deliberately:
 * `seats.remaining` is a property of the offering, not of the people in it.
 */
export type PublicOffering = {
    slug: string;
    name: string;
    description: string | null;
    kind: OfferingKind;
    opens_at: string | null;
    closes_at: string | null;
    /** is_active AND inside the window — the model's own accessor, verbatim. */
    is_open: boolean;
    closed_reason: 'inactive' | 'not_yet_open' | 'closed' | null;
    seats: {
        is_full: boolean;
        /** null = unlimited, which is unknown rather than 0. */
        remaining: number | null;
    };
    registration_state: 'open' | 'waitlist' | 'closed';
    /**
     * WHY the state is `closed`; null when it is not. Decided by the same server
     * call that produced `registration_state`, so the two cannot disagree.
     *
     * NOT a second `closed_reason`. That one answers "is the window open", which
     * is the model's question, and reports null for an offering whose intake
     * form has been deleted or whose fee plans have all been deactivated — both
     * of which shut registration completely. This answers "would a registration
     * be accepted", which is the write path's question, and it is the one a
     * renderer explains itself from.
     */
    registration_state_reason:
        | 'inactive'
        | 'not_yet_open'
        | 'closed'
        | 'no_intake_form'
        | 'no_fee_plan'
        | null;
    fee_plans: PublicFeePlan[];
    intake_form: PublicOfferingForm | null;
};

/**
 * The `offering` page section: a REFERENCE plus page-level wording, exactly as
 * `form` is. The price, the window, the places left and the questions live on
 * the offering; copying any of them in here would be a second price that goes
 * stale the moment a fee plan is replaced.
 *
 * `offering` is READ-ONLY and server-supplied: SectionContentBinder inlines it
 * when the site fetches the page, and it is null when the id is unset, points at
 * nothing, belongs to another masjid, is deleted, or is switched off. The editor
 * never sets it — the same contract `EmbedSectionContent.iframe` has.
 */
export type OfferingSectionContent = {
    offering_id: number | null;
    title: string;
    intro: string;
    show_fee_plans: boolean;
    /** Wording only. It never decides whether registration is open. */
    button_text: string;
    background_color: string;
    offering?: PublicOffering | null;
};
