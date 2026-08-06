/**
 * Flyer palette contract.
 *
 * Every template in this directory reads its colours from CSS custom properties
 * and hard-codes none. This module is the single place that turns a masjid's
 * theme_settings into that variable set, so one template serves every tenant.
 *
 * Two rules the owner settled, encoded here rather than left to a README:
 *
 *   1. Burlington's purple gradient is Burlington's. It ships as the named
 *      palette 'burlington-purple' and is the default for masjid 1 ONLY. Every
 *      other tenant defaults to a cool/neutral gradient DERIVED from its own
 *      brand colours (see coolNeutralFrom). `restricted_to_masjid` is checked on
 *      EVERY route in — an explicit palette, a named one, or the default.
 *   2. The ink must clear WCAG AA on the ground it actually lands on. This
 *      codebase has already shipped a contrast bug once; enforceInk() is the
 *      guard, and it runs on resolve, not on review.
 *
 * Both rules fail loudly. A colour that will not parse, a gradient of the wrong
 * length, a contrast minimum no ink can reach: each throws, because every one of
 * them otherwise degrades into a flyer that looks finished and is wrong, and a
 * wrong flyer is printed and handed out before anyone reads a console warning.
 *
 * Zero dependencies, plain ES module — the admin SPA renders flyers in the
 * browser (the droplet has no Node, and no server-side rasteriser here can do
 * flexbox or Arabic shaping).
 */

/** WCAG AA for body text. The flyer disclaimer is body text. */
const AA_NORMAL = 4.5;

/**
 * Where the unboxed ink lands when a template does not say.
 *
 * The food disclaimer sits at 81.9–86.1% of the canvas, so the gradient under it
 * is the interpolation of stops 2 and 3 — NOT stop 0. Sampling at the top of the
 * gradient is how you ship a flyer whose one unboxed line is invisible.
 *
 * Templates that put their plain text somewhere else say so in their manifest
 * (`palette.contrast_sample`: 0.5 on the invitation, 0.6 on the bulletin) and
 * pass it in. That number is not decoration: the gradient runs dark-to-light
 * downward, so a check at 0.84 is a check against a LIGHTER ground than the ink
 * actually gets, and dark ink can pass at 0.84 while failing at 0.5.
 */
const INK_SAMPLE_POINT = 0.84;

/** Masjid 1 is Burlington; its purple is the default for that tenant and no other. */
const BURLINGTON_MASJID_ID = 1;
const BURLINGTON_PALETTE_KEY = 'burlington-purple';

/** Gradient stop positions, measured off the exemplars. */
export const GRADIENT_STOPS = [0, 0.5, 0.8, 1];

/** The neutral/cool ground used when a tenant has no theme at all. */
export const DEFAULT_PALETTE = Object.freeze({
    key: 'cool-neutral',
    name: 'Cool Neutral',
    grad: ['#C7D2DE', '#D6DDE6', '#E6E7EC', '#F1F0F2'],
    field: '#2E4258',
    ground: '#12161C',
    accent: '#7A8CA3',
    ink: '#14181F',
    fieldInk: '#FFFFFF',
    groundInk: '#F2F4F7',
    pillBg: '#FFFFFF',
    pillInk: '#000000',
    barBg: '#000000',
    barInk: '#FFFFFF',
});

/** How far each derived stop is pulled toward the neutral default (0..1). */
const COOL_BLEND = 0.55;

/* ---------------------------------------------------------------------- */
/* Colour maths                                                            */
/* ---------------------------------------------------------------------- */

/** Normalise to #RRGGBB, or null when blank/invalid. Mirrors App\Support\DesignTokens. */
export function normalizeHex(value) {
    const hex = typeof value === 'string' ? value.trim() : '';
    if (!/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(hex)) return null;
    if (hex.length === 4) {
        return `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}`.toUpperCase();
    }
    return `#${hex.slice(1, 7)}`.toUpperCase();
}

/**
 * Parse to #RRGGBB or throw.
 *
 * Every gradient stop and every ink is read by POSITION downstream — grad[2] is
 * the ground under the disclaimer, `--flyer-bar-ink` is the phone number on the
 * black bar. Substituting black for a colour nobody could parse therefore does
 * not produce a visibly broken flyer; it produces a plausible one with the wrong
 * ground, which is exactly the failure the contrast gate exists to catch. So the
 * malformed value stops here, where the message can still name what was wrong.
 */
function requireHex(value, what = 'colour') {
    const hex = normalizeHex(value);
    if (!hex) {
        throw new Error(`Flyer palette: ${what} is not a hex colour (got ${JSON.stringify(value)}).`);
    }
    return hex;
}

function toRgb(hex, what = 'colour') {
    const h = requireHex(hex, what);
    return [
        parseInt(h.slice(1, 3), 16),
        parseInt(h.slice(3, 5), 16),
        parseInt(h.slice(5, 7), 16),
    ];
}

function fromRgb([r, g, b]) {
    const clamp = (c) => Math.max(0, Math.min(255, Math.round(c)));
    return `#${[r, g, b].map((c) => clamp(c).toString(16).padStart(2, '0')).join('')}`.toUpperCase();
}

export function rgbToHsl(hex) {
    const [r, g, b] = toRgb(hex).map((c) => c / 255);
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const l = (max + min) / 2;
    if (max === min) return { h: 0, s: 0, l };

    const d = max - min;
    const s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
    let h;
    if (max === r) h = ((g - b) / d + (g < b ? 6 : 0)) / 6;
    else if (max === g) h = ((b - r) / d + 2) / 6;
    else h = ((r - g) / d + 4) / 6;

    return { h: h * 360, s, l };
}

export function hslToHex({ h, s, l }) {
    const hue = ((h % 360) + 360) % 360 / 360;
    if (s === 0) {
        const v = l * 255;
        return fromRgb([v, v, v]);
    }
    const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
    const p = 2 * l - q;
    const channel = (t) => {
        let x = t;
        if (x < 0) x += 1;
        if (x > 1) x -= 1;
        if (x < 1 / 6) return p + (q - p) * 6 * x;
        if (x < 1 / 2) return q;
        if (x < 2 / 3) return p + (q - p) * (2 / 3 - x) * 6;
        return p;
    };
    return fromRgb([channel(hue + 1 / 3) * 255, channel(hue) * 255, channel(hue - 1 / 3) * 255]);
}

/** WCAG relative luminance (0 = black .. 1 = white). */
export function luminance(hex) {
    const lin = (c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
    const [r, g, b] = toRgb(hex).map((c) => lin(c / 255));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG contrast ratio, 1..21. */
export function contrastRatio(a, b) {
    const la = luminance(a);
    const lb = luminance(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/** Linear mix of two hex colours, amount 0..1 toward `b`. */
export function mix(a, b, amount) {
    const ra = toRgb(a);
    const rb = toRgb(b);
    return fromRgb(ra.map((c, i) => c + (rb[i] - c) * amount));
}

/**
 * The colour of a 4-stop vertical gradient at position t (0..1). Used to ask
 * "what is actually behind the disclaimer" instead of guessing.
 */
export function sampleGradient(stops, t, positions = GRADIENT_STOPS) {
    if (!Array.isArray(stops) || stops.length !== positions.length) {
        throw new Error(`Flyer palette: expected ${positions.length} gradient stops, got ${JSON.stringify(stops)}.`);
    }

    // Every stop, not just the two being interpolated: a bad stop elsewhere in the
    // ramp still reaches the flyer through --flyer-grad-N, and it is better heard
    // about here, once, than seen later as a band of the wrong colour.
    stops.forEach((stop, i) => requireHex(stop, `gradient stop ${i}`));

    const pos = Math.max(0, Math.min(1, t));
    for (let i = 0; i < positions.length - 1; i += 1) {
        const a = positions[i];
        const b = positions[i + 1];
        if (pos <= b) {
            const span = b - a || 1;
            return mix(stops[i], stops[i + 1], (pos - a) / span);
        }
    }
    return requireHex(stops[stops.length - 1], 'gradient stop');
}

/**
 * Walk `ink` toward black or white until it clears `min` against `ground`.
 * Returns the original when it already passes, so a hand-picked palette is left
 * alone. Throws when no ink of any lightness can clear `min` on that ground.
 *
 * The direction is decided by asking the two extremes, not by a lightness
 * midpoint. Contrast is not linear in lightness: white stops beating black at a
 * relative luminance of ~0.179, not 0.5. Between those two numbers lies a real
 * band of grounds — mid greys, a mid-tone brand colour — where lightening the
 * ink can never reach 4.5:1 no matter how far it goes, and the old midpoint test
 * sent every one of them the wrong way and then returned the failing colour as
 * though it had been repaired.
 *
 * At AA the two extremes overlap (white clears 4.5:1 up to luminance ~0.183,
 * black from ~0.175), so every ground has an answer and the throw is unreachable.
 * At a stricter `min` they separate — nothing clears 7:1 on a mid grey — and then
 * refusing is the only honest result. A caller that swallows this and renders
 * anyway has re-created the contrast incident this function was written for.
 */
export function enforceInk(ink, ground, min = AA_NORMAL) {
    const start = requireHex(ink, 'ink');
    const base = requireHex(ground, 'ground');
    if (contrastRatio(start, base) >= min) return start;

    const white = contrastRatio('#FFFFFF', base);
    const black = contrastRatio('#000000', base);
    const best = Math.max(white, black);

    if (best < min) {
        throw new Error(
            `Flyer palette: no ink clears ${min}:1 on ${base} — the best any colour can do `
            + `is ${best.toFixed(2)}:1. Darken or lighten that ground.`
        );
    }

    // Head for whichever extreme can actually get there; on a tie either does.
    const towardWhite = white > black;
    const hsl = rgbToHsl(start);

    // 50 steps of 0.02 spans the whole lightness scale, so the walk always reaches
    // the extreme the guard above proved passes — the hue and saturation are kept
    // as long as they can be, and only surrendered at the very end.
    for (let step = 1; step <= 50; step += 1) {
        const l = towardWhite
            ? Math.min(1, hsl.l + step * 0.02)
            : Math.max(0, hsl.l - step * 0.02);
        const candidate = hslToHex({ ...hsl, l });
        if (contrastRatio(candidate, base) >= min) return candidate;
    }

    return towardWhite ? '#FFFFFF' : '#000000';
}

/* ---------------------------------------------------------------------- */
/* Derivation                                                              */
/* ---------------------------------------------------------------------- */

/**
 * Derive the default cool/neutral gradient from a tenant's brand colour.
 *
 * A light, low-chroma ramp at the brand's own hue, each stop then pulled
 * halfway toward the neutral default. The pull is an RGB mix rather than a hue
 * rotation on purpose: rotating a red brand toward blue lands it on lavender —
 * a colour the tenant never chose, and uncomfortably close to Burlington's.
 * Mixing desaturates toward grey instead, so every brand keeps a faint,
 * recognisable cast and nobody inherits someone else's identity.
 *
 * Lightness climbs down the canvas the way the measured ramp does — palest at
 * the foot, where the unboxed disclaimer sits.
 */
export function coolNeutralFrom(brandHex) {
    const brand = normalizeHex(brandHex);
    if (!brand) return [...DEFAULT_PALETTE.grad];

    const { h, s } = rgbToHsl(brand);
    const chroma = Math.max(0.35, Math.min(1, s / 0.6));
    const sats = [0.26, 0.20, 0.14, 0.09].map((v) => v * chroma);
    const lights = [0.76, 0.83, 0.89, 0.94];

    return lights.map((l, i) => mix(
        hslToHex({ h, s: sats[i], l }),
        DEFAULT_PALETTE.grad[i],
        COOL_BLEND,
    ));
}

/**
 * A deep, saturated ground for the banner archetype. Clamped dark enough that
 * white type on it clears AA by construction, then verified anyway.
 */
export function fieldFrom(brandHex) {
    const brand = normalizeHex(brandHex);
    if (!brand) return DEFAULT_PALETTE.field;

    const { h, s } = rgbToHsl(brand);
    let field = hslToHex({ h, s: Math.min(s, 0.45), l: 0.30 });
    for (let step = 1; contrastRatio('#FFFFFF', field) < AA_NORMAL && step <= 15; step += 1) {
        field = hslToHex({ h, s: Math.min(s, 0.45), l: Math.max(0.06, 0.30 - step * 0.02) });
    }
    return field;
}

/** The sombre near-black ground for janazah — desaturated on purpose. */
export function groundFrom(brandHex) {
    const brand = normalizeHex(brandHex);
    if (!brand) return DEFAULT_PALETTE.ground;
    const { h, s } = rgbToHsl(brand);
    return hslToHex({ h, s: Math.min(s, 0.12), l: 0.09 });
}

/**
 * Build a full palette from a masjid's theme.
 *
 * `theme` accepts either the legacy shape ({primary_color, secondary_color,
 * accent_color, background_color}) or the derived token tree's `color` object,
 * so callers can pass whichever the API handed them.
 */
export function paletteFromTheme(theme) {
    const t = theme || {};
    const primary = normalizeHex(t.primary_color ?? t.primary);
    const secondary = normalizeHex(t.secondary_color ?? t.secondary);
    const accent = normalizeHex(t.accent_color ?? t.accent);

    const grad = coolNeutralFrom(primary);
    const field = fieldFrom(primary);
    const ground = groundFrom(secondary || primary);

    return {
        key: 'derived',
        name: 'Derived from theme',
        grad,
        field,
        ground,
        accent: accent || DEFAULT_PALETTE.accent,
        ink: DEFAULT_PALETTE.ink,
        fieldInk: DEFAULT_PALETTE.fieldInk,
        groundInk: DEFAULT_PALETTE.groundInk,
        pillBg: DEFAULT_PALETTE.pillBg,
        pillInk: DEFAULT_PALETTE.pillInk,
        barBg: DEFAULT_PALETTE.barBg,
        barInk: DEFAULT_PALETTE.barInk,
    };
}

/**
 * A masjid id as a number, whatever shape it arrived in.
 *
 * Ids travel as route-param and localStorage STRINGS as often as they do as
 * numbers, and `'1' === 1` is false — which is how a tenant check silently stops
 * checking.
 */
export function normalizeMasjidId(value) {
    if (typeof value !== 'number' && typeof value !== 'string') return null;
    if (typeof value === 'string' && value.trim() === '') return null;
    const id = Number(value);
    return Number.isInteger(id) ? id : null;
}

/**
 * Whether this tenant may wear this palette.
 *
 * `restricted_to_masjid` is the owner's decision written down: Burlington's
 * gradient is Burlington's. It has to hold on every route into resolvePalette —
 * an explicit palette object and a named key are exactly how another tenant would
 * end up in it, and those two paths do not go through the Studio's option list.
 */
function allowedForMasjid(candidate, masjidId) {
    if (!candidate) return false;

    const declared = candidate.restricted_to_masjid;
    if (declared === undefined || declared === null) return true;

    // Declared but unreadable refuses everyone. A restriction nobody can parse is
    // still a restriction somebody meant, and failing open on this one hands a
    // tenant's brand to a tenant who did not choose it.
    const restricted = normalizeMasjidId(declared);
    return restricted !== null && restricted === masjidId;
}

/**
 * The point on the gradient a template's unboxed ink actually sits at.
 * Absent means "use the default"; present but nonsense means the manifest is
 * wrong and should be fixed rather than quietly ignored.
 */
export function inkSamplePoint(sample) {
    if (sample === undefined || sample === null) return INK_SAMPLE_POINT;
    if (typeof sample !== 'number' || !Number.isFinite(sample) || sample < 0 || sample > 1) {
        throw new Error(`Flyer palette: contrast_sample must be a number 0..1 (got ${JSON.stringify(sample)}).`);
    }
    return sample;
}

/**
 * Resolve the palette a template should render with, then gate every ink against
 * the ground it will actually sit on.
 *
 * @param {object}  opts
 * @param {object}  [opts.palette]  Explicit palette object — wins over everything.
 * @param {object}  [opts.named]    A named palette from palettes.json.
 * @param {object}  [opts.theme]    theme_settings row (or tokens.color).
 * @param {number|string} [opts.masjidId] Only masjid 1 inherits Burlington's purple.
 * @param {object}  [opts.palettes] Parsed palettes.json, for the masjid-1 default.
 * @param {object}  [opts.manifest] The template manifest, read for palette.contrast_sample.
 * @param {number}  [opts.contrastSample] That sample point directly, when there is no manifest.
 */
export function resolvePalette(opts = {}) {
    const { palette, named, theme, masjidId, palettes, manifest, contrastSample } = opts;

    const id = normalizeMasjidId(masjidId);
    const burlington = palettes?.palettes?.[BURLINGTON_PALETTE_KEY];

    // A restricted palette is refused rather than substituted: the tenant falls
    // through to the palette it was always entitled to, which is its own.
    let base;
    if (allowedForMasjid(palette, id)) base = { ...DEFAULT_PALETTE, ...palette };
    else if (allowedForMasjid(named, id)) base = { ...DEFAULT_PALETTE, ...named };
    else if (id === BURLINGTON_MASJID_ID && allowedForMasjid(burlington, id)) {
        base = { ...DEFAULT_PALETTE, ...burlington };
    } else if (theme) base = paletteFromTheme(theme);
    else base = { ...DEFAULT_PALETTE };

    const sample = inkSamplePoint(contrastSample ?? manifest?.palette?.contrast_sample);
    const gradientGround = sampleGradient(base.grad, sample);

    return {
        ...base,
        // Carried so auditPalette() re-checks against the same point this resolve
        // used. A canary measuring somewhere else is not a canary.
        contrastSample: sample,
        // Each ink is checked against its own ground, because a flyer has three
        // different grounds and one blanket check would pass the wrong one.
        ink: enforceInk(base.ink, gradientGround),
        fieldInk: enforceInk(base.fieldInk, base.field),
        groundInk: enforceInk(base.groundInk, base.ground),
        pillInk: enforceInk(base.pillInk, base.pillBg),
        barInk: enforceInk(base.barInk, base.barBg),
    };
}

/**
 * The CSS custom-property set every template reads.
 *
 * Every value is re-checked on the way out. This is the last place a colour is
 * still named — past here it is `--flyer-grad-2`, a positional slot the templates
 * consume as a var() fallback, so a missing or malformed one does not fail: the
 * template quietly draws its own hard-coded default and the flyer looks fine and
 * is wrong. Nothing downstream can tell the difference, so the check belongs here.
 */
export function paletteToCssVars(palette) {
    const p = palette || {};

    if (!Array.isArray(p.grad) || p.grad.length !== GRADIENT_STOPS.length) {
        throw new Error(`Flyer palette: expected ${GRADIENT_STOPS.length} gradient stops, got ${JSON.stringify(p.grad)}.`);
    }

    return {
        '--flyer-grad-0': requireHex(p.grad[0], 'grad[0]'),
        '--flyer-grad-1': requireHex(p.grad[1], 'grad[1]'),
        '--flyer-grad-2': requireHex(p.grad[2], 'grad[2]'),
        '--flyer-grad-3': requireHex(p.grad[3], 'grad[3]'),
        '--flyer-ink': requireHex(p.ink, 'ink'),
        '--flyer-field': requireHex(p.field, 'field'),
        '--flyer-field-ink': requireHex(p.fieldInk, 'fieldInk'),
        '--flyer-ground': requireHex(p.ground, 'ground'),
        '--flyer-ground-ink': requireHex(p.groundInk, 'groundInk'),
        '--flyer-accent': requireHex(p.accent, 'accent'),
        '--flyer-pill-bg': requireHex(p.pillBg, 'pillBg'),
        '--flyer-pill-ink': requireHex(p.pillInk, 'pillInk'),
        '--flyer-bar-bg': requireHex(p.barBg, 'barBg'),
        '--flyer-bar-ink': requireHex(p.barInk, 'barInk'),
    };
}

/** Stamp a resolved palette onto the flyer root (or any ancestor of it). */
export function applyPalette(el, palette) {
    const vars = paletteToCssVars(palette);
    Object.entries(vars).forEach(([name, value]) => el.style.setProperty(name, value));
    return el;
}

/**
 * Report every ink/ground pair that fails AA. resolvePalette() already fixes
 * these; this exists so a test or the Studio preview can assert it stayed fixed.
 *
 * The sample point comes from the resolved palette itself, so the audit measures
 * the same spot on the gradient the repair did; pass one explicitly to audit a
 * palette that never went through resolvePalette().
 */
export function auditPalette(palette, contrastSample) {
    const sample = inkSamplePoint(contrastSample ?? palette.contrastSample);
    const pairs = [
        ['ink', palette.ink, sampleGradient(palette.grad, sample)],
        ['fieldInk', palette.fieldInk, palette.field],
        ['groundInk', palette.groundInk, palette.ground],
        ['pillInk', palette.pillInk, palette.pillBg],
        ['barInk', palette.barInk, palette.barBg],
    ];

    return pairs
        .map(([name, ink, ground]) => ({ name, ink, ground, ratio: contrastRatio(ink, ground) }))
        .filter((r) => r.ratio < AA_NORMAL);
}
