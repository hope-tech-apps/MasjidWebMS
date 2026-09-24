/**
 * The logo palette sampler, shared by the onboarding wizard and Manara Studio's
 * Brand panel (docs/manara-studio-w1.md S5). Moved here verbatim from
 * OnboardingWizardView.vue so the two screens sample a logo identically; only
 * `export` was added. No imports, so tests/extract-palette.test.ts can run it
 * under node with a stubbed canvas.
 */

/** #rrggbb from 0-255 channels. */
export function rgbToHex(r: number, g: number, b: number): string {
    const to2 = (n: number) => Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0');
    return `#${to2(r)}${to2(g)}${to2(b)}`;
}

/**
 * Sample up to `count` dominant colors from an image by drawing it small,
 * quantizing each channel into coarse buckets, and ranking buckets by pixel
 * count. Near-transparent, near-white and near-black pixels are skipped so the
 * logo's actual brand colors win over its background/outline. Runs entirely in
 * the browser (object-URL image -> canvas is same-origin, so getImageData is
 * not tainted); nothing is uploaded.
 */
export function extractDominantColors(img: HTMLImageElement, count: number): string[] {
    const w = 64, h = 64;
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    if (!ctx) return [];
    ctx.drawImage(img, 0, 0, w, h);

    let pixels: Uint8ClampedArray;
    try {
        pixels = ctx.getImageData(0, 0, w, h).data;
    } catch {
        return [];
    }

    const buckets = new Map<string, { count: number; r: number; g: number; b: number }>();
    for (let i = 0; i < pixels.length; i += 4) {
        if (pixels[i + 3] < 125) continue; // skip near-transparent
        const r = pixels[i], g = pixels[i + 1], b = pixels[i + 2];
        const max = Math.max(r, g, b), min = Math.min(r, g, b);
        if (max > 240 && min > 240) continue; // skip near-white
        if (max < 18) continue;               // skip near-black
        // Quantize each channel into 6 levels (~216 buckets).
        const key = `${Math.round(r / 51)}-${Math.round(g / 51)}-${Math.round(b / 51)}`;
        const bucket = buckets.get(key);
        if (bucket) { bucket.count++; bucket.r += r; bucket.g += g; bucket.b += b; }
        else buckets.set(key, { count: 1, r, g, b });
    }

    return [...buckets.values()]
        .sort((a, b) => b.count - a.count)
        .slice(0, count)
        .map(bk => rgbToHex(bk.r / bk.count, bk.g / bk.count, bk.b / bk.count));
}
