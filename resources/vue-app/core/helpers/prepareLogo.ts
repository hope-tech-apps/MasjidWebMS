/**
 * Get a client's logo ready for Manara Studio's upload
 * (POST /api/admin/studio/drafts/{id}/logo, docs/manara-studio-w1.md S5).
 *
 * The server keeps only PNG and JPEG (config/studio.php `logo.mime_types`):
 * Step 3 derives the favicon and share image from the logo with GD, which
 * cannot rasterise SVG, and SVG can carry script. Clients send whatever they
 * have, so the browser does the conversion:
 *
 *  - SVG, WebP, GIF or any other type the browser can decode is redrawn on a
 *    canvas and sent as a PNG, at most LOGO_MAX_EDGE pixels on its longest side.
 *    Nothing fills the canvas first, so a transparent background stays
 *    transparent: a logo sits on the header colour, not on a white box.
 *  - A PNG or JPEG already within the cap is sent exactly as chosen. Redrawing
 *    it could only lose detail.
 *  - A PNG or JPEG over the cap is redrawn to fit, as a PNG like the rest.
 *
 * An SVG has no pixels of its own, so it is drawn with its longest side at the
 * cap: the favicon and share image are cut from this file, and the sharper the
 * source the sharper they are. A raster is never enlarged.
 *
 * This is NOT preparePhoto(): that one flattens onto white and re-encodes as
 * JPEG (core/helpers/preparePhoto.ts), which would put a white box behind every
 * transparent logo.
 *
 * The pure parts (planLogo, fitWithin, svgIntrinsicSize) carry the rules and
 * have no imports, so tests/prepare-logo.test.ts runs them under node.
 */

/** Longest side, in pixels, of a logo Studio sends. */
export const LOGO_MAX_EDGE = 2048;

const PASSTHROUGH_TYPES = ['image/png', 'image/jpeg'];

const SVG_TYPE = 'image/svg+xml';

export type LogoPlan =
    | { action: 'keep' }
    | { action: 'redraw'; width: number; height: number };

/** Thrown with a sentence the Brand panel can show as it is. */
export class LogoPreparationError extends Error {}

/**
 * Integer dimensions that fit `maxEdge` on the longest side, keeping the aspect
 * ratio. `enlarge` lets a vector grow to the cap; a raster only ever shrinks.
 */
export function fitWithin(width: number, height: number, maxEdge: number, enlarge: boolean): { width: number; height: number } {
    const longest = Math.max(width, height);
    const scale = enlarge ? maxEdge / longest : Math.min(1, maxEdge / longest);

    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
    };
}

/** What to do with a logo of this type and intrinsic size. */
export function planLogo(type: string, width: number, height: number, maxEdge: number = LOGO_MAX_EDGE): LogoPlan {
    if (!(width > 0) || !(height > 0)) {
        throw new LogoPreparationError('That image has no size the browser can read. Export the logo as a PNG and try again.');
    }

    if (PASSTHROUGH_TYPES.includes(type) && Math.max(width, height) <= maxEdge) {
        return { action: 'keep' };
    }

    return { action: 'redraw', ...fitWithin(width, height, maxEdge, type === SVG_TYPE) };
}

/**
 * An SVG's own width and height, read from its root element: the viewBox when
 * it has one, otherwise plain numeric width and height attributes. Null when
 * neither says, for the caller to fall back on what the browser decoded.
 */
export function svgIntrinsicSize(source: string): { width: number; height: number } | null {
    const root = source.match(/<svg\b[^>]*>/i)?.[0];
    if (!root) {
        return null;
    }

    const attribute = (name: string) => root.match(new RegExp(`\\s${name}\\s*=\\s*["']([^"']*)["']`, 'i'))?.[1] ?? null;

    const viewBox = attribute('viewBox')?.trim().split(/[\s,]+/).map(Number);
    if (viewBox && viewBox.length === 4 && viewBox[2] > 0 && viewBox[3] > 0) {
        return { width: viewBox[2], height: viewBox[3] };
    }

    const length = (value: string | null) => {
        const match = value?.trim().match(/^(\d+(?:\.\d+)?)(px)?$/i);
        return match ? Number(match[1]) : null;
    };
    const width = length(attribute('width'));
    const height = length(attribute('height'));

    return width && height ? { width, height } : null;
}

function isSvg(file: File): boolean {
    return file.type === SVG_TYPE || /\.svg$/i.test(file.name);
}

function decode(url: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new LogoPreparationError('That file could not be read as an image.'));
        image.src = url;
    });
}

/** The logo as it should be uploaded: the file itself, or a PNG redrawn from it. */
export async function prepareLogo(file: File): Promise<File> {
    const svg = isSvg(file);
    const url = URL.createObjectURL(file);

    try {
        const image = await decode(url);
        let width = image.naturalWidth;
        let height = image.naturalHeight;

        if (svg) {
            // Browsers disagree about an SVG's natural size (Firefox reports 0
            // without width/height attributes), so the file's own numbers win.
            const own = svgIntrinsicSize(await file.text());
            if (own) {
                width = own.width;
                height = own.height;
            }
        }

        const plan = planLogo(svg ? SVG_TYPE : file.type, width, height);
        if (plan.action === 'keep') {
            return file;
        }

        const canvas = document.createElement('canvas');
        canvas.width = plan.width;
        canvas.height = plan.height;

        const context = canvas.getContext('2d');
        if (!context) {
            throw new LogoPreparationError('This browser could not redraw the logo. Export it as a PNG and try again.');
        }
        context.drawImage(image, 0, 0, plan.width, plan.height);

        let blob: Blob | null;
        try {
            blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));
        } catch {
            // A browser that treats a drawn SVG as cross-origin refuses to export it.
            blob = null;
        }
        if (!blob) {
            throw new LogoPreparationError('This browser could not convert the logo. Export it as a PNG and try again.');
        }

        const stem = file.name.replace(/\.[^.]+$/, '') || 'logo';
        return new File([blob], `${stem}.png`, { type: 'image/png', lastModified: Date.now() });
    } finally {
        URL.revokeObjectURL(url);
    }
}
