/**
 * The online shop's admin screens (shop slice B3): plain functions with no Vue in them, so
 * `npm run test:spa` covers them.
 *
 * Two things are INJECTED rather than imported, because this file must run under node's type
 * stripping (no `@/` alias there) and because each already has exactly one home:
 *   - money: `composables/useMinorUnits` is the only place that turns a typed amount into
 *     integer cents (a `PriceCodec` carries it in);
 *   - errors: `core/helpers/serverMessage` reads the server's words and its dotted 422 keys
 *     (an `ErrorReader` carries it in).
 *
 * Money out is always an integer, sent as a JSON NUMBER: the server refuses a string, a float or
 * a boolean price. Stock is a count or null (unlimited). Nothing here ever sends `slug` or
 * `currency`.
 */
import type {
    ProductBody,
    ProductForm,
    SaleFilters,
    SaleState,
    SalesSummaryRow,
    ShopImage,
    ShopMeta,
    ShopProduct,
    ShopSale,
    ShopVariant,
    VariantBody,
    VariantRow,
} from '@/core/types/data/masjid-related/Shop';

// ------------------------------------------------------------------------------------------ money

export type MinorParse = { ok: true; minor: number } | { ok: false; reason: string };

/** What the screen needs to know about money: how many decimals the currency has, and how to parse. */
export type PriceCodec = {
    exponent: number;
    parse: (text: string) => MinorParse;
};

/**
 * Integer minor units back into the text an input shows ("2500" cents is "25.00"). Integer
 * arithmetic on the digits, never a division, so 2999 is "29.99" and not "29.990000000000002".
 */
export function minorToMajorText(minor: number, exponent: number): string {
    if (!Number.isSafeInteger(minor) || minor < 0) return '';
    if (exponent <= 0) return String(minor);

    const digits = String(minor).padStart(exponent + 1, '0');

    return `${digits.slice(0, -exponent)}.${digits.slice(-exponent)}`;
}

/** A price the admin typed: a valid amount of at least one minor unit. */
export function parsePrice(text: string, codec: PriceCodec): MinorParse {
    const parsed = codec.parse(text);
    if (!parsed.ok) return parsed;
    if (parsed.minor < 1) return { ok: false, reason: 'A price must be more than zero.' };

    return parsed;
}

/** Stock as typed: empty is unlimited (null); otherwise a whole number, zero or more. */
export function parseStock(text: string): { ok: true; value: number | null } | { ok: false; reason: string } {
    const raw = String(text ?? '').trim();
    if (raw === '') return { ok: true, value: null };
    if (!/^\d+$/.test(raw)) return { ok: false, reason: 'Stock must be a whole number, or empty for unlimited.' };

    const value = Number(raw);
    if (!Number.isSafeInteger(value)) return { ok: false, reason: 'That is more stock than can be recorded.' };

    return { ok: true, value };
}

/** The sort order as typed: empty is 0; otherwise a whole number, negative allowed. */
export function parseSort(text: string): { ok: true; value: number } | { ok: false; reason: string } {
    const raw = String(text ?? '').trim();
    if (raw === '') return { ok: true, value: 0 };
    if (!/^-?\d+$/.test(raw)) return { ok: false, reason: 'The order must be a whole number.' };

    const value = Number(raw);
    if (!Number.isSafeInteger(value)) return { ok: false, reason: 'That number is too large.' };

    return { ok: true, value };
}

/**
 * The symbol an input shows beside a price ("$" for usd), from Intl. A code Intl does not know is
 * shown as the code itself, so the input never claims a currency it cannot name.
 */
export function currencySymbol(currency: string | null | undefined): string {
    const code = String(currency ?? '').trim().toUpperCase() || 'USD';

    try {
        const parts = new Intl.NumberFormat(undefined, { style: 'currency', currency: code, currencyDisplay: 'narrowSymbol' }).formatToParts(0);

        return parts.find((part) => part.type === 'currency')?.value ?? code;
    } catch {
        return code;
    }
}

// --------------------------------------------------------------------------------- the editor form

let rowCounter = 0;

/** A row key that is stable while the row moves; never sent. */
function nextRowKey(): string {
    rowCounter += 1;

    return `row-${rowCounter}`;
}

/** A size row as the editor holds it. `key` is the screen's own, never sent. */
export type EditorRow = VariantRow & { key: string };

export type EditorForm = Omit<ProductForm, 'variants'> & { variants: EditorRow[] };

export function blankVariantRow(): EditorRow {
    return { key: nextRowKey(), label: '', enabled: true, price: '', stock: '' };
}

export function blankProductForm(): EditorForm {
    return { name: '', category: '', description: '', price: '', active: true, sort: '0', variants: [] };
}

function rowFromVariant(variant: ShopVariant, exponent: number): EditorRow {
    return {
        key: nextRowKey(),
        id: variant.id,
        label: variant.label,
        enabled: variant.enabled,
        price: variant.price_minor === null ? '' : minorToMajorText(variant.price_minor, exponent),
        stock: variant.stock === null ? '' : String(variant.stock),
        numbers: {
            stock: variant.stock,
            sold_count: variant.sold_count,
            held: variant.held,
            available: variant.available,
        },
    };
}

/** The editor's fields from a product answer. */
export function formFromProduct(product: ShopProduct, exponent: number): EditorForm {
    return {
        name: product.name,
        category: product.category ?? '',
        description: product.description ?? '',
        price: minorToMajorText(product.base_price_minor, exponent),
        active: product.active,
        sort: String(product.sort),
        variants: (product.variants ?? []).map((variant) => rowFromVariant(variant, exponent)),
    };
}

/** Flat dotted keys, as the server names them: `name`, `base_price_minor`, `variants.0.label`. */
export type FieldErrors = Record<string, string[]>;

export type BuildResult = { ok: true; body: ProductBody } | { ok: false; errors: FieldErrors };

/**
 * The JSON a product is saved with, or the problems with what was typed, keyed exactly as the
 * server keys its own 422 so both are shown by the same lookup.
 *
 *   - the price is dollars typed, converted by the codec to integer cents; an empty size price is
 *     `null` (the product's price) and an empty stock is `null` (unlimited), both sent EXPLICITLY:
 *     an absent key would leave a size's old value alone, and the admin who cleared the field
 *     would find it unchanged;
 *   - `variants` is the WHOLE list: a row with an `id` edits, a row without adds, and a size left
 *     out is removed. Each row's `sort` is its place in the list, so a reorder is saved;
 *   - `lock_version` is sent on an edit, the value the editor loaded, and never on a create.
 */
export function buildProductRequest(form: EditorForm, codec: PriceCodec, lockVersion: number | null = null): BuildResult {
    const errors: FieldErrors = {};
    const add = (key: string, message: string) => {
        errors[key] = [...(errors[key] ?? []), message];
    };

    const name = form.name.trim();
    if (name === '') add('name', 'A product needs a name.');

    const price = form.price.trim() === '' ? ({ ok: false, reason: 'A product needs a price.' } as MinorParse) : parsePrice(form.price, codec);
    if (!price.ok) add('base_price_minor', price.reason);

    const sort = parseSort(form.sort);
    if (!sort.ok) add('sort', sort.reason);

    const variants: VariantBody[] = [];
    form.variants.forEach((row, index) => {
        const label = row.label.trim();
        if (label === '') add(`variants.${index}.label`, 'Every size needs a name.');

        let priceMinor: number | null = null;
        if (row.price.trim() !== '') {
            const own = parsePrice(row.price, codec);
            if (own.ok) priceMinor = own.minor;
            else add(`variants.${index}.price_minor`, own.reason);
        }

        const stock = parseStock(row.stock);
        if (!stock.ok) add(`variants.${index}.stock`, stock.reason);

        variants.push({
            ...(row.id !== undefined ? { id: row.id } : {}),
            label,
            enabled: row.enabled,
            price_minor: priceMinor,
            stock: stock.ok ? stock.value : null,
            sort: index,
        });
    });

    if (Object.keys(errors).length > 0 || !price.ok || !sort.ok) return { ok: false, errors };

    const body: ProductBody = {
        name,
        category: form.category.trim() === '' ? null : form.category.trim(),
        description: form.description.trim() === '' ? null : form.description.trim(),
        base_price_minor: price.minor,
        active: form.active,
        sort: sort.value,
        variants,
    };

    if (lockVersion !== null) body.lock_version = lockVersion;

    return { ok: true, body };
}

/** The sizes the product had when it loaded that the form no longer lists: saving removes them. */
export function removedVariants(loaded: readonly Pick<ShopVariant, 'id' | 'label'>[] | null | undefined, form: Pick<EditorForm, 'variants'>): { id: number; label: string }[] {
    const kept = new Set(form.variants.filter((row) => row.id !== undefined).map((row) => row.id as number));

    return (loaded ?? []).filter((variant) => !kept.has(variant.id)).map((variant) => ({ id: variant.id, label: variant.label }));
}

/** The sentence a confirm dialog shows before a save that removes sizes. */
export function removalWarning(removed: readonly { label: string }[]): string {
    const names = removed.map((variant) => `"${variant.label}"`).join(', ');
    const noun = removed.length === 1 ? 'this size' : 'these sizes';

    return `Saving removes ${noun} from the shop: ${names}. Paid orders keep their own record of ${removed.length === 1 ? 'it' : 'them'}.`;
}

/** Move one entry of a list from one place to another, returning a new list. Out of range returns a copy. */
export function moveItem<T>(list: readonly T[], from: number, to: number): T[] {
    const copy = [...list];
    if (from < 0 || from >= copy.length || to < 0 || to >= copy.length || from === to) return copy;

    const [item] = copy.splice(from, 1);
    copy.splice(to, 0, item);

    return copy;
}

// ---------------------------------------------------------------------------- the server's numbers

/** "Total 20 · sold 12 · in baskets 1 · left 7". An unlimited size reads "Total unlimited" and "left Unlimited". */
export function stockLine(numbers: Pick<ShopVariant, 'stock' | 'sold_count' | 'held' | 'available'>): string {
    const total = numbers.stock === null ? 'unlimited' : String(numbers.stock);
    const left = numbers.available === null ? 'Unlimited' : String(numbers.available);

    return `Total ${total} · sold ${numbers.sold_count} · in baskets ${numbers.held} · left ${left}`;
}

export type SizeChip = { key: number; label: string; state: 'on' | 'off' | 'sold_out'; note: string };

/** A size as a chip on the list: its name, and an off or sold-out state. Unlimited is never sold out. */
export function sizeChips(product: Pick<ShopProduct, 'variants'>): SizeChip[] {
    return (product.variants ?? []).map((variant) => {
        if (!variant.enabled) return { key: variant.id, label: variant.label, state: 'off', note: 'off' };
        if (variant.available === 0) return { key: variant.id, label: variant.label, state: 'sold_out', note: 'sold out' };

        return { key: variant.id, label: variant.label, state: 'on', note: '' };
    });
}

/**
 * The price a list row shows: the product's, or "from $X" when the sizes a buyer can pick cost
 * different amounts. Only enabled sizes count, as a switched-off size cannot be bought.
 */
export function priceLabel(product: Pick<ShopProduct, 'base_price_minor' | 'variants'>, format: (minor: number) => string): string {
    const prices = (product.variants ?? []).filter((variant) => variant.enabled).map((variant) => variant.effective_price_minor);
    if (prices.length === 0) return format(product.base_price_minor);

    const lowest = Math.min(...prices);
    const highest = Math.max(...prices);

    return lowest === highest ? format(lowest) : `from ${format(lowest)}`;
}

// ------------------------------------------------------------------------------------ the failures

/** What a failed write means for the screen. */
export type Failure =
    | { kind: 'conflict'; message: string }
    | { kind: 'invalid'; message: string; fields: FieldErrors }
    | { kind: 'forbidden'; message: string }
    | { kind: 'not_found'; message: string }
    | { kind: 'other'; message: string };

/** `serverMessage` and `serverFieldErrors` of core/helpers/serverMessage, carried in. */
export type ErrorReader = {
    message: (error: any, fallback: string) => string;
    fields: (error: any) => FieldErrors;
};

/**
 * Which kind of failure this is, by HTTP status:
 *   409  somebody else changed the product (the screen offers Reload, and never retries);
 *   422  validation: `fields` keyed by dotted name;
 *   403  the shop is not switched on, or the person may not;
 *   404  not this organisation's, or gone.
 */
export function classifyFailure(error: any, fallback: string, read: ErrorReader): Failure {
    const status = error?.response?.status;
    const message = read.message(error, fallback);

    if (status === 409) return { kind: 'conflict', message };
    if (status === 422) return { kind: 'invalid', message, fields: read.fields(error) };
    if (status === 403) return { kind: 'forbidden', message };
    if (status === 404) return { kind: 'not_found', message };

    return { kind: 'other', message };
}

/** The messages for one key. */
export function errorsFor(fields: FieldErrors, key: string): string[] {
    return fields[key] ?? [];
}

export type RowErrors = { label: string[]; price: string[]; stock: string[]; other: string[] };

/** Everything the server said about one size row, by the row's place in the list. */
export function rowErrors(fields: FieldErrors, index: number): RowErrors {
    const at = (suffix: string) => errorsFor(fields, `variants.${index}.${suffix}`);

    return {
        label: at('label'),
        price: at('price_minor'),
        stock: at('stock'),
        other: [...at('id'), ...at('enabled'), ...at('sort')],
    };
}

const PLACED = /^(name|category|description|base_price_minor|active|sort|variants|variants\.\d+\.(label|price_minor|stock|enabled|sort|id))$/;

/** Messages that no field or row on the editor shows, so none is lost (an unknown key, `lock_version`, `images`). */
export function unplacedErrors(fields: FieldErrors): string[] {
    return Object.entries(fields)
        .filter(([key]) => !PLACED.test(key))
        .flatMap(([, messages]) => messages);
}

// --------------------------------------------------------------------------------------- pictures

export const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
export const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
/** The most one picture may weigh, in megabytes, when the server names none. */
export const DEFAULT_MAX_IMAGE_MB = 10;

export type ImageLimits = { maxImages: number; maxMb: number };

export function imageLimits(meta: Partial<ShopMeta> | null | undefined): ImageLimits {
    const mb = Number(meta?.max_image_mb);

    return {
        maxImages: Number(meta?.max_images) > 0 ? Number(meta?.max_images) : 8,
        maxMb: Number.isFinite(mb) && mb > 0 ? mb : DEFAULT_MAX_IMAGE_MB,
    };
}

/** "Up to 8 pictures, 10 MB each". */
export function imageHint(limits: ImageLimits): string {
    return `Up to ${limits.maxImages} pictures, ${limits.maxMb} MB each. JPG, PNG, GIF or WebP.`;
}

export type FileLike = { name: string; size: number; type: string };

/**
 * Whether these files may go up, before they do: a type and a name the server accepts, no file
 * over the size, and not more than the product has room for. Judged as a whole, as the server
 * judges it, so a list with one bad file uploads nothing and says which.
 */
export function checkImageFiles(files: readonly FileLike[], limits: ImageLimits, existing: number): string[] {
    const problems: string[] = [];
    const maxBytes = limits.maxMb * 1024 * 1024;

    if (files.length === 0) return ['Choose at least one picture.'];

    files.forEach((file) => {
        const extension = file.name.includes('.') ? file.name.split('.').pop()!.toLowerCase() : '';

        if (!IMAGE_EXTENSIONS.includes(extension) || (file.type !== '' && !IMAGE_MIME_TYPES.includes(file.type))) {
            problems.push(`${file.name}: a picture must be a JPG, PNG, GIF or WebP image.`);
        } else if (file.size > maxBytes) {
            problems.push(`${file.name}: a picture can be at most ${limits.maxMb} MB.`);
        }
    });

    if (existing + files.length > limits.maxImages) {
        problems.push(`A product can have at most ${limits.maxImages} pictures; this one has ${existing} and you chose ${files.length}.`);
    }

    return problems;
}

/** The body of the reorder call: every picture id, in the order wanted. */
export function imageOrderBody(images: readonly Pick<ShopImage, 'id'>[]): { order: number[] } {
    return { order: images.map((image) => image.id) };
}

// ------------------------------------------------------------------------------------- the pickup list

/** What a picture answer says the product's version is, and what the editor does with it. */
export interface PictureAnswerVersion {
    /** The version the editor's next Save must send. */
    lockVersion: number;
    /** A colleague changed the product between this editor's load and its picture action. */
    changedElsewhere: boolean;
}

/**
 * A picture upload, reorder or delete moves `lock_version` on by EXACTLY one
 * (ProductWriter::bumpVersion), and its answer carries the new value. So the editor adopts the
 * answer's version only when it is the one it held plus one: that step is its own. Any other
 * value means somebody else saved the product in between; adopting it would let this editor's next
 * Save pass the stale check and silently overwrite their price or sizes. The held version is kept
 * instead (so that Save answers 409) and the editor says so at once.
 */
export function pictureAnswerVersion(held: number, answered: number): PictureAnswerVersion {
    if (Number.isInteger(held) && Number.isInteger(answered) && answered === held + 1) {
        return { lockVersion: answered, changedElsewhere: false };
    }

    return { lockVersion: held, changedElsewhere: true };
}

/**
 * Whether a pager event should load a page. The shared Pagination emits the page it STARTS on as it
 * mounts, and can emit a page below 1 before the list has loaded; the server refuses page 0 (422),
 * which replaced a list that had just loaded with an error.
 */
export function shouldLoadPage(toPage: number, currentPage: number): boolean {
    return Number.isInteger(toPage) && toPage >= 1 && toPage !== currentPage;
}

export const SALE_TABS: { state: SaleState; label: string }[] = [
    { state: 'to_hand_out', label: 'To hand out' },
    { state: 'collected', label: 'Collected' },
    { state: 'all', label: 'All' },
];

export function blankSaleFilters(): SaleFilters {
    return { state: 'to_hand_out', product_id: '', variant_id: '', search: '' };
}

/**
 * The one place the pickup filters become a query string, so the list and the CSV can never describe
 * different sales. Blank values are left out (`search=` or `product_id=` would be a 422 or a
 * pointless filter); `state` is always sent, because the server's default is to_hand_out.
 * `page` and `per_page` belong to the list only.
 */
export function salesQuery(filters: SaleFilters, page: number | null = null, perPage: number | null = null): string {
    const params = new URLSearchParams();

    params.append('state', filters.state);
    if (filters.product_id !== '' && filters.product_id !== null) params.append('product_id', String(filters.product_id));
    if (filters.variant_id !== '' && filters.variant_id !== null) params.append('variant_id', String(filters.variant_id));
    if (filters.search.trim() !== '') params.append('search', filters.search.trim());
    if (perPage !== null) params.append('per_page', String(perPage));
    if (page !== null) params.append('page', String(page));

    return params.toString();
}

export function shopBase(masjidId: number | string): string {
    return `/api/admin/masjids/${masjidId}/shop`;
}

export function salesListPath(masjidId: number | string, filters: SaleFilters, page: number, perPage: number | null = null): string {
    return `${shopBase(masjidId)}/sales?${salesQuery(filters, page, perPage)}`;
}

/** The CSV covers the whole filtered list, never the page on screen: no `page`, no `per_page`. */
export function salesCsvPath(masjidId: number | string, filters: SaleFilters): string {
    return `${shopBase(masjidId)}/sales.csv?${salesQuery(filters)}`;
}

/** The fetch for the CSV: a bearer token, because a plain link would carry none. */
export function salesCsvRequest(masjidId: number | string, filters: SaleFilters, token: string | null): { url: string; init: { headers: Record<string, string> } } {
    return {
        url: salesCsvPath(masjidId, filters),
        init: { headers: { Authorization: `Bearer ${token}`, Accept: 'text/csv' } },
    };
}

/** The name the SERVER chose for the download (it carries the organisation), else a dated default. */
export function csvFilename(contentDisposition: string | null, today: string): string {
    return contentDisposition?.match(/filename="?([^";]+)"?/i)?.[1] ?? `shop-sales-${today}.csv`;
}

export type Tone = 'success' | 'warning' | 'danger' | 'secondary' | 'info';

export type SaleStatus = {
    collected: boolean;
    /** The order's money went back, or is disputed: never hand out. */
    refundedOrDisputed: boolean;
    disputed: boolean;
    partlyRefunded: boolean;
    resolvedRefunded: boolean;
    resolvedSubstituted: boolean;
    /** Oversold, nobody decided yet, not collected and not refunded: a decision is still owed. */
    oversoldOpen: boolean;
    /** Still to be handed to the buyer. A row resolved "refunded" is not; "substituted" still is. */
    toHandOut: boolean;
    canCollect: boolean;
    canUndoCollect: boolean;
    canResolve: boolean;
    canUndoResolve: boolean;
    state: 'collected' | 'to_hand_out' | 'do_not_hand_out';
    stateLabel: string;
    badges: { text: string; tone: Tone }[];
};

/**
 * Everything the screen needs to know about one line, derived in one place.
 *
 *   refunded true         never hand out; the badge says "Refunded", or "Disputed" for a dispute (the
 *                         server counts a dispute under `refunded`, and no money has gone back);
 *   partially_refunded    still to hand out, with "Partly refunded: check Stripe before handing out",
 *                         because the order cannot say which line was refunded;
 *   oversold, no decision the red banner and the two decisions. A refunded order needs none, as the
 *                         server's own header counts only oversold lines that are not refunded.
 *
 * `to_hand_out` from the server is trusted when present, and a "refunded" resolution takes the line
 * out of it either way.
 */
export function saleStatus(row: Pick<ShopSale, 'collected_at' | 'refunded' | 'charge_flag' | 'oversold' | 'to_hand_out' | 'resolution'>): SaleStatus {
    const collected = !!row.collected_at;
    const refundedOrDisputed = row.refunded === true;
    const disputed = row.charge_flag === 'disputed';
    const partlyRefunded = row.charge_flag === 'partially_refunded';
    const resolvedRefunded = row.resolution === 'refunded';
    const resolvedSubstituted = row.resolution === 'substituted';
    const oversoldOpen = row.oversold === true && !row.resolution && !collected && !refundedOrDisputed;
    const toHandOut = (row.to_hand_out ?? (!collected && !refundedOrDisputed)) && !resolvedRefunded && !collected;

    const badges: SaleStatus['badges'] = [];
    if (disputed) badges.push({ text: 'Disputed', tone: 'danger' });
    else if (refundedOrDisputed) badges.push({ text: 'Refunded', tone: 'secondary' });
    if (partlyRefunded) badges.push({ text: 'Partly refunded: check Stripe before handing out', tone: 'warning' });
    if (resolvedRefunded) badges.push({ text: 'Oversold: refunded', tone: 'secondary' });
    if (resolvedSubstituted) badges.push({ text: 'Oversold: substituted', tone: 'info' });

    const state = collected ? 'collected' : toHandOut ? 'to_hand_out' : 'do_not_hand_out';

    return {
        collected,
        refundedOrDisputed,
        disputed,
        partlyRefunded,
        resolvedRefunded,
        resolvedSubstituted,
        oversoldOpen,
        toHandOut,
        canCollect: toHandOut,
        canUndoCollect: collected,
        canResolve: oversoldOpen,
        canUndoResolve: !!row.resolution,
        state,
        stateLabel: state === 'collected' ? 'Collected' : state === 'to_hand_out' ? 'To hand out' : 'Do not hand out',
        badges,
    };
}

/** Put a row as the server now has it into the list; a row that is not on this page is left out. */
export function replaceSale(rows: readonly ShopSale[], updated: ShopSale): ShopSale[] {
    return rows.map((row) => (row.id === updated.id ? { ...row, ...updated } : row));
}

export function summaryTotals(rows: readonly SalesSummaryRow[]): { to_hand_out: number; collected: number; oversold_open: number } {
    return rows.reduce(
        (sum, row) => ({
            to_hand_out: sum.to_hand_out + row.to_hand_out,
            collected: sum.collected + row.collected,
            // A backend that has not yet named this column adds nothing, never NaN.
            oversold_open: sum.oversold_open + (row.oversold_open ?? 0),
        }),
        { to_hand_out: 0, collected: 0, oversold_open: 0 }
    );
}

/** The products the filter offers, from the header's own rows: one entry per product id. */
export function productOptions(rows: readonly SalesSummaryRow[]): { id: number; name: string }[] {
    const seen = new Set<number>();
    const out: { id: number; name: string }[] = [];

    rows.forEach((row) => {
        if (seen.has(row.product_id)) return;
        seen.add(row.product_id);
        out.push({ id: row.product_id, name: row.product_name });
    });

    return out;
}

/** The sizes the filter offers, from the header's rows: one entry per size id, of the chosen product when one is. */
export function sizeOptions(rows: readonly SalesSummaryRow[], productId: number | '' | null): { id: number; label: string }[] {
    const seen = new Set<number>();
    const out: { id: number; label: string }[] = [];

    rows.forEach((row) => {
        if (productId !== '' && productId !== null && row.product_id !== productId) return;
        if (seen.has(row.variant_id)) return;
        seen.add(row.variant_id);
        out.push({ id: row.variant_id, label: productId === '' || productId === null ? `${row.product_name}: ${row.variant_label}` : row.variant_label });
    });

    return out;
}

/**
 * An instant as the organisation's own clock reads it ("Oct 8, 2026, 7:04 PM EDT"). A time zone the
 * browser does not know falls back to the browser's own; an unreadable instant is shown as it came.
 */
export function formatWhen(iso: string | null | undefined, timeZone: string | null | undefined): string {
    if (!iso) return '—';

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return iso;

    const base: Intl.DateTimeFormatOptions = { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' };

    try {
        return new Intl.DateTimeFormat(undefined, timeZone ? { ...base, timeZone } : base).format(date);
    } catch {
        return new Intl.DateTimeFormat(undefined, base).format(date);
    }
}
