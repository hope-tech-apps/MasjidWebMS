/**
 * Studio's autosave body (core/studio/draftAnswers.ts): only changed sections
 * are sent, each whole, as a plain object, and blanks never reach the draft.
 * Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { autosaveBody, changedSections, compact, fingerprints, normaliseAnswers, previewBody } from '../core/studio/draftAnswers.ts';

test('compact drops blanks but keeps false and zero, which are answers', () => {
    assert.deepEqual(
        compact({ iqama_given: false, iqama: { fajr: 0, dhuhr: null }, name: '', admin: { name: '' }, platforms: [] }),
        { iqama_given: false, iqama: { fajr: 0 } },
    );
});

test('a section the server stored empty ([]) loads as an editable object, and the copy is deep', () => {
    const raw = { identity: { name: 'Al-Noor', admin: { name: 'A' } }, brand: [], unknown: { x: 1 } };
    const answers = normaliseAnswers(raw);

    assert.deepEqual(answers.brand, {});
    assert.deepEqual(answers.prayer, {});
    assert.equal((answers as any).unknown, undefined);

    answers.identity.admin!.name = 'B';
    assert.equal(raw.identity.admin.name, 'A');
});

test('nothing is changed straight after a load, even when the server ordered keys differently', () => {
    const answers = normaliseAnswers({ identity: { name: 'X', email: 'x@y.z' } });
    const saved = fingerprints(normaliseAnswers({ identity: { email: 'x@y.z', name: 'X' } }));

    assert.deepEqual(changedSections(answers, saved), []);
});

test('a field typed and then cleared is not a change', () => {
    const answers = normaliseAnswers({ identity: { name: 'X' } });
    const saved = fingerprints(answers);

    answers.identity.phone = '123';
    assert.deepEqual(changedSections(answers, saved), ['identity']);

    answers.identity.phone = '';
    assert.deepEqual(changedSections(answers, saved), []);
});

test('the PATCH body names the lock version and only the changed sections, whole', () => {
    const answers = normaliseAnswers({ identity: { name: 'X', slug: 'x' }, brand: { primary_color: '#010203' } });
    answers.identity.name = 'Y';

    const body = autosaveBody(4, answers, changedSections(answers, fingerprints(normaliseAnswers({ identity: { name: 'X', slug: 'x' }, brand: { primary_color: '#010203' } }))));

    assert.deepEqual(body, { lock_version: 4, answers: { identity: { name: 'Y', slug: 'x' } } });
    assert.equal(Object.getPrototypeOf(body), Object.prototype, 'a plain object, sent as JSON');
});

test('a step change alone sends no answers', () => {
    assert.deepEqual(autosaveBody(0, normaliseAnswers({}), [], 'features'), { lock_version: 0, current_step: 'features' });
});

test('a section emptied by the operator is sent as {}, so the stored one is cleared', () => {
    const answers = normaliseAnswers({ content: { about: 'x' } });
    answers.content.about = '';

    assert.deepEqual(autosaveBody(1, answers, ['content']).answers, { content: {} });
});

test('the preview posts the unsaved sections, or nothing when all are saved', () => {
    const answers = normaliseAnswers({ brand: { primary_color: '#000000', accent_color: '' } });

    assert.deepEqual(previewBody(answers, ['brand']), { answers: { brand: { primary_color: '#000000' } } });
    assert.deepEqual(previewBody(answers, []), {});
});

test("a preset's thumbnail previews the unsaved sections with only the preset swapped", async () => {
    const { presetPreviewBody } = await import('../core/studio/draftAnswers.ts');
    const answers = normaliseAnswers({ identity: { name: 'Al-Noor' }, layout: { preset: 'mine', approved_at: '2026-09-24T10:00:00.000Z' } });

    assert.deepEqual(presetPreviewBody(answers, ['identity'], 'other'), {
        answers: { identity: { name: 'Al-Noor' }, layout: { preset: 'other', approved_at: '2026-09-24T10:00:00.000Z' } },
    });
    assert.deepEqual(presetPreviewBody(normaliseAnswers({}), [], 'other'), { answers: { layout: { preset: 'other' } } });
    // The draft itself is not touched.
    assert.equal(answers.layout.preset, 'mine');
});

test('choosing a preset clears an approval; approving writes the preset and the time together', async () => {
    const { approvedLayout, chosenLayout, sectionBody } = await import('../core/studio/draftAnswers.ts');
    const answers = normaliseAnswers({ layout: { preset: 'mine', approved_at: '2026-09-24T10:00:00.000Z' } });

    Object.assign(answers.layout, chosenLayout('other'));
    assert.deepEqual(sectionBody(answers.layout), { preset: 'other' });

    Object.assign(answers.layout, approvedLayout('other', new Date(Date.UTC(2026, 8, 24, 12, 30))));
    assert.deepEqual(sectionBody(answers.layout), { preset: 'other', approved_at: '2026-09-24T12:30:00.000Z' });
});
