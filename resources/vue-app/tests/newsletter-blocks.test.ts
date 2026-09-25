/**
 * The newsletter layout editor's rules (core/helpers/newsletterBlocks.ts), and that
 * the composer and the editor use them. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import {
    PREVIEW_ORIGIN,
    appendNewsletter,
    imageKeysInUse,
    keyMinter,
    moveBlock,
    newBlock,
    removeBlock,
    toPayload,
    withLocalImages,
    type EditorBlock,
} from '../core/helpers/newsletterBlocks.ts';

const recorder = () => {
    const fields: [string, unknown][] = [];
    return { fields, append: (name: string, value: unknown) => { fields.push([name, value]); } };
};

const layout = (): EditorBlock[] => {
    const key = keyMinter();
    return [
        newBlock('heading', 'u1', key),
        newBlock('image', 'u2', key),
        newBlock('image_row', 'u3', key),
        newBlock('button', 'u4', key),
    ];
};

test('every picture slot gets its own upload key the moment it exists', () => {
    const blocks = layout();
    assert.deepEqual(imageKeysInUse(toPayload(blocks)), ['img-1', 'img-2', 'img-3']);
});

test('reordering moves one block and never mutates the list it was given', () => {
    const blocks = layout();
    const before = blocks.map(b => b.uid);

    assert.deepEqual(moveBlock(blocks, 0, 2).map(b => b.uid), ['u2', 'u3', 'u1', 'u4']);
    assert.deepEqual(moveBlock(blocks, 3, 0).map(b => b.uid), ['u4', 'u1', 'u2', 'u3']);
    assert.deepEqual(blocks.map(b => b.uid), before, 'the original list is untouched');

    // Up from the top and down from the bottom change nothing.
    assert.deepEqual(moveBlock(blocks, 0, -1).map(b => b.uid), before);
    assert.deepEqual(moveBlock(blocks, 3, 4).map(b => b.uid), before);

    assert.deepEqual(removeBlock(blocks, 1).map(b => b.uid), ['u1', 'u3', 'u4']);
});

test('the payload is the stored shape with no editor ids', () => {
    const [heading] = layout();
    assert.deepEqual(toPayload([heading]), [{ type: 'heading', text: '', align: 'left' }]);
    assert.equal(JSON.stringify(toPayload(layout())).includes('uid'), false);
});

test('a send carries the layout as JSON and only the files a block still uses', () => {
    const blocks = layout();
    const files = {
        'img-1': new Blob(['a']),
        'img-2': new Blob(['b']),
        'img-3': new Blob(['c']),
        'img-9': new Blob(['removed block']),
    };

    const fd = recorder();
    appendNewsletter(fd, blocks, files);

    assert.deepEqual(fd.fields.map(([name]) => name), ['blocks', 'block_images[img-1]', 'block_images[img-2]', 'block_images[img-3]']);
    assert.deepEqual(JSON.parse(fd.fields[0][1] as string), toPayload(blocks));
});

test('a send without a layout adds nothing at all', () => {
    const fd = recorder();
    appendNewsletter(fd, [], { 'img-1': new Blob(['a']) });
    assert.deepEqual(fd.fields, []);
});

test('the preview shows the admin\'s own copy of each picture, and only an image data URL', () => {
    const png = 'data:image/png;base64,iVBORw0KGgo=';
    const html = `<img src="${PREVIEW_ORIGIN}/newsletter-image/img-1" alt="a"><img src="${PREVIEW_ORIGIN}/newsletter-image/img-2" alt="b">`
        + `<img src="${PREVIEW_ORIGIN}/newsletter-image/img-3" alt="c"><img src="${PREVIEW_ORIGIN}/composer-image" alt="d">`;

    const out = withLocalImages(html, {
        'img-1': png,
        // Not an image data URL: must never be written into the document.
        'img-2': 'javascript:alert(1)',
        'img-3': 'data:image/png;base64,AAAA" onerror="alert(1)',
    }, png);

    assert.equal(out, `<img src="${png}" alt="a"><img src="${PREVIEW_ORIGIN}/newsletter-image/img-2" alt="b">`
        + `<img src="${PREVIEW_ORIGIN}/newsletter-image/img-3" alt="c"><img src="${png}" alt="d">`);
});

test('a key that is a prefix of another is not confused with it', () => {
    const png = 'data:image/png;base64,QUJD';
    const html = `<img src="${PREVIEW_ORIGIN}/newsletter-image/img-1"><img src="${PREVIEW_ORIGIN}/newsletter-image/img-10">`;
    assert.equal(withLocalImages(html, { 'img-1': png }), `<img src="${png}"><img src="${PREVIEW_ORIGIN}/newsletter-image/img-10">`);
});

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const composer = readFileSync(join(root, 'views/dashboard/broadcasts/BroadcastComposerView.vue'), 'utf8');
const editor = readFileSync(join(root, 'components/broadcasts/NewsletterEditor.vue'), 'utf8');
const preview = readFileSync(join(root, 'components/broadcasts/NewsletterPreview.vue'), 'utf8');

test('the composer sends the layout through appendNewsletter, and only with email ticked', () => {
    assert.match(composer, /if \(newsletterActive\.value\) appendNewsletter\(fd, blocks\.value, blockFiles\.value\)/);
    assert.match(composer, /const newsletterActive = computed\(\(\) => form\.value\.channels\.includes\('email'\) && useNewsletter\.value && blocks\.value\.length > 0\)/);
});

test('the editor reorders through moveBlock and the preview frame runs no script', () => {
    assert.match(editor, /moveBlock\(props\.modelValue, index, index \+ direction\)/);
    // An empty sandbox: no scripts, no same-origin, no navigation of the admin tab.
    assert.match(preview, /<iframe[^>]*sandbox=""/);
    assert.match(preview, /withLocalImages\(/);
});

test('a send the server refuses (422) keeps the composer open; any other outcome leaves as before', () => {
    // A refused send stored nothing, so staying loses nothing and saves the layout.
    assert.match(composer, /refused = e\.response\?\.status === 422/);
    assert.match(composer, /if \(!refused\) router\.push\('\/masjid\/broadcasts'\)/);
    assert.doesNotMatch(composer, /^\s*router\.push\('\/masjid\/broadcasts'\)\s*$/m);
});
