/**
 * Studio's logo preparation (core/helpers/prepareLogo.ts): what is sent as it is,
 * what is redrawn as a PNG, and how big. The browser parts (Image, canvas) are
 * stubbed; the rules are real. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fitWithin, LOGO_MAX_EDGE, LogoPreparationError, planLogo, prepareLogo, svgIntrinsicSize } from '../core/helpers/prepareLogo.ts';

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
    const calls: { drawn?: number[]; exported?: string; filled: boolean } = { filled: false };
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
            getContext: () => ({
                drawImage: (_image: unknown, _x: number, _y: number, width: number, height: number) => { calls.drawn = [width, height]; },
                fillRect: () => { calls.filled = true; },
            }),
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
});

test('a small PNG is the very file chosen', async () => {
    stubBrowser({ width: 800, height: 400 });
    const png = new File(['png'], 'logo.png', { type: 'image/png' });

    assert.equal(await prepareLogo(png), png);
});
