/**
 * Studio's logo preparation (core/helpers/prepareLogo.ts): what is sent as it is,
 * what is redrawn as a PNG, and how big. The browser parts (Image, canvas) are
 * stubbed; the rules are real. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fitWithin, LOGO_LARGEST_EDGE, LOGO_MAX_EDGE, LOGO_MIN_EDGE, LogoPreparationError, planLogo, prepareLogo, svgIntrinsicSize } from '../core/helpers/prepareLogo.ts';

test('a PNG or JPEG within 2048 px is kept exactly as chosen', () => {
    assert.deepEqual(planLogo('image/png', 2048, 900), { action: 'keep' });
    assert.deepEqual(planLogo('image/jpeg', 512, 512), { action: 'keep' });
});

test('a PNG over the cap is redrawn to fit, keeping its shape', () => {
    assert.deepEqual(planLogo('image/png', 4096, 1024), { action: 'redraw', width: 2048, height: 512 });
});

test('WebP and GIF are redrawn even when small, and never enlarged', () => {
    assert.deepEqual(planLogo('image/webp', 300, 200), { action: 'redraw', width: 300, height: 200 });
    assert.deepEqual(planLogo('image/gif', 5000, 2500), { action: 'redraw', width: 2048, height: 1024 });
});

test('an SVG is drawn with its longest side at the cap', () => {
    assert.deepEqual(planLogo('image/svg+xml', 100, 50), { action: 'redraw', width: LOGO_MAX_EDGE, height: 1024 });
});

test('a wide wordmark keeps the server minimum on its short side, going past the cap to do it', () => {
    // 3000x120 fitted to 2048 would be 2048x82, which the server's 96 px minimum refuses.
    assert.deepEqual(planLogo('image/png', 3000, 120), { action: 'redraw', width: 2400, height: LOGO_MIN_EDGE });
    assert.deepEqual(planLogo('image/svg+xml', 2000, 80), { action: 'redraw', width: 2400, height: LOGO_MIN_EDGE });
    assert.deepEqual(planLogo('image/svg+xml', 40, 1000), { action: 'redraw', width: LOGO_MIN_EDGE, height: 2400 });
});

test('a logo too long and thin for both server limits is refused here, with a sentence', () => {
    assert.throws(() => planLogo('image/svg+xml', 100, 1), (error: unknown) =>
        error instanceof LogoPreparationError && error.message.includes(`${LOGO_LARGEST_EDGE} px`));
});

test('a raster never that tall is not enlarged to reach the minimum', () => {
    assert.deepEqual(planLogo('image/webp', 300, 50), { action: 'redraw', width: 300, height: 50 });
});

test('an image with no readable size is refused with a sentence, not sent', () => {
    assert.throws(() => planLogo('image/svg+xml', 0, 0), LogoPreparationError);
});

test('fitWithin never returns a zero edge', () => {
    assert.deepEqual(fitWithin(10000, 1, 2048, false), { width: 2048, height: 1 });
});

test('an SVG size comes from its viewBox, else its numeric width and height', () => {
    assert.deepEqual(svgIntrinsicSize('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 80" width="100%">'), { width: 240, height: 80 });
    assert.deepEqual(svgIntrinsicSize("<svg width='64px' height='32'>"), { width: 64, height: 32 });
    assert.equal(svgIntrinsicSize('<svg width="10em" height="2em">'), null);
    assert.equal(svgIntrinsicSize('not an svg'), null);
});

/** Stubs Image, object URLs and the canvas; returns what was drawn and exported. */
function stubBrowser(natural: { width: number; height: number }) {
    const calls: { drawn?: number[]; exported?: string; filled: boolean; contextOptions?: unknown } = { filled: false };
    (globalThis as any).Image = class {
        naturalWidth = natural.width;
        naturalHeight = natural.height;
        onload: (() => void) | null = null;
        onerror: (() => void) | null = null;
        set src(_url: string) { queueMicrotask(() => this.onload?.()); }
    };
    URL.createObjectURL = () => 'blob:stub';
    URL.revokeObjectURL = () => {};
    (globalThis as any).document = {
        createElement: () => ({
            width: 0,
            height: 0,
            getContext: (_kind: string, options?: unknown) => {
                calls.contextOptions = options;
                return {
                    drawImage: (_image: unknown, _x: number, _y: number, width: number, height: number) => { calls.drawn = [width, height]; },
                    fillRect: () => { calls.filled = true; },
                    fill: () => { calls.filled = true; },
                };
            },
            toBlob: (resolve: (blob: Blob) => void, type: string) => { calls.exported = type; resolve(new Blob(['png'], { type })); },
        }),
    };
    return calls;
}

test('an SVG upload leaves as a PNG named after it, drawn from its viewBox, with nothing painted behind it', async () => {
    const calls = stubBrowser({ width: 0, height: 0 });
    const svg = new File(['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 100"></svg>'], 'crest.svg', { type: 'image/svg+xml' });

    const out = await prepareLogo(svg);

    assert.equal(out.type, 'image/png');
    assert.equal(out.name, 'crest.png');
    assert.equal(calls.exported, 'image/png');
    assert.deepEqual(calls.drawn, [2048, 683]);
    assert.equal(calls.filled, false, 'a transparent logo must stay transparent');
    assert.notEqual((calls.contextOptions as { alpha?: boolean } | undefined)?.alpha, false, 'an opaque canvas turns a transparent background black');
});

test('a PNG over the cap is redrawn as a PNG, on a canvas that keeps transparency', async () => {
    const calls = stubBrowser({ width: 4096, height: 1024 });
    const png = new File(['png'], 'wide.png', { type: 'image/png' });

    const out = await prepareLogo(png);

    assert.equal(calls.exported, 'image/png', 'a JPEG would lose the transparency and mismatch its .png name');
    assert.deepEqual(calls.drawn, [2048, 512]);
    assert.equal(out.type, 'image/png');
    assert.match(out.name, /\.png$/);
    assert.equal(calls.filled, false);
    assert.notEqual((calls.contextOptions as { alpha?: boolean } | undefined)?.alpha, false);
});

test('a small PNG is the very file chosen', async () => {
    stubBrowser({ width: 800, height: 400 });
    const png = new File(['png'], 'logo.png', { type: 'image/png' });

    assert.equal(await prepareLogo(png), png);
});
