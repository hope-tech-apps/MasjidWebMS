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
    | 'stats'
    | 'mission_vision'
    | 'cta';

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
    | StatsSectionContent
    | MissionVisionSectionContent
    | CTASectionContent;

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

export type CTASectionContent = {
    heading: string;
    description: string;
    button_text: string;
    button_link: string;
    button_style: 'primary' | 'secondary';
    background_image_url: string | null;
    background_color: string;
};

// Section Type Info (for admin panel)
export type SectionTypeInfo = {
    value: SectionType;
    label: string;
    description: string;
    uses_external_data: boolean;
    default_content: SectionContent;
};

