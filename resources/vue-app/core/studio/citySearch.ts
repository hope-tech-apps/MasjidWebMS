/**
 * Studio's city picker, the pure half (components/super/studio/foundation/
 * IdentityPanel.vue). A country's cities are not a select: the US alone has
 * 21,008 rows, several names more than once with no state to tell them apart,
 * and a select of them filled seconds after the country changed. The operator
 * types instead, and GET /api/admin/countries/{id}/cities?q= answers with up to
 * CITY_SEARCH_LIMIT names, each once (CountriesCitiesController::search). The
 * draft still stores the city's id.
 *
 * No imports, so tests/studio-city-picker.test.ts runs it under node.
 */

/** Fewer letters than this are not searched: one letter matches most of a country. */
export const CITY_SEARCH_MIN_CHARS = 2;

/** Quiet time after the last keystroke before the search is sent. */
export const CITY_SEARCH_DEBOUNCE_MS = 250;

/**
 * CountriesCitiesController::SEARCH_LIMIT. A full page of answers means there
 * may be more, which the picker says; it filters nothing by this.
 */
export const CITY_SEARCH_LIMIT = 50;

/** The server's `q` limit (CountryCitiesRequest::QUERY_MAX), the box's maxlength. */
export const CITY_QUERY_MAX = 100;

/** The text to search for, trimmed, or null while it is too short to send. */
export function citySearchText(typed: string): string | null {
    const text = typed.trim();
    return Array.from(text).length >= CITY_SEARCH_MIN_CHARS ? text : null;
}

/** What the picker says when a search found nothing. */
export function noCityText(query: string): string {
    return `No city starts with or contains “${query}”.`;
}

/** What the picker says under a full page of answers. */
export const MORE_CITIES_TEXT = `Showing the first ${CITY_SEARCH_LIMIT}. Type more of the name to narrow them.`;

/** The highlighted suggestion after an arrow key, wrapping at both ends; -1 is none. */
export function moveActive(active: number, count: number, key: 'ArrowDown' | 'ArrowUp'): number {
    if (count <= 0) return -1;
    if (key === 'ArrowDown') return (active + 1) % count;
    return active <= 0 ? count - 1 : active - 1;
}

/**
 * What leaving the box does. Typing is a search, not an answer: only a pick
 * sets the city. So text left without a pick goes back to the chosen city's
 * name, and a box the operator emptied means no city. A box they never typed
 * in changes nothing, even when the stored city's name could not be loaded
 * and the box is blank: focusing a field must not clear an answer.
 */
export function cityOnLeave(text: string, chosenName: string, edited: boolean): { clear: boolean; text: string } {
    if (edited && text.trim() === '') return { clear: true, text: '' };
    return { clear: false, text: chosenName };
}
