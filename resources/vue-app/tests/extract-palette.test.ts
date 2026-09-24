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
    const seen: { canvas?: { width: number; height: number }; drawn?: number[] } = {};
    (globalThis as any).document = {
        createElement: () => {
            const canvas = {
                width: 0,
                height: 0,
                getContext: () => ({
                    drawImage: (_image: unknown, _x: number, _y: number, width: number, height: number) => { seen.drawn = [width, height]; },
                    getImageData: () => ({ data }),
                }),
            };
            seen.canvas = canvas;
            return canvas;
        },
    };
    return seen;
}

const sample = (count = 6) => extractDominantColors({} as HTMLImageElement, count);

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

// The sampler was moved verbatim from the onboarding wizard (b81980da,
// OnboardingWizardView.vue): these pin each of its thresholds on both sides,
// so a "tidy" of the shared copy cannot change the colours either screen offers.

test('the logo is sampled drawn at 64x64', () => {
    const seen = stubCanvas(() => [1, 177, 81, 255]);
    sample();
    assert.deepEqual([seen.canvas?.width, seen.canvas?.height], [64, 64]);
    assert.deepEqual(seen.drawn, [64, 64]);
});

test('a pixel counts from alpha 125; at 124 it is transparent', () => {
    stubCanvas((i) => (i < 2048 ? [200, 30, 40, 124] : [1, 177, 81, 125]));
    assert.deepEqual(sample(), ['#01b151']);
});

test('near-black is a brightest channel under 18; 18 counts', () => {
    // Both land in one bucket, so a skipped 17 would also move the mean.
    stubCanvas((i) => (i < 2048 ? [17, 0, 0, 255] : [18, 10, 5, 255]));
    assert.deepEqual(sample(), ['#120a05']);
});

test('near-white is every channel over 240; 240 counts', () => {
    stubCanvas((i) => (i < 2048 ? [241, 241, 241, 255] : [240, 240, 240, 255]));
    assert.deepEqual(sample(), ['#f0f0f0']);
});

test('channels are bucketed in steps of 51, and a bucket is the mean of its pixels', () => {
    // 100 and 120 share a bucket (both round to 2 at /51): one colour, their mean.
    stubCanvas((i) => (i % 2 ? [100, 100, 100, 255] : [120, 120, 120, 255]));
    assert.deepEqual(sample(), ['#6e6e6e']);

    // 76 and 80 do not (1 and 2 at /51), so two colours, the commoner first.
    stubCanvas((i) => (i < 3000 ? [80, 100, 100, 255] : [76, 100, 100, 255]));
    assert.deepEqual(sample(), ['#506464', '#4c6464']);
});
