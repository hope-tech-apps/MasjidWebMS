import { PageSection } from "./PageSection";

export type Page = {
    id: number;
    masjid_id: number;
    slug: string;
    title: string;
    page_title: string | null;
    page_title_background_image_url: string | null;
    is_active: boolean;
    order: number;
    show_in_menu: boolean;
    show_as_button: boolean;
    meta_description: string | null;
    sections?: PageSection[];
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
};

export type PageMenuItem = {
    id: number;
    slug: string;
    title: string;
    order: number;
};

