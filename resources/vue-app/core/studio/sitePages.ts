/**
 * Which starter pages the website mockup (components/super/studio/preview/
 * WebFrame.vue) puts in its header and footer: the ones the live site will.
 *
 * The public site is served only active pages (PagesController, `->active()`),
 * so a page StarterSite::plan() writes inactive (none of its sections is live
 * yet, for example while its facts are still to be filled in) is in no menu
 * there, and must be in none here, or the operator approves a layout the
 * client never gets. A page's button is already gated on it: the plan sets
 * `show_as_button` only on an active page.
 *
 * Only `import type`, so tests/studio-site-pages.test.ts runs it under node.
 */
import type { StudioPlanPage } from "@/core/types/data/Studio";

/** The pages the site's menu and footer list, in the order planned. */
export function menuPages(pages: readonly StudioPlanPage[]): StudioPlanPage[] {
    return pages.filter((page) => page.is_active && page.show_in_menu && !page.show_as_button);
}

/** The pages the site's header offers as a button. */
export function buttonPages(pages: readonly StudioPlanPage[]): StudioPlanPage[] {
    return pages.filter((page) => page.is_active && page.show_as_button);
}
