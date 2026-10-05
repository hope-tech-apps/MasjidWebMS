/**
 * A PDF attached to a web page: the page tool's own file check, how it reads a stored document's
 * address, and what it sends and says (core/helpers/sectionDocumentFile.ts, pagesStore.uploadPageDocument).
 *
 * The check must refuse what the server refuses by name, type and size, in sentences that say what to
 * do, BEFORE a large file is sent. Run: npm run test:spa
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as documentFile from '../core/helpers/sectionDocumentFile.ts';
import { httpError, loadTs } from './support/mountSfc.ts';

const {
    SECTION_DOCUMENT_ACCEPT, SECTION_DOCUMENT_ICON, SECTION_DOCUMENT_MAX_BYTES, SECTION_DOCUMENT_MIME,
    SECTION_DOCUMENT_UPLOAD_FAILED, sectionDocumentFileProblem, sectionDocumentLabel, sectionDocumentName,
    sectionDocumentUploadProblem,
} = documentFile;

const WRONG_FILE = 'Choose a PDF file (a name ending in .pdf).';

test('the limit is the server\'s max:25600 in bytes, and a PDF at the limit is accepted', () => {
    assert.equal(SECTION_DOCUMENT_MAX_BYTES, 26_214_400);
    assert.equal(SECTION_DOCUMENT_MIME, 'application/pdf');
    assert.equal(SECTION_DOCUMENT_ACCEPT, 'application/pdf,.pdf');
    assert.equal(sectionDocumentFileProblem({ name: 'Academic Calendar 2026.pdf', type: 'application/pdf', size: 480_000 }), null);
    assert.equal(sectionDocumentFileProblem({ name: 'calendar.pdf', type: 'application/pdf', size: SECTION_DOCUMENT_MAX_BYTES }), null);
});

test('one byte over the limit is refused, and the sentence gives the size and what to do', () => {
    assert.equal(
        sectionDocumentFileProblem({ name: 'calendar.pdf', type: 'application/pdf', size: SECTION_DOCUMENT_MAX_BYTES + 1 }),
        'This file is 25.0 MB. The limit is 25 MB: export it at a lower quality or split it into parts.',
    );
    assert.equal(
        sectionDocumentFileProblem({ name: 'calendar.pdf', type: 'application/pdf', size: 32_715_571 }),
        'This file is 31.2 MB. The limit is 25 MB: export it at a lower quality or split it into parts.',
    );
});

test('a file the computer knows is not a PDF is refused, whatever its name and size', () => {
    for (const type of ['image/png', 'text/html', 'video/mp4', 'application/msword', 'application/octet-stream']) {
        assert.equal(sectionDocumentFileProblem({ name: 'calendar.pdf', type, size: 1024 }), WRONG_FILE, type);
    }
});

test('a name that does not end in .pdf is refused, as the server refuses it; the case does not matter', () => {
    for (const name of ['calendar.html', 'calendar.pdf.html', 'calendar', 'calendar.docx', 'pdf']) {
        assert.equal(sectionDocumentFileProblem({ name, type: 'application/pdf', size: 1024 }), WRONG_FILE, name);
    }
    assert.equal(sectionDocumentFileProblem({ name: 'SCAN_0001.PDF', type: 'application/pdf', size: 1024 }), null);
});

test('a .pdf the computer gave no type is sent, because the server reads the bytes and never the type', () => {
    assert.equal(sectionDocumentFileProblem({ name: 'calendar.pdf', type: '', size: 1024 }), null);
    // No type and no .pdf name: nothing says it is a PDF.
    assert.equal(sectionDocumentFileProblem({ name: 'calendar', type: '', size: 1024 }), WRONG_FILE);
});

test('a stored page document\'s address is recognised and gives its file name', () => {
    assert.equal(sectionDocumentName('https://platform.example.test/storage/412/academic-calendar-2026.pdf'), 'academic-calendar-2026.pdf');
    assert.equal(sectionDocumentName('http://localhost/storage/7/document.pdf'), 'document.pdf');
    // A person may add a page number or a cache mark; it is still that document.
    assert.equal(sectionDocumentName('https://platform.example.test/storage/412/calendar.pdf#page=3'), 'calendar.pdf');
    assert.equal(sectionDocumentName('https://platform.example.test/storage/412/calendar.pdf?v=2'), 'calendar.pdf');
    assert.equal(sectionDocumentName('  https://platform.example.test/storage/412/calendar.pdf  '), 'calendar.pdf');
});

test('any other address is not taken for a page document', () => {
    for (const value of [
        '', null, undefined, 42,
        'https://example.org/files/calendar.pdf',                         // a PDF somewhere else
        'https://platform.example.test/storage/412/photo.jpg',            // a section image
        'https://platform.example.test/storage/412/Calendar 2026.pdf',    // not a name the server makes
        'https://platform.example.test/storage/412/calendar.pdf.html',
        'https://platform.example.test/storage/lunch-flyers/calendar.pdf',
        '/storage/412/calendar.pdf',                                      // root-relative: the website would look on itself
        'mailto:office@example.test', 'tel:+15550100', '/admissions',
        'blob:https://platform.example.test/3f1c', 'data:application/pdf;base64,JVBERi0=',
    ]) {
        assert.equal(sectionDocumentName(value), null, String(value));
    }
});

test('a label is made from a file\'s name, and is empty when there is nothing to read', () => {
    assert.equal(sectionDocumentLabel('Academic Calendar 2026.pdf'), 'Academic Calendar 2026');
    assert.equal(sectionDocumentLabel('Academic_Calendar__2026.PDF'), 'Academic Calendar 2026');
    // The server answers the name without its ending; a dash inside a name is part of it.
    assert.equal(sectionDocumentLabel('2026-27 School Calendar'), '2026-27 School Calendar');
    assert.equal(sectionDocumentLabel('  spaced   out  .pdf'), 'spaced out');
    assert.equal(sectionDocumentLabel('.pdf'), '');
    assert.equal(sectionDocumentLabel('___.pdf'), '');
    assert.equal(sectionDocumentLabel(undefined), '');
    assert.equal(SECTION_DOCUMENT_ICON, 'bi-file-earmark-arrow-down');
});

test('a refusal by the server is shown word for word; anything else gets one plain sentence', () => {
    const refusal = 'This file is not a PDF. Save or print it as a PDF, then upload that.';
    assert.equal(sectionDocumentUploadProblem(httpError(422, { status: 'failed', data: { document: [refusal] } })), refusal);

    assert.equal(
        sectionDocumentUploadProblem(httpError(413, {})),
        'This PDF is too large for the server to accept. Export it at a lower quality, or split it into parts.',
    );
    assert.equal(
        sectionDocumentUploadProblem(httpError(403, { status: 'error', message: 'Web Pages Management is switched off for this organisation.' })),
        'Web Pages Management is switched off for this organisation.',
    );
    // What the server says in place of a sentence it will not show is not shown either.
    assert.equal(sectionDocumentUploadProblem(httpError(403, { message: 'Request failed.' })), 'You may not upload a PDF for this organisation.');

    for (const failure of [
        httpError(500, { status: 'failed', data: 'An error occurred while processing your request.' }),
        httpError(422, { status: 'failed', data: {} }),
        new Error('Network Error'),
        null,
    ]) {
        assert.equal(sectionDocumentUploadProblem(failure), SECTION_DOCUMENT_UPLOAD_FAILED);
    }
});

/** pagesStore with its imports swapped: the API call is recorded, the organisation is number 7. */
async function loadStore(post: (url: string, body: any) => Promise<any>, masjid: any = { id: 7 }) {
    const calls: Array<{ url: string; body: any }> = [];
    const mod = await loadTs('stores/masjid/pagesStore.ts', {
        pinia: { defineStore: (_id: string, setup: () => any) => setup },
        vue: await import('vue'),
        '@/core/types/data/masjid-related/Page': {},
        '@/core/types/data/masjid-related/PageSection': {},
        '../masjidStore': { useMasjidStore: () => ({ masjid }) },
        '@/core/services/ApiService': { default: { post: (url: string, body: any) => { calls.push({ url, body }); return post(url, body); } } },
        axios: {},
        '@/core/types/data/interfaces/PaginatedData': {},
        '@/core/helpers/sectionDocumentFile': documentFile,
    });

    return { store: mod.usePagesStore(), calls };
}

const pdf = () => new File(['%PDF-1.4\n'], 'Academic Calendar 2026.pdf', { type: 'application/pdf' });

test('the store sends the file as `document` to the organisation\'s documents route and returns the address', async () => {
    const answer = { url: 'https://platform.example.test/storage/412/academic-calendar-2026.pdf', name: 'Academic Calendar 2026', size: 9 };
    const { store, calls } = await loadStore(async () => ({ data: { status: 'success', data: answer } }));
    const file = pdf();

    assert.deepEqual(await store.uploadPageDocument(file), answer);

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/api/admin/masjids/7/pages/documents');
    // The payload itself: one field, named as the server's rule names it, holding THE file.
    assert.ok(calls[0].body instanceof FormData);
    assert.deepEqual([...calls[0].body.keys()], ['document']);
    const sent = calls[0].body.get('document') as File;
    assert.equal(sent.name, 'Academic Calendar 2026.pdf');
    assert.equal(sent.size, file.size);
});

test('the store throws the server\'s own sentence for a refused file', async () => {
    const refusal = 'This PDF is larger than 25 MB. Export it at a lower quality, or split it into parts.';
    const { store } = await loadStore(async () => { throw httpError(422, { status: 'failed', data: { document: [refusal] } }); });

    await assert.rejects(() => store.uploadPageDocument(pdf()), { message: refusal });
});

test('an answer without an absolute address is a failed upload, never an address written into a page', async () => {
    for (const data of [
        { url: '/storage/412/academic-calendar-2026.pdf', name: 'x', size: 1 },   // the website would look on itself
        { url: '', name: 'x', size: 1 },
        { name: 'x', size: 1 },
        null,
    ]) {
        const { store } = await loadStore(async () => ({ data: { status: 'success', data } }));
        await assert.rejects(() => store.uploadPageDocument(pdf()), { message: SECTION_DOCUMENT_UPLOAD_FAILED }, JSON.stringify(data));
    }

    const { store } = await loadStore(async () => ({ data: { status: 'failed', data: 'no' } }));
    await assert.rejects(() => store.uploadPageDocument(pdf()), { message: SECTION_DOCUMENT_UPLOAD_FAILED });
});

test('with no organisation selected nothing is sent', async () => {
    const { store, calls } = await loadStore(async () => ({ data: {} }), null);

    await assert.rejects(() => store.uploadPageDocument(pdf()), { message: SECTION_DOCUMENT_UPLOAD_FAILED });
    assert.equal(calls.length, 0);
});
