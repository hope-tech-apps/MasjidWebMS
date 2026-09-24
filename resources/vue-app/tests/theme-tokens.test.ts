/**
 * Brand Studio fonts and header/footer style (core/helpers/themeTokens.ts): a saved font
 * is never dropped from the public site as a side effect, and nothing is sent until a
 * font or layout choice changes. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { buildTokens, CURRENT, fontChoices, googleCss2Families, styleFromTokens, themePayload } from '../core/helpers/themeTokens.ts';

const LORA_URL = 'https://fonts.googleapis.com/css2?family=Lora:wght@300;400;800&family=Inter:wght@400;500;600;700&display=optional';
const saved = {
    typography: { headingFamily: "'Lora', serif", bodyFamily: "'Inter', sans-serif", fontsUrl: LORA_URL, scale: { body: 17 } },
    layout: { footer: 'columns' },
    color: { primary: '#123456' },
};

test('the saved custom font shows as a choice, and the list font as itself', () => {
    const style = styleFromTokens(saved);
    assert.deepEqual(style, { heading: CURRENT, body: 'inter', header: 'default', footer: 'columns' });
    assert.equal(fontChoices(saved, 'heading').at(-1)?.stack, "'Lora', serif");
});

test('finding 7: changing only the header style leaves typography byte-for-byte as saved', () => {
    const style = { ...styleFromTokens(saved), header: 'overlay' };
    const tree = buildTokens(saved, style, { fonts: false, layout: true });
    assert.deepEqual(tree.typography, saved.typography);
    assert.deepEqual(tree.layout, { footer: 'columns', header: 'overlay' });
    assert.deepEqual(tree.color, saved.color, 'other token families are untouched');
});

test('changing the body font keeps the saved face\'s families (and weights) in the stylesheet', () => {
    const style = { ...styleFromTokens(saved), body: 'poppins' };
    const tree = buildTokens(saved, style, { fonts: true, layout: false });
    assert.equal(tree.typography.headingFamily, "'Lora', serif");
    assert.equal(tree.typography.bodyFamily, "'Poppins', sans-serif");
    const families = googleCss2Families(tree.typography.fontsUrl);
    assert.ok(families?.includes('Lora:wght@300;400;800'), JSON.stringify(families));
    assert.ok(families?.includes('Poppins:wght@400;500;600;700'));
    assert.deepEqual(tree.layout, saved.layout, 'a font change does not touch the layout');
});

test('with only list fonts the stylesheet names exactly those; with none it is removed', () => {
    const lists = buildTokens(null, { heading: 'playfair', body: 'inter', header: 'default', footer: 'default' }, { fonts: true, layout: false });
    assert.deepEqual(googleCss2Families(lists.typography.fontsUrl), ['Playfair Display:wght@400;600;700', 'Inter:wght@400;500;600;700']);
    const none = buildTokens(saved, { heading: '', body: '', header: 'default', footer: 'columns' }, { fonts: true, layout: false });
    assert.equal(none.typography.fontsUrl, undefined);
    assert.equal(none.typography.headingFamily, undefined);
    assert.deepEqual(none.typography.scale, { body: 17 }, 'unrelated typography stays');
});

test('a saved stylesheet this screen cannot extend is kept while its face is chosen', () => {
    const v1 = { typography: { headingFamily: "'Lora', serif", fontsUrl: 'https://fonts.googleapis.com/css?family=Lora' } };
    const tree = buildTokens(v1, { heading: CURRENT, body: 'inter', header: 'default', footer: 'default' }, { fonts: true, layout: false });
    assert.equal(tree.typography.fontsUrl, 'https://fonts.googleapis.com/css?family=Lora');
});

test('colours alone post no tokens at all; a font or layout change posts the whole tree', () => {
    const colours = { primary_color: '#111111' };
    const style = styleFromTokens(saved);
    assert.deepEqual(themePayload(colours, saved, style, { fonts: false, layout: false }), colours);
    assert.ok('tokens' in themePayload(colours, saved, { ...style, footer: 'default' }, { fonts: false, layout: true }));
});

test('Brand Studio marks fonts only from the font selects and layout only from the style selects', () => {
    const view = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '../views/dashboard/ThemeSettingsView.vue'), 'utf8');
    for (const [id, flag] of [['theme-heading-font', 'fonts'], ['theme-body-font', 'fonts'], ['theme-header-style', 'layout'], ['theme-footer-style', 'layout']]) {
        assert.match(view, new RegExp(`<select id="${id}"[^>]*@change="touched\\.${flag} = true"`), id);
    }
    // Save and the preview post the same thing, built by the rules above.
    assert.match(view, /const themePayload = \(\) => buildThemePayload\(settingsModel\.value, savedTokens\.value, styleModel\.value, touched\.value\);/);
    assert.match(view, /ApiService\.post\(`\/api\/admin\/masjids\/\$\{masjidStore\.masjid\?\.id\}\/theme`, themePayload\(\)\)/);
    // A fresh load starts untouched, so a later save posts no tokens unless a choice changed.
    assert.match(view, /styleModel\.value = styleFromTokens\(savedTokens\.value\);\s*touched\.value = \{ fonts: false, layout: false \};/);
});

