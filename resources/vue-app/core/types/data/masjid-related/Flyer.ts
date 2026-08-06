// Flyer Studio. Two different shapes meet in this file and they are deliberately not
// the same thing:
//
//   - the DESIGN — a manifest + a self-contained HTML file under
//     resources/flyer-templates, bundled into this app at build time and rendered in
//     the browser. It is the source of truth for what the editor asks for and how the
//     flyer looks.
//   - the ROW — App\Models\Flyer — which stores only the filled slot values plus the
//     palette as it resolved at save time, so a template correction (or a rebrand)
//     never rewrites a flyer the board has already approved.
//
// Rendering is client-side because it has to be: the droplet has no Node, and DomPDF
// cannot do flexbox, -webkit-text-stroke or Arabic shaping. See
// resources/flyer-templates/README.md.

/**
 * `import.meta.glob` is a Vite build-time transform, not a runtime call, and this
 * project's tsconfig never references `vite/client` — so the compiler does not know
 * the member exists. Declared here rather than cast at the call site: a cast would
 * have to swallow the whole expression, and the glob has to survive as a literal
 * `import.meta.glob(...)` for Vite to rewrite it at all.
 *
 * Only the eager form is described, because that is the only form the Studio uses.
 */
declare global {
    interface ImportMeta {
        glob<T = unknown>(
            pattern: string,
            options?: { eager?: boolean; query?: string; import?: string }
        ): Record<string, T>;
    }
}

/** Design families. Mirrors App\Models\FlyerTemplate::KINDS. */
export type FlyerKind = 'food' | 'event' | 'janazah';

/** Slot vocabulary the manifests actually use today. */
export type FlyerSlotType = 'text' | 'multiline' | 'image' | 'list';

/**
 * The three treatments, never mixed — a line gets exactly one. `plain` is the food
 * disclaimer's deliberate exception (no box, no outline), which is why it is the only
 * line whose legibility depends on the ground the palette gate checks.
 */
export type FlyerTreatment = 'sticker' | 'pill' | 'bar' | 'plain' | 'image';

/** Which ground a template paints, and therefore what a bleed must be filled with. */
export type FlyerGround = 'gradient' | 'field' | 'solid' | 'photo';

/** One field inside a repeating row (`data-repeat`). Only event-bulletin has these. */
export type FlyerItemSlot = {
    name: string;
    label: string;
    type: 'text' | 'multiline';
    required: boolean;
    maxLength: number | null;
    default: string | null;
};

export type FlyerSlot = {
    name: string;
    label: string;
    type: FlyerSlotType;
    required: boolean;
    maxLength: number | null;
    default: string | null;
    help?: string;
    zone?: string;
    treatment?: FlyerTreatment;
    /** The CSS custom property a shrink-to-fit pass steps down. */
    size_var?: string;
    /**
     * Three settled decisions the manifests encode: the phone number, the food
     * disclaimer, and the name of the deceased are asked EVERY time. The editor never
     * prefills one, even where a default exists — carrying the last flyer's forward is
     * the failure this flag exists to prevent.
     */
    ask_always?: boolean;
    maxItems?: number;
    item_slots?: FlyerItemSlot[];
};

export type FlyerCanvas = {
    width: number;
    height: number;
    aspect: string;
};

/** A pinned band, as a percentage of canvas height. Only the measured food layout has them. */
export type FlyerZone = {
    name: string;
    top: number;
    bottom: number;
    treatment: FlyerTreatment;
    elastic?: boolean;
};

export type FlyerTemplateManifest = {
    key: string;
    name: string;
    kind: FlyerKind;
    archetype?: string;
    version: number;
    html: string;
    root_class: string;
    summary: string;
    canvas: FlyerCanvas;
    /** Absent on the measured food layout, which pins its zones instead. */
    layout?: 'flow';
    palette: {
        default: string;
        ground: FlyerGround;
        contrast_sample?: number;
        note?: string;
    };
    zones?: FlyerZone[];
    slots: FlyerSlot[];
};

/** A row of resources/flyer-templates/index.json — the registry of shipped designs. */
export type FlyerTemplateIndexEntry = {
    key: string;
    name: string;
    kind: FlyerKind;
    archetype?: string;
    manifest: string;
    html: string;
    measured: boolean;
    summary: string;
};

export type FlyerTemplateIndex = {
    version: number;
    font: { family: string; weights: number[]; self_hosted: boolean; note: string };
    canvas_default: FlyerCanvas;
    palette: { resolver: string; named: string; default: string; vars: string[] };
    templates: FlyerTemplateIndexEntry[];
};

/** A server-side template row, as FlyerTemplatesController serialises it. */
export type FlyerTemplate = {
    id: number;
    key: string;
    name: string;
    kind: FlyerKind;
    archetype: string | null;
    summary: string | null;
    canvas: FlyerCanvas | null;
    root_class: string | null;
    palette: FlyerTemplateManifest['palette'] | null;
    slots: FlyerSlot[];
    default_content: FlyerContent;
    is_system: boolean;
    is_active: boolean;
    /** Null on the shared designs; set on one this masjid authored. */
    masjid_id: number | null;
    updated_at: string | null;
};

/**
 * A design the Studio can actually put on screen: the bundled manifest and markup,
 * paired with the server row that owns its id.
 *
 * `template` is null until the templates are seeded on this host. That is not fatal —
 * a flyer can still be built and exported, because none of that touches the server —
 * but it is what makes "save as draft" impossible, since a flyer row needs a
 * flyer_template_id.
 */
export type FlyerDesign = {
    key: string;
    manifest: FlyerTemplateManifest;
    html: string;
    template: FlyerTemplate | null;
};

/**
 * A resolved palette, as palette.js hands it back: every ink already gated against the
 * ground it will actually sit on. Snapshotted onto the flyer row at save time.
 */
export type FlyerPalette = {
    key?: string;
    name?: string;
    note?: string;
    /** Four stops, at 0% / 50% / 80% / 100% of the canvas. */
    grad: string[];
    field: string;
    ground: string;
    accent: string;
    ink: string;
    fieldInk: string;
    groundInk: string;
    pillBg: string;
    pillInk: string;
    barBg: string;
    barInk: string;
    /** Burlington's purple is Burlington's: a named palette offered to masjid 1 only. */
    restricted_to_masjid?: number;
};

/** One failing ink/ground pair from auditPalette(). Should always be empty. */
export type FlyerPaletteAudit = {
    name: string;
    ink: string;
    ground: string;
    ratio: number;
};

export type FlyerPalettesFile = {
    version: number;
    default: string;
    notes?: string;
    palettes: Record<string, FlyerPalette>;
};

/** A row of a `list` slot: item slot name => value. */
export type FlyerListItem = Record<string, string>;

export type FlyerSlotValue = string | FlyerListItem[] | null;

/** The filled slot values, keyed by slot name — the whole of a flyer's content. */
export type FlyerContent = Record<string, FlyerSlotValue>;

export type FlyerStatus = 'draft' | 'rendered';

/**
 * Background-removal lifecycle. 'none' is the resting state for a flyer with no photo
 * — or for a host where cutouts are switched off — and is NOT a synonym for "not
 * started yet", which is what 'queued' means.
 */
export type FlyerCutoutStatus = 'none' | 'queued' | 'processing' | 'done' | 'failed';

/** The two states the Studio keeps polling through. */
export const FLYER_CUTOUT_PENDING: FlyerCutoutStatus[] = ['queued', 'processing'];

/**
 * Where a flyer's pictures can be fetched from.
 *
 * Endpoints, not storage paths: uploads live on the PRIVATE disk and are streamed back
 * through the admin session, so a janazah photo is not reachable by guessing a
 * /storage URL. They therefore need the Authorization header like any other API call.
 */
export type FlyerImageUrls = {
    source: string | null;
    /** Only ever set once the cutout is done. */
    cutout: string | null;
    /** Cutout if there is one, otherwise the upload — the image to actually render. */
    composite: string | null;
};

/** What the photo endpoints answer with. Mirrors FlyerCutoutController::cutoutState. */
export type FlyerCutoutState = {
    flyer_id: number;
    cutout_status: FlyerCutoutStatus;
    cutout_error: string | null;
    /** Poll while true; stop when it goes false, whatever the outcome. */
    pending: boolean;
    /** Whether this host can remove a background at all — not whether one failed. */
    available: boolean;
    images: FlyerImageUrls;
};

/** A saved flyer, as FlyersController serialises it. */
export type Flyer = {
    id: number;
    uuid: string;
    masjid_id: number;
    flyer_template_id: number;
    template_key: string | null;
    template_name: string | null;
    kind: FlyerKind | null;
    title: string;
    content: FlyerContent;
    palette: FlyerPalette | [];
    status: FlyerStatus;
    is_rendered: boolean;
    /** Required slots still empty. The server does not reject a half-filled draft. */
    missing_slots: string[];
    cutout_status: FlyerCutoutStatus;
    cutout_error: string | null;
    cutout_pending: boolean;
    images: FlyerImageUrls;
    created_at: string | null;
    updated_at: string | null;
};

/**
 * What the Studio saves. masjid_id is never sent — the tenant guardrail owns it — and
 * image slots are stripped from `content` before it goes out: the photo is uploaded to
 * the flyer, and StoreFlyerRequest rejects a `data:` URI in an image slot outright
 * rather than let a multi-megabyte blob into a JSON column.
 */
export type FlyerPayload = {
    flyer_template_id: number;
    title: string;
    content: FlyerContent;
    palette: FlyerPalette;
};

/** Local state for one image slot: what was uploaded, what came back, which is in use. */
export type FlyerImageSlotState = {
    /** The upload, as a data URL. Data URLs, not object URLs — export needs them inlined. */
    original: string | null;
    /** The same image with its background removed, once the worker finishes. */
    cutout: string | null;
    /** Never branch on cutout_status to pick an image; a failed cutout falls back here. */
    useCutout: boolean;
    fileName: string | null;
};

export type FlyerExportSize = {
    key: string;
    label: string;
    width: number;
    height: number;
    note: string;
};

/** Every template is sized in px against this reference. */
export const FLYER_CANVAS: FlyerCanvas = { width: 1200, height: 1600, aspect: '3:4' };

/**
 * Exported at full canvas resolution, never at the on-screen preview size — the
 * preview is a CSS transform of the same 1200x1600 DOM, and rasterising that would
 * post a soft flyer.
 *
 * The 4:5 size is not the canvas aspect, so the flyer is scaled to FIT and the side
 * bleed is filled from the palette. It is not cropped to fill: the CTA bar ends 2.2%
 * from the bottom edge and the title starts 4.1% from the top, so a centre crop eats
 * the phone number.
 */
export const FLYER_EXPORT_SIZES: FlyerExportSize[] = [
    {
        key: 'canvas',
        label: '1200 × 1600',
        width: 1200,
        height: 1600,
        note: 'The design\'s own 3:4 canvas — print, WhatsApp, the noticeboard.'
    },
    {
        key: 'portrait',
        label: '1080 × 1350',
        width: 1080,
        height: 1350,
        note: 'Instagram portrait (4:5). Scaled to fit, bleed filled from the palette — nothing is cropped.'
    }
];
