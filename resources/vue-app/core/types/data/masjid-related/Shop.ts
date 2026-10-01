/**
 * The online shop, as the ADMIN screens read it (shop slices B1 to B3):
 * /api/admin/masjids/{id}/shop/products and /shop/sales.
 *
 * MONEY IS INTEGER MINOR UNITS (cents). `base_price_minor`, `price_minor`,
 * `effective_price_minor`, `unit_minor` and `total_minor` are integers and are sent
 * back as JSON NUMBERS: the server refuses a string, a float or a boolean price.
 * Only `composables/useMinorUnits` turns them into text or a typed amount into
 * them.
 */

/** One size of a product, as an answer carries it. */
export type ShopVariant = {
    id: number;
    label: string;
    /** Off = not sold, not offered. A size an order names is never deleted, only switched off. */
    enabled: boolean;
    /** The size's own price; null = the product's price. */
    price_minor: number | null;
    /** What a buyer pays: the size's own price, else the product's. */
    effective_price_minor: number;
    /** The TOTAL put on sale, sold units included; null = unlimited. */
    stock: number | null;
    sold_count: number;
    /** Units held by pending payment pages right now ("in baskets"). */
    held: number;
    /** What can still be bought, never below zero; null = unlimited. */
    available: number | null;
    sort: number;
};

export type ShopImage = {
    id: number;
    url: string;
    name: string;
    file_name: string;
    mime_type: string;
    size: number;
    order: number;
};

/** A product with its sizes and pictures. `lock_version` is the optimistic-lock token every PUT must send back. */
export type ShopProduct = {
    id: number;
    name: string;
    slug: string;
    category: string | null;
    description: string | null;
    base_price_minor: number;
    currency: string;
    active: boolean;
    sort: number;
    variants: ShopVariant[];
    images: ShopImage[];
    lock_version: number;
    created_at?: string | null;
    updated_at?: string | null;
};

/** `meta` of the product answers: the shop itself, beside the rows. */
export type ShopMeta = {
    currency: string;
    max_images: number;
    /** The most one picture may weigh, in megabytes. Absent from an older backend. */
    max_image_mb?: number;
};

/** A size as the editor holds it while it is being edited (strings, as typed). */
export type VariantRow = {
    /** Present for a size that exists on the server; absent for a row the admin just added. */
    id?: number;
    label: string;
    enabled: boolean;
    /** Dollars as typed; '' = the product's price. */
    price: string;
    /** A count as typed; '' = unlimited. */
    stock: string;
    /** The server's numbers for a saved size, shown read-only. Absent on a new row. */
    numbers?: Pick<ShopVariant, 'stock' | 'sold_count' | 'held' | 'available'>;
};

export type ProductForm = {
    name: string;
    category: string;
    description: string;
    /** Dollars as typed. */
    price: string;
    active: boolean;
    /** Typed integer; '' = 0. */
    sort: string;
    variants: VariantRow[];
};

/** The JSON a product is saved with. Never `slug`, never `currency`. */
export type VariantBody = {
    id?: number;
    label: string;
    enabled: boolean;
    price_minor: number | null;
    stock: number | null;
    sort: number;
};

export type ProductBody = {
    name: string;
    category: string | null;
    description: string | null;
    base_price_minor: number;
    active: boolean;
    sort: number;
    variants: VariantBody[];
    /** Edits only: the value the editor loaded. */
    lock_version?: number;
};

export type SaleResolution = 'refunded' | 'substituted';
export type SaleState = 'to_hand_out' | 'collected' | 'all';
export type ChargeFlag = 'refunded' | 'partially_refunded' | 'disputed';

/** One line of the pickup list. */
export type ShopSale = {
    id: number;
    order_id: number;
    order_number: string;
    paid_at: string | null;
    buyer_name: string | null;
    buyer_email: string | null;
    buyer_phone: string | null;
    product_id: number;
    variant_id: number;
    /** The snapshot: what the buyer was sold, whatever the catalogue says now. */
    product_name: string;
    variant_label: string;
    quantity: number;
    unit_minor: number;
    total_minor: number;
    currency: string;
    collected_at: string | null;
    collected_by: { id: number; name: string | null } | null;
    oversold: boolean;
    /** Refunded or disputed: never to hand out. */
    refunded: boolean;
    charge_flag: ChargeFlag | null;
    to_hand_out: boolean;
    resolution?: SaleResolution | null;
    resolved_at?: string | null;
    resolved_by?: { id: number; name: string | null } | null;
};

/** One product-and-size line of the header, in units, over the whole organisation (the filters do not move it). */
export type SalesSummaryRow = {
    product_id: number;
    variant_id: number;
    product_name: string;
    variant_label: string;
    to_hand_out: number;
    collected: number;
    /** A count of LINES still needing a decision (oversold, not refunded, not resolved). */
    oversold_open: number;
};

export type SalesMeta = {
    state?: SaleState;
    summary: SalesSummaryRow[];
};

/** Every filter the pickup list and its CSV honour: one contract for both. */
export type SaleFilters = {
    state: SaleState;
    product_id: number | '' | null;
    variant_id: number | '' | null;
    search: string;
};
