/**
 * A live organisation's Brand card: which colours the theme save takes
 * (S9-2) and which contrast report may speak for the colours being saved
 * (S9-5). Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isCompleteWhileTyping, isHex6, isThemeHex } from '../core/studio/foundationGate.ts';
import { failingPairLines } from '../core/studio/paletteLabels.ts';
import type { StudioPaletteReport } from '../core/types/data/Studio.ts';

test('the theme forms #RGB, #RRGGBB and #RRGGBBAA are all accepted, nothing else', () => {
    for (const ok of ['#FA0', '#0a3d62', '#0A3D62FF']) assert.equal(isThemeHex(ok), true, ok);
    for (const bad of ['', 'fa0', '#FA', '#FA00', '#0A3D6', '#0A3D62F', '#0A3D62FFF', '#GGGGGG', null, undefined]) {
        assert.equal(isThemeHex(bad), false, String(bad));
    }
});

test('the draft form stays six digits only', () => {
    assert.equal(isHex6('#FA0'), false);
    assert.equal(isHex6('#0A3D62FF'), false);
    assert.equal(isHex6('#0A3D62'), true);
});

const failing = (): StudioPaletteReport => ({
    valid: false,
    blocking_failures: ['text_on_background'],
    pairs: [{ key: 'text_on_background', ratio: 2.1, required: 4.5 }] as unknown as StudioPaletteReport['pairs'],
    tokens: { color: {} },
    aspect_warning: false,
});
const idle = { queued: false, loading: false, error: null as string | null };

test('a settled report names its failing pairs', () => {
    assert.deepEqual(failingPairLines(failing(), idle), ['Body text on the background: 2.10:1, needs 4.5:1']);
    assert.deepEqual(failingPairLines({ ...failing(), valid: true, blocking_failures: [] }, idle), []);
});

test('a report left behind by a failed preview does not describe the colours being saved', () => {
    const valid = { ...failing(), valid: true, blocking_failures: [] };
    assert.equal(failingPairLines(valid, { ...idle, error: 'The preview could not be updated.' }), null);
});

test('a report still catching up, or none at all, is not a verdict either', () => {
    assert.equal(failingPairLines(failing(), { ...idle, queued: true }), null);
    assert.equal(failingPairLines(failing(), { ...idle, loading: true }), null);
    assert.equal(failingPairLines(null, idle), null);
});

test('typing on the live card emits only a full 6 or 8 digit code, never the #RGB prefix of one', () => {
    for (const ok of ['#0a3d62', '#0A3D62FF']) assert.equal(isCompleteWhileTyping(ok), true, ok);
    for (const partial of ['#0a3', '#0A3D', '#0A3D6', '#0A3D62F', '', null]) {
        assert.equal(isCompleteWhileTyping(partial), false, String(partial));
    }
});
