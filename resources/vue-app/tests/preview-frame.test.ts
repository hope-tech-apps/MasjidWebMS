/**
 * The admin side of the live-preview frame (core/helpers/previewFrame.ts) and that
 * LivePreviewPane.vue applies it. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { PREVIEW_FRAME_SANDBOX, isReadyFromFrame, overridesMessage, postTargetFor } from '../core/helpers/previewFrame.ts';

const ORIGIN = 'https://preview.manara.hopetechapps.com';
const frameWindow = { name: 'the pane\'s frame' };
const ready = { source: 'manara-preview', v: 1, type: 'ready', path: '/', org: 13, surface: 'pages' };

test('finding 3: the frame is sandboxed without any top navigation', () => {
    const tokens = PREVIEW_FRAME_SANDBOX.split(' ');
    for (const needed of ['allow-scripts', 'allow-same-origin', 'allow-forms', 'allow-popups', 'allow-popups-to-escape-sandbox']) {
        assert.ok(tokens.includes(needed), needed);
    }
    assert.equal(tokens.some((t) => t.startsWith('allow-top-navigation')), false);
});

test('a ready is believed only from this pane\'s frame at the preview origin', () => {
    assert.equal(isReadyFromFrame({ origin: ORIGIN, source: frameWindow, data: ready }, frameWindow, ORIGIN), true);
    assert.equal(isReadyFromFrame({ origin: ORIGIN, source: { other: 1 }, data: ready }, frameWindow, ORIGIN), false, 'another window');
    assert.equal(isReadyFromFrame({ origin: 'https://evil.example', source: frameWindow, data: ready }, frameWindow, ORIGIN), false, 'another origin');
    assert.equal(isReadyFromFrame({ origin: ORIGIN, source: frameWindow, data: { ...ready, type: 'x' } }, frameWindow, ORIGIN), false, 'not a ready');
    assert.equal(isReadyFromFrame({ origin: ORIGIN, source: undefined, data: ready }, undefined, ORIGIN), false, 'no frame yet');
    assert.equal(isReadyFromFrame({ origin: '', source: frameWindow, data: ready }, frameWindow, ''), false, 'no origin yet');
});

test('unsaved values go only to the preview origin, never to "*"', () => {
    assert.equal(postTargetFor(ORIGIN), ORIGIN);
    for (const bad of ['*', '', null, undefined, 'https://x.example/', 'javascript:alert(1)']) {
        assert.equal(postTargetFor(bad as string), null, String(bad));
    }
});

test('the message is plain JSON in the protocol envelope', () => {
    const live = { pages: [{ id: 1, sections: [{ id: 2, content: { heading: 'x', skip: undefined } }] }] };
    assert.deepEqual(overridesMessage(live), { source: 'manara-admin', v: 1, type: 'overrides', overrides: { pages: [{ id: 1, sections: [{ id: 2, content: { heading: 'x' } }] }] } });
});

const pane = readFileSync(join(dirname(dirname(fileURLToPath(import.meta.url))), 'components/preview/LivePreviewPane.vue'), 'utf8');

test('LivePreviewPane sandboxes the frame and uses these rules', () => {
    assert.match(pane, /:sandbox="PREVIEW_FRAME_SANDBOX"/);
    assert.match(pane, /if \(!isReadyFromFrame\(event, frame\.value\?\.contentWindow, origin\.value\)\) return/);
    assert.match(pane, /const targetOrigin = postTargetFor\(origin\.value\)/);
    assert.match(pane, /target\.postMessage\(overridesMessage\(props\.overrides\), targetOrigin\)/);
    assert.doesNotMatch(pane, /postMessage\([^)]*'\*'/);
});
