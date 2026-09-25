/**
 * The member directory's tag decisions, as plain functions so they can be
 * pinned without a browser (resources/vue-app/tests/contact-tags.test.ts).
 * Every one is a DISPLAY answer: the server decides again — duplicate names on
 * the unique key, contact ids through the tenant scope — whatever this page did.
 */

/** The tag fields these helpers read. */
export type TagLike = { id: number; name: string };

/**
 * The comparison key the server's unique index holds
 * (App\Models\ContactTag::keyFor): trimmed, whitespace collapsed, lower-cased.
 * The page uses it only to say "that tag already exists" before a round trip.
 */
export function tagNameKey(name: string | null | undefined): string {
    return String(name ?? "").replace(/\s+/gu, " ").trim().toLowerCase();
}

/** An existing tag this name would collide with, ignoring `exceptId` (the tag being renamed). */
export function clashingTag<T extends TagLike>(tags: T[], name: string, exceptId: number | null = null): T | null {
    const key = tagNameKey(name);
    if (key === "") return null;

    return tags.find(t => t.id !== exceptId && tagNameKey(t.name) === key) ?? null;
}

/**
 * The bulk-tag body, FORM-ENCODED as `contact_ids[]=…` — ApiService posts
 * url-encoded and Laravel reads the bracketed name as an array
 * (.claude/rules/shipping.md: one encoding in the tests, another in the browser,
 * is how a green suite ships a broken form). Duplicates are dropped.
 */
export function contactIdsBody(ids: number[]): URLSearchParams {
    const body = new URLSearchParams();
    [...new Set(ids)].forEach(id => body.append("contact_ids[]", String(id)));
    return body;
}

/**
 * The page's rows that can be ticked: a DELETED member cannot be tagged (the
 * server resolves ids through the live directory and would refuse the whole
 * request), so a deleted row never enters the selection.
 */
export function selectableIds(rows: { id: number; deleted_at?: string | null }[]): number[] {
    return rows.filter(r => !r.deleted_at).map(r => r.id);
}

/** Tick or untick one row. */
export function toggleId(selected: number[], id: number): number[] {
    return selected.includes(id) ? selected.filter(x => x !== id) : [...selected, id];
}

/**
 * The header checkbox: ticks every selectable row on this page, or, when they
 * are all ticked already, unticks them. Rows selected on OTHER pages are kept
 * either way, so paging through the directory builds one selection.
 */
export function togglePage(selected: number[], pageIds: number[]): number[] {
    const allOn = pageIds.length > 0 && pageIds.every(id => selected.includes(id));
    return allOn
        ? selected.filter(id => !pageIds.includes(id))
        : [...new Set([...selected, ...pageIds])];
}

/** The tags a member does not carry yet, for the "add a tag" picker on their record. */
export function tagsNotOn<T extends TagLike>(tags: T[], carried: TagLike[] | undefined): T[] {
    const on = new Set((carried ?? []).map(t => t.id));
    return tags.filter(t => !on.has(t.id));
}
