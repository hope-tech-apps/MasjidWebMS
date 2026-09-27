/**
 * GET /api/admin/masjids/{id}/pages/placeholders (Studio W2 S10,
 * App\Support\Studio\PlaceholderChecklist): what a Studio organisation's admin
 * still has to fill in on their own site. Empty (`pages: []`, both counts 0) for
 * every organisation without Studio's marker, and the page builder then draws
 * nothing.
 */
export type PlaceholderKind = 'bound' | 'review' | 'text' | 'image' | 'list';

export interface ChecklistPlaceholder {
    /** A content path in the section (`title`, `links.0.url`), or the bound row for `bound`. */
    field: string;
    kind: PlaceholderKind;
    /** The hint's key in config('studio_layouts.hints'). */
    hint: string;
    /** The hint's sentence, in the operator's words. */
    hint_text: string;
    essential: boolean;
    open: boolean;
}

export interface ChecklistSection {
    section_id: number;
    title: string;
    section_type: string;
    active: boolean;
    placeholders: ChecklistPlaceholder[];
}

export interface ChecklistPage {
    page_id: number;
    slug: string;
    title: string;
    sections: ChecklistSection[];
}

export interface PlaceholderChecklist {
    /** Open placeholders across the site, each section counted once. */
    open: number;
    /** Of those, the essential ones: a section waits inactive until they are filled. */
    essential_open: number;
    pages: ChecklistPage[];
}
