/**
 * The Payment Methods screen's draft, as pure functions so npm run test:spa can pin
 * them (tests/payment-methods.test.ts). PaymentMethodsController owns the truth:
 * it replaces the whole set on save and validates every row again.
 *
 * The server sends the SAVED rows (in the order the public sees them) and the
 * vocabulary to choose from (App\Support\PaymentMethods::KEYS). The screen shows
 * every method once: the saved ones first, ticked, in their order; then the rest,
 * unticked, in the vocabulary's order.
 */

export interface SavedMethod { method: string; label?: string | null; instructions?: string | null }
export interface CatalogueMethod { method: string; label: string; online?: boolean }

export interface DraftRow {
    method: string;
    /** The vocabulary's own word, shown as the row's name. */
    name: string;
    online: boolean;
    accepted: boolean;
    /** The organisation's own label; required in practice for "other". */
    label: string;
    instructions: string;
}

export function draftFrom(saved: SavedMethod[] | null | undefined, catalogue: CatalogueMethod[] | null | undefined): DraftRow[] {
    const words = new Map((catalogue ?? []).map((c) => [c.method, c]));
    const rows: DraftRow[] = [];
    const seen = new Set<string>();

    for (const s of saved ?? []) {
        // A saved method the vocabulary no longer names is not offered back, so a
        // save cannot resend a value the server would refuse.
        const word = words.get(s.method);
        if (!word || seen.has(s.method)) continue;
        seen.add(s.method);
        rows.push({ method: s.method, name: word.label, online: !!word.online, accepted: true, label: s.label ?? '', instructions: s.instructions ?? '' });
    }

    for (const c of catalogue ?? []) {
        if (seen.has(c.method)) continue;
        rows.push({ method: c.method, name: c.label, online: !!c.online, accepted: false, label: '', instructions: '' });
    }

    return rows;
}

/** Move a row up (-1) or down (+1); out of range leaves the list as it is. */
export function moved(rows: DraftRow[], index: number, delta: -1 | 1): DraftRow[] {
    const target = index + delta;
    if (index < 0 || index >= rows.length || target < 0 || target >= rows.length) return rows;
    const next = rows.slice();
    [next[index], next[target]] = [next[target], next[index]];
    return next;
}

/**
 * The PUT body: ONLY the ticked rows, in screen order, trimmed. Always an object
 * with a `methods` list — the server refuses a body without one, so nothing but a
 * deliberate empty list can clear the set.
 */
export function saveBody(rows: DraftRow[]): { methods: { method: string; label: string | null; instructions: string | null }[] } {
    return {
        methods: rows
            .filter((r) => r.accepted)
            .map((r) => ({
                method: r.method,
                label: r.label.trim() === '' ? null : r.label.trim(),
                instructions: r.instructions.trim() === '' ? null : r.instructions.trim(),
            })),
    };
}

/** What must be fixed before saving, or null. "Other" says nothing to a customer without a name. */
export function draftProblem(rows: DraftRow[]): string | null {
    const other = rows.find((r) => r.accepted && r.method === 'other');
    if (other && other.label.trim() === '') return 'Give "Other" a name, such as PayPal, so customers know what it is.';
    return null;
}
