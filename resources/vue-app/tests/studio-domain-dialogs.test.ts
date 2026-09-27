// The Detach dialog in Studio's domain panel (W2 S3) puts a host and the
// server's plan into dialog HTML. Pinned here to the house rule in
// core/plugins/swalSanitize.ts: data in `titleText`, and every piece of data in
// `html` through escapeHtml, so a host or a step reads as written.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const panel = readFileSync(new URL('../components/super/studio/StudioDomainAttachPanel.vue', import.meta.url), 'utf8');

test('the detach dialog carries the host as text, never as a title rendered as HTML', () => {
    assert.match(panel, /titleText: `Detach \$\{domain\.host\}\?`/);
    assert.doesNotMatch(panel, /\btitle: `[^`]*\$\{/, 'no dialog title interpolates data as HTML');
});

test('every piece of data in the detach dialog body is escaped', () => {
    const body = panel.slice(panel.indexOf('const detachDialog'), panel.indexOf('const detachDomain'));
    assert.ok(body.length > 0, 'the dialog builder is where the test expects it');
    assert.match(body, /\$\{escapeHtml\(domain\.host\)\}/);
    assert.match(body, /<li>\$\{escapeHtml\(line\)\}<\/li>/);
    assert.match(body, /\$\{escapeHtml\(title\)\}/);
    const interpolations = body.match(/\$\{[^}]*\}/g) ?? [];
    for (const piece of interpolations) {
        assert.ok(/escapeHtml\(|^\$\{tag\}$|^\$\{items\}$/.test(piece), `unescaped data in the dialog: ${piece}`);
    }
});
