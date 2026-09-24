/**
 * The logo palette sampler (core/helpers/extractPalette.ts), moved out of the
 * onboarding wizard for Studio's Brand panel. The canvas is stubbed, so these pin
 * the bucketing rules, not a browser's decoder. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { extractDominantColors, rgbToHex } from '../core/helpers/extractPalette.ts';

/** A 64x64 canvas whose pixels are `fill(index)` as [r, g, b, a]. */
function stubCanvas(fill: (index: number) => [number, number, number, number]) {
    const data = new Uint8ClampedArray(64 * 64 * 4);
    for (let i = 0; i < 64 * 64; i++) {
        data.set(fill(i), i * 4);
    }
    (globalThis as any).document = {
        createElement: () => ({
            width: 0,
            height: 0,
            getContext: () => ({ drawImage() {}, getImageData: () => ({ data }) }),
        }),
    };
}

test('rgbToHex clamps and pads each channel', () => {
    assert.equal(rgbToHex(1, 177, 81), '#01b151');
    assert.equal(rgbToHex(-4, 300, 15.6), '#00ff10');
});

test('the commonest colour comes first, and white, black and transparent pixels are skipped', () => {
    stubCanvas((i) => {
        if (i < 2000) return [255, 255, 255, 255]; // white background, the most pixels
        if (i < 2600) return [0, 0, 0, 255];       // black outline
        if (i < 3000) return [10, 20, 200, 0];     // transparent
        if (i < 3800) return [1, 177, 81, 255];    // brand green
        return [200, 30, 40, 255];                 // brand red, fewer
    });

    assert.deepEqual(extractDominantColors({} as HTMLImageElement, 3), ['#01b151', '#c81e28']);
});

test('no colours when every pixel is background', () => {
    stubCanvas(() => [250, 250, 250, 255]);
    assert.deepEqual(extractDominantColors({} as HTMLImageElement, 3), []);
});
