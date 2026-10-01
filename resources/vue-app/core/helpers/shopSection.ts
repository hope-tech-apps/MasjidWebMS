/**
 * What the page-builder `shop` section editor reads and writes (ShopSectionEditor.vue).
 *
 * The bounds are the server's (App\Http\Requests\Concerns\ValidatesShopSection: heading 120
 * characters, category 60, max_items a whole number 1 to 24), so the editor never offers a
 * value the save would refuse.
 */

export const SHOP_DEFAULT_MAX_ITEMS = 8;
export const SHOP_MIN_ITEMS = 1;
export const SHOP_MAX_ITEMS = 24;
export const SHOP_HEADING_MAX = 120;
export const SHOP_CATEGORY_MAX = 60;

/**
 * How many products to show, as a whole number from 1 to 24. Anything that is not a finite
 * number is the default (8); a number outside the bounds is pulled to the nearest one, and a
 * fraction is cut to a whole number.
 */
export function clampShopMaxItems(value: unknown): number {
    const n = typeof value === 'string' && value.trim() !== '' ? Number(value) : value;

    if (typeof n !== 'number' || !Number.isFinite(n)) {
        return SHOP_DEFAULT_MAX_ITEMS;
    }

    return Math.min(SHOP_MAX_ITEMS, Math.max(SHOP_MIN_ITEMS, Math.trunc(n)));
}

/**
 * The organisation's distinct product categories, from the admin products list
 * (GET /api/admin/masjids/{id}/shop/products?per_page=100): sorted, with each kept exactly as
 * stored, because the renderer compares the section's category to a product's by equality.
 *
 * `body` is the response body. The list is read from `body.data`, or from `body.data.data`
 * when the endpoint paginates, so either shape works. Returns null when the body carries no
 * product list at all, which the editor treats as "categories unavailable" and falls back to
 * a text input; an empty list is a real answer (a shop with no categories yet) and returns [].
 *
 * A category over 60 characters is left out: the server would refuse to save it, so offering
 * it would be a choice the admin cannot make.
 */
export function shopCategoriesFrom(body: unknown): string[] | null {
    const data = (body as { data?: unknown } | null | undefined)?.data;
    const rows = Array.isArray(data) ? data : (data as { data?: unknown } | null | undefined)?.data;

    if (!Array.isArray(rows)) {
        return null;
    }

    const seen = new Set<string>();

    for (const row of rows) {
        const category = (row as { category?: unknown } | null | undefined)?.category;

        if (typeof category === 'string' && category.trim() !== '' && category.length <= SHOP_CATEGORY_MAX) {
            seen.add(category);
        }
    }

    return [...seen].sort((a, b) => a.localeCompare(b));
}

/**
 * Section types that may be placed on the WEBSITE only. A shop section is one for now: the native
 * apps have not been checked against a section type they do not know, and the server refuses a
 * placement that would show one there (PageSectionsController::SHOP_WEB_ONLY).
 */
export const WEB_ONLY_SECTION_TYPES: readonly string[] = ['shop'];

export function isWebOnlySectionType(type: unknown): boolean {
    return typeof type === 'string' && WEB_ONLY_SECTION_TYPES.includes(type);
}

/** The one placement such a section takes. A fresh array each time: the form mutates it. */
export function webOnlyPlatforms(): string[] {
    return ['web'];
}
