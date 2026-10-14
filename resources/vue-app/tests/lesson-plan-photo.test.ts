import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { applyPhotoFields, clearEditedSuggestions, photoAvailable, photoSlots } from '../core/helpers/lessonPlanPhoto.ts';

test('photo permission fails closed and requires server pilot decision', () => {
    assert.equal(photoAvailable(undefined), false);
    assert.equal(photoAvailable({ lesson_plan_photo_fill: false }), false);
    assert.equal(photoAvailable({ lesson_plan_photo_fill: true }), true);
});

test('photo fill keeps every populated field and tags only suggestions actually filled', () => {
    const form: any = { body: 'My activities', objective: '', learning_outcomes: [''], teaching_methods: ['modeling'], subject: 'Mine' };
    const result = applyPhotoFields(form, { body: 'Photo', objective: '[cut off in the photo]', learning_outcomes: ['Suggested outcome'], teaching_methods: ['storytelling'], subject: 'Model subject' }, ['learning_outcomes', 'teaching_methods'], new Set());
    assert.equal(form.body, 'My activities');
    assert.equal(form.subject, 'Mine');
    assert.equal(form.objective, '[cut off in the photo]');
    assert.deepEqual(form.learning_outcomes, ['Suggested outcome']);
    assert.deepEqual(result.suggestions, { learning_outcomes: JSON.stringify(['Suggested outcome']) });
    assert.equal(result.kept, true);
});

test('editing a suggested value clears its tag, including arrays', () => {
    const tags = { learning_outcomes: JSON.stringify(['Read']), differentiation_support: JSON.stringify('Blocks') };
    assert.deepEqual(clearEditedSuggestions(tags, { learning_outcomes: ['Edited'], differentiation_support: 'Blocks' }), { differentiation_support: JSON.stringify('Blocks') });
    assert.equal(photoSlots(9, 4), 1);
    assert.equal(photoSlots(10, 1), 0);
});

test('editor exposes photo input, suggestion labels, and saves deferred photos', () => {
    const source = readFileSync(new URL('../views/teacher/TeacherClass.vue', import.meta.url), 'utf8');
    assert.match(source, /v-if="canPhotoFill"/);
    assert.match(source, /accept="image\/\*"/);
    assert.match(source, /Suggested: check before saving/);
    assert.match(source, /from your photo/);
    assert.match(source, /photos\[\]/);
    assert.match(source, /clearEditedSuggestions/);
});

test('browser re-encodes to bounded JPEG and releases the original object URL', async () => {
    const { downscalePhoto } = await import('../core/helpers/lessonPlanPhoto.ts');
    const globals = { Image: globalThis.Image, document: globalThis.document, create: URL.createObjectURL, revoke: URL.revokeObjectURL };
    let revoked = ''; let encoded: any[] = []; let drawn: any[] = [];
    const canvas: any = { width: 0, height: 0, getContext: () => ({ fillRect() {}, drawImage: (...args: any[]) => { drawn = args; } }), toBlob: (fn: any, ...args: any[]) => { encoded = args; fn(new Blob(['invented pixels'], { type: 'image/jpeg' })); } };
    try {
        globalThis.Image = class { naturalWidth = 4000; naturalHeight = 3000; src = ''; async decode() {} } as any;
        globalThis.document = { createElement: () => canvas } as any;
        URL.createObjectURL = () => 'blob:invented'; URL.revokeObjectURL = (url) => { revoked = url; };
        const result = await downscalePhoto(new File(['invented EXIF metadata'], 'original.png'), 0);
        assert.equal(canvas.width, 2000); assert.equal(canvas.height, 1500);
        assert.deepEqual(encoded, ['image/jpeg', 0.85]);
        assert.equal(drawn.length, 5); assert.equal(result.name, 'lesson-photo-1.jpg');
        assert.equal(result.type, 'image/jpeg'); assert.equal(await result.text(), 'invented pixels');
        assert.equal(revoked, 'blob:invented');
    } finally {
        globalThis.Image = globals.Image; globalThis.document = globals.document;
        URL.createObjectURL = globals.create; URL.revokeObjectURL = globals.revoke;
    }
});

test('deferred Save uses multipart fields, retains explicit empty file links and PUT override', async () => {
    const { photoSaveForm } = await import('../core/helpers/lessonPlanPhoto.ts');
    const form = photoSaveForm({ body: 'Written', teaching_methods: ['modeling'], resource_ids: [], subject: null, attachments: undefined }, [new File(['blank'], 'lesson-photo-1.jpg')], 'put');
    assert.equal(form.get('body'), 'Written'); assert.equal(form.get('teaching_methods[0]'), 'modeling');
    assert.equal(form.get('resource_ids'), '[]'); assert.equal(form.get('subject'), '');
    assert.equal(form.get('_method'), 'PUT'); assert.equal(form.has('attachments'), false);
    assert.equal((form.get('photos[]') as File).name, 'lesson-photo-1.jpg');
});
