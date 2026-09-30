/**
 * The class store's screens (T-003.4): plain functions with no Vue in them, so
 * `npm run test:spa` covers them.
 *
 * WHAT IS NOT HERE, ON PURPOSE: a sort by balance, a rank, a class total, a
 * "top students" list. The server serves every balance in roster order and never
 * ranks (App\Support\GroupAudience, .claude/rules/groups.md), and a screen that
 * sorted the roster by Bucks would rebuild the public tally this module exists to
 * refuse. Nothing here adds two children's balances together.
 *
 * Every number on screen is the server's. A balance is never worked out in the
 * browser from the history, and a failed read hides the figure rather than print
 * a 0 that says a child has nothing.
 */

export type LedgerKind = 'earned' | 'adjusted' | 'redeemed' | 'reversal' | 'cashed_out' | 'expired';

export const LEDGER_KINDS: LedgerKind[] = ['earned', 'adjusted', 'redeemed', 'reversal', 'cashed_out', 'expired'];

const KIND_LABEL: Record<LedgerKind, string> = {
    earned: 'Earned',
    adjusted: 'Adjusted',
    redeemed: 'Prize',
    reversal: 'Given back',
    cashed_out: 'Paid out on paper',
    expired: 'Expired',
};

/** The teacher's word for one ledger line; an unknown kind is shown as it came, never blank. */
export function kindLabel(kind: string | null | undefined): string {
    return KIND_LABEL[kind as LedgerKind] ?? String(kind ?? '').replace(/_/g, ' ');
}

/** "+5", "-4", "0": the same text `signedPoints` gives a single award. */
export function signedBucks(n: number | string | null | undefined): string {
    const value = Number(n ?? 0);

    return `${value > 0 ? '+' : ''}${value}`;
}

/** "1 Buck", "5 Bucks", "0 Bucks". */
export function bucksLabel(n: number | string | null | undefined): string {
    const value = Number(n ?? 0);

    return `${value} ${Math.abs(value) === 1 ? 'Buck' : 'Bucks'}`;
}

/**
 * One value per CLICK. The server treats a repeat of it as a replay of the first, so a
 * double-tap or a retry after a dropped response takes nothing twice. Matches the
 * server's /^[A-Za-z0-9_-]{8,36}$/ (a UUID, or a fallback of the same shape).
 */
export function newRequestId(): string {
    const uuid = (globalThis as any).crypto?.randomUUID?.();
    if (typeof uuid === 'string' && uuid.length >= 8) return uuid;

    let out = '';
    for (let i = 0; i < 32; i++) out += Math.floor(Math.random() * 16).toString(16);

    return out;
}

/**
 * Should a retry of a write reuse its request id? YES when the answer never arrived (no HTTP
 * response at all: a dropped connection, a timeout) or the server failed (5xx, 408): the write
 * may have committed, and only the same id turns the retry into a replay instead of a second
 * deduction. NO for any other refusal (a 4xx says it was NOT done, and a fresh attempt is a
 * fresh write).
 */
export function keepsRequestId(status: number | undefined | null): boolean {
    return status === undefined || status === null || status === 0 || status === 408 || status >= 500;
}

/**
 * One request id per WRITE, not per click: the id stays with the write (a prize for a student,
 * an amount for a student) until it is known to have finished, so tapping again after "That prize
 * could not be given" while the first request may have committed sends the SAME id and the server
 * answers with the row it already wrote. A double-tap while the first is in flight is stopped by
 * the screen's `busy` flag; this covers the retry after a lost response.
 */
export function createRequestIds(make: () => string = newRequestId) {
    const pending = new Map<string, string>();

    return {
        /** The id for this write: the one already pending, or a fresh one. */
        idFor(key: string): string {
            let id = pending.get(key);
            if (id === undefined) {
                id = make();
                pending.set(key, id);
            }

            return id;
        },
        /** The write succeeded: the next one for the same key is a new write. */
        succeeded(key: string): void {
            pending.delete(key);
        },
        /** The write failed with this HTTP status (undefined when no response came back). */
        failed(key: string, status: number | undefined | null): void {
            if (!keepsRequestId(status)) pending.delete(key);
        },
        /** For tests: how many writes are waiting on an answer. */
        get size(): number {
            return pending.size;
        },
    };
}

export interface StorePrize {
    id: number;
    scope: 'school' | 'class';
    title: string;
    description: string | null;
    cost_bucks: number;
    stock: number | null;
    in_stock: boolean;
    is_active: boolean;
    editable: boolean;
}

export interface ShelfItem extends StorePrize {
    /** May this student take it right now? */
    available: boolean;
    /** Why not, in a teacher's words, or null when it can be given. */
    why: string | null;
}

/**
 * The shelf as a student sees it FROM THE TEACHER'S CHAIR: every active prize, cheapest first
 * (the server's order), each marked with whether this student can take it and, if not, why.
 * A retired prize is not on the shelf at all (it is managed elsewhere). `balance` null means
 * the balance could not be read, and then nothing is offered rather than guessed at.
 */
export function shelfFor(prizes: StorePrize[], balance: number | null): ShelfItem[] {
    return prizes
        .filter((p) => p.is_active)
        .map((p) => {
            let why: string | null = null;

            if (!p.in_stock) why = 'Out of stock';
            else if (balance === null) why = 'Balance unavailable';
            else if (balance < p.cost_bucks) why = `Needs ${bucksLabel(p.cost_bucks - balance)} more`;

            return { ...p, available: why === null, why };
        });
}

/** How many are left, in words: unlimited stock says nothing. */
export function stockNote(p: Pick<StorePrize, 'stock'>): string {
    return p.stock === null || p.stock === undefined ? 'Unlimited' : `${p.stock} left`;
}

export interface PrizeForm {
    title: string;
    description: string;
    cost: string;
    /** Blank means unlimited (R6). */
    stock: string;
    active: boolean;
}

export const MAX_COST = 10000;
export const MAX_STOCK = 100000;

export function blankPrizeForm(): PrizeForm {
    return { title: '', description: '', cost: '', stock: '', active: true };
}

export function prizeFormFrom(p: StorePrize): PrizeForm {
    return {
        title: p.title,
        description: p.description ?? '',
        cost: String(p.cost_bucks),
        stock: p.stock === null || p.stock === undefined ? '' : String(p.stock),
        active: p.is_active,
    };
}

const wholeNumber = (v: string): number | null => (/^\d+$/.test(v.trim()) ? Number(v.trim()) : null);

/** Is the form good enough to send? The server checks it all again; this only stops an empty click. */
export function prizeFormReady(f: PrizeForm): boolean {
    const cost = wholeNumber(f.cost);
    const stock = f.stock.trim() === '' ? 0 : wholeNumber(f.stock);

    return f.title.trim() !== '' && cost !== null && cost >= 1 && cost <= MAX_COST && stock !== null && stock <= MAX_STOCK;
}

/**
 * The request body for a prize. A blank stock is sent as null, which the server reads as
 * unlimited: an empty string would do the same, but null says it in the transport's own words.
 */
export function prizeRequest(f: PrizeForm): { title: string; description: string | null; cost_bucks: number; stock: number | null; is_active: boolean } {
    const stock = f.stock.trim() === '' ? null : wholeNumber(f.stock);

    return {
        title: f.title.trim(),
        description: f.description.trim() === '' ? null : f.description.trim(),
        cost_bucks: wholeNumber(f.cost) ?? 0,
        stock,
        is_active: f.active,
    };
}

export type PrizeEditBody = Omit<ReturnType<typeof prizeRequest>, 'stock'> & { stock?: number | null; expected_stock?: number | null };

/**
 * The body for EDITING a prize. Every field goes, except the stock: that goes only when the editor
 * changed it, and then with `expected_stock`, the count the form was LOADED with. The server
 * compares it with the row under a lock and answers 409 when a prize was given meanwhile, so a
 * number typed on a stale screen can never put a given prize back on the shelf. `loaded` must be
 * the prize as it was when the form opened, not a later reload of the list.
 */
export function prizeEditRequest(f: PrizeForm, loaded: Pick<StorePrize, 'stock'>): PrizeEditBody {
    const { stock, ...rest } = prizeRequest(f);
    const was = loaded.stock ?? null;

    return stock === was ? rest : { ...rest, stock, expected_stock: was };
}

/**
 * One more page of a newest-first history appended to what is shown, without showing a line
 * twice: a line written since the first page was read pushes the pages along by one, and the
 * next page then starts with a line already on screen.
 */
export function appendPage<T extends { id: number }>(shown: T[], page: T[]): T[] {
    const seen = new Set(shown.map((e) => e.id));

    return shown.concat(page.filter((e) => !seen.has(e.id)));
}

/** "2 x 20, 1 x 5, 2 x 1" for the paper notes, zeros left out; "none" for an empty set. */
export function breakdownLine(b: Record<string, number> | null | undefined): string {
    const parts = Object.entries(b ?? {})
        .map(([note, n]) => [Number(note), Number(n)] as const)
        .filter(([, n]) => n > 0)
        .sort((x, y) => y[0] - x[0])
        .map(([note, n]) => `${n} x ${note}`);

    return parts.length ? parts.join(', ') : 'none';
}

/** 'YYYY-MM-DD' as "Oct 4", read as a calendar day (no browser-zone drift). */
export function dayLabel(iso: string | null | undefined): string {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso ?? ''));
    if (!m) return '';

    const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));

    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });
}

/** What a ledger line says it was for: the prize, the week, or the plain kind. */
export function entryText(e: { kind: string; prize_title?: string | null; week_start?: string | null; breakdown?: Record<string, number> | null }): string {
    switch (e.kind) {
        case 'redeemed':
            return e.prize_title ? `Prize: ${e.prize_title}` : 'Prize';
        case 'reversal':
            return e.prize_title ? `Given back: ${e.prize_title}` : 'Given back';
        case 'earned':
            return e.week_start ? `Earned, week of ${dayLabel(e.week_start)}` : 'Earned';
        case 'adjusted':
            return e.week_start ? `Adjusted, week of ${dayLabel(e.week_start)}` : 'Adjusted';
        case 'cashed_out':
            return `Paid out on paper (${breakdownLine(e.breakdown)})`;
        case 'expired':
            return 'Expired at the end of the class or year';
        default:
            return kindLabel(e.kind);
    }
}

/** True when a reason from the server means "the store is not switched on / not allowed here". */
export function isRefusedByGate(status: number | undefined): boolean {
    return status === 403;
}
