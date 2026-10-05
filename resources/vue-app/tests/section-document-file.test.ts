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
    SECTION_DOCUMENT_TOO_MANY, SECTION_DOCUMENT_UPLOAD_FAILED, sectionDocumentFileProblem, sectionDocumentLabel,
    sectionDocumentName, sectionDocumentPath, sectionDocumentsIn, sectionDocumentsLeaving, sectionDocumentsNotSaved,
    sectionDocumentUploadProblem,
} = documentFile;

// The server's own two sentences (StorePageDocumentRequest), so the office reads the same words
// whichever side refuses, and both say what to do.
const NOT_A_PDF = 'This file is not a PDF. Save or print it as a PDF, then upload that.';
const WRONG_NAME = 'This file\'s name does not end in .pdf. Save or print it as a PDF, then upload that.';

test('the limit is the server\'s max:25600 in bytes, and a PDF at the limit is accepted', () => {
    assert.equal(SECTION_DOCUMENT_MAX_BYTES, 26_214_400);
    assert.equal(SECTION_DOCUMENT_MIME, 'application/pdf');
    assert.equal(SECTION_DOCUMENT_ACCEPT, 'application/pdf,.pdf');
    assert.equal(sectionDocumentFileProblem({ name: 'Academic Calendar 2026.pdf', type: 'application/pdf', size: 480_000 }), null);
    assert.equal(sectionDocumentFileProblem({ name: 'calendar.pdf', type: 'application/pdf', size: SECTION_DOCUMENT_MAX_BYTES }), null);
});

test('one byte over the limit is refused, and the sentence gives the size and what to do', () => {
    const over = (size: number) => sectionDocumentFileProblem({ name: 'calendar.pdf', type: 'application/pdf', size });
    const sentence = (size: string) => `This file is ${size}. The limit is 25 MB: export it at a lower quality or split it into parts.`;

    assert.equal(over(32_715_571), sentence('31.2 MB'));
    assert.equal(over(27_000_000), sentence('25.7 MB'));
    assert.equal(over(26_300_000), sentence('25.1 MB'));
});

test('a file a little over the limit is never told it is 25.0 MB against a limit of 25 MB', () => {
    // Every size that one decimal place would print as the limit itself, from one byte over.
    for (const size of [SECTION_DOCUMENT_MAX_BYTES + 1, SECTION_DOCUMENT_MAX_BYTES + 1024, SECTION_DOCUMENT_MAX_BYTES + 40_000]) {
        const sentence = sectionDocumentFileProblem({ name: 'calendar.pdf', type: 'application/pdf', size });

        assert.equal(sentence, 'This file is a little over 25 MB. The limit is 25 MB: export it at a lower quality or split it into parts.', String(size));
        assert.ok(!sentence!.includes('25.0'), String(size));
    }
});

test('a file the computer knows is not a PDF is refused, whatever its name and size, and told what to do', () => {
    for (const type of ['image/png', 'text/html', 'video/mp4', 'application/msword', 'application/octet-stream']) {
        assert.equal(sectionDocumentFileProblem({ name: 'calendar.pdf', type, size: 1024 }), NOT_A_PDF, type);
    }
    // A document from a word processor, the file an office is most likely to choose by mistake.
    assert.equal(sectionDocumentFileProblem({ name: 'calendar.docx', type: 'application/msword', size: 1024 }), NOT_A_PDF);
    assert.ok(NOT_A_PDF.endsWith('Save or print it as a PDF, then upload that.'));
});

test('a name that does not end in .pdf is refused, as the server refuses it; the case does not matter', () => {
    for (const name of ['calendar.html', 'calendar.pdf.html', 'calendar', 'calendar.docx', 'pdf']) {
        assert.equal(sectionDocumentFileProblem({ name, type: 'application/pdf', size: 1024 }), WRONG_NAME, name);
    }
    assert.equal(sectionDocumentFileProblem({ name: 'SCAN_0001.PDF', type: 'application/pdf', size: 1024 }), null);
});

test('a .pdf the computer gave no type is sent, because the server reads the bytes and never the type', () => {
    assert.equal(sectionDocumentFileProblem({ name: 'calendar.pdf', type: '', size: 1024 }), null);
    // No type and no .pdf name: nothing says it is a PDF.
    assert.equal(sectionDocumentFileProblem({ name: 'calendar', type: '', size: 1024 }), WRONG_NAME);
});

test('a stored page document\'s address is recognised and gives its file name', () => {
    assert.equal(sectionDocumentName('https://platform.example.test/storage/412/academic-calendar-2026.pdf'), 'academic-calendar-2026.pdf');
    assert.equal(sectionDocumentName('http://localhost/storage/7/document.pdf'), 'document.pdf');
    // A person may add a page number or a cache mark; it is still that document.
    assert.equal(sectionDocumentName('https://platform.example.test/storage/412/calendar.pdf#page=3'), 'calendar.pdf');
    assert.equal(sectionDocumentName('https://platform.example.test/storage/412/calendar.pdf?v=2'), 'calendar.pdf');
    assert.equal(sectionDocumentName('  https://platform.example.test/storage/412/calendar.pdf  '), 'calendar.pdf');
});

test('two addresses of one document have one path, whatever is written in front of it or after it', () => {
    assert.equal(sectionDocumentPath('https://platform.example.test/storage/412/calendar.pdf'), '/storage/412/calendar.pdf');
    assert.equal(sectionDocumentPath('http://another-host.example.test/storage/412/calendar.pdf?v=2#page=3'), '/storage/412/calendar.pdf');
    assert.equal(sectionDocumentPath('https://example.org/files/calendar.pdf'), null);
    assert.equal(sectionDocumentPath(null), null);
});

test('any other address is not taken for a page document', () => {
    for (const value of [
        '', null, undefined, 42,
        'https://example.org/files/calendar.pdf',                         // a PDF somewhere else
        'https://platform.example.test/storage/412/photo.jpg',            // a section image
        'https://platform.example.test/storage/412/Calendar 2026.pdf',    // not a name the server makes
        'https://platform.example.test/storage/412/calendar.pdf.html',
        'https://platform.example.test/storage/lunch-flyers/calendar.pdf',
        'https://platform.example.test/storage/0412/calendar.pdf',        // a leading zero: no file is behind it, and the server takes it for nobody's
        'https://platform.example.test/storage/0/calendar.pdf',
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
    assert.equal(sectionDocumentLabel('  spaced   out  .pdf'), 'spaced out');
    assert.equal(sectionDocumentLabel('.pdf'), '');
    assert.equal(sectionDocumentLabel('___.pdf'), '');
    assert.equal(sectionDocumentLabel(undefined), '');
    assert.equal(SECTION_DOCUMENT_ICON, 'bi-file-earmark-arrow-down');
});

test('dashes and underscores in a file\'s name read as spaces in its label, and a dash between two digits is kept', () => {
    // What visitors would read on the button: a file name has dashes where words have spaces.
    assert.equal(sectionDocumentLabel('academic-calendar-2026.pdf'), 'academic calendar 2026');
    assert.equal(sectionDocumentLabel('Academic-Calendar-2026.pdf'), 'Academic Calendar 2026');
    assert.equal(sectionDocumentLabel('SCAN_0001.pdf'), 'SCAN 0001');
    assert.equal(sectionDocumentLabel('class_schedule-fall.pdf'), 'class schedule fall');
    assert.equal(sectionDocumentLabel('a - b.pdf'), 'a b');
    // Between two digits a dash is part of what is said: a school year, a date, a range of grades.
    assert.equal(sectionDocumentLabel('2026-27 School Calendar'), '2026-27 School Calendar');
    assert.equal(sectionDocumentLabel('calendar-2026-27.pdf'), 'calendar 2026-27');
    assert.equal(sectionDocumentLabel('2026-09-01-newsletter.pdf'), '2026-09-01 newsletter');
    assert.equal(sectionDocumentLabel('grades-5-6-reading.pdf'), 'grades 5-6 reading');
    // Nothing but dashes is nothing to read, so the editor's own word ("Document") is used.
    assert.equal(sectionDocumentLabel('---.pdf'), '');
    assert.equal(sectionDocumentLabel('-.pdf'), '');
});

test('the page documents a section links are found in any field, as the server finds them', () => {
    const content = {
        heading: 'Downloads',
        links: [
            { label: 'Calendar', url: 'https://platform.example.test/storage/412/calendar.pdf?v=2' },
            { label: 'Calendar again', url: 'http://another-host.example.test/storage/412/calendar.pdf' },
            { label: 'Elsewhere', url: 'https://example.org/files/report.pdf' },
            { label: 'A picture', url: 'https://platform.example.test/storage/9/photo.jpg' },
            { label: 'Mistyped', url: 'https://platform.example.test/storage/0413/schedule.pdf' },
            { label: 'Longer name', url: 'https://platform.example.test/storage/414/fees.pdf.html' },
        ],
        body: '<p>Read <a href="https://platform.example.test/storage/415/handbook.pdf">the handbook</a>.</p>',
        nothing: null,
        count: 3,
    };

    // Each once, by its path, with the address as it was FIRST written there: its host and path, and
    // nothing after the path.
    assert.deepEqual(sectionDocumentsIn(content), [
        { path: '/storage/412/calendar.pdf', name: 'calendar.pdf', address: 'https://platform.example.test/storage/412/calendar.pdf' },
        { path: '/storage/415/handbook.pdf', name: 'handbook.pdf', address: 'https://platform.example.test/storage/415/handbook.pdf' },
    ]);
    assert.deepEqual(sectionDocumentsIn(undefined), []);
    assert.deepEqual(sectionDocumentsIn({}), []);

    // Written with no host, the path is all the address there is; inside a viewer's link, the
    // address is the document's own, not the viewer's.
    assert.deepEqual(sectionDocumentsIn({ a: 'see /storage/7/fees.pdf.', b: 'https://viewer.example.test/view?url=https://platform.example.test:8443/storage/8/menu.pdf&x=1' }), [
        { path: '/storage/7/fees.pdf', name: 'fees.pdf', address: '/storage/7/fees.pdf' },
        { path: '/storage/8/menu.pdf', name: 'menu.pdf', address: 'https://platform.example.test:8443/storage/8/menu.pdf' },
    ]);
});

test('a saved document the content no longer links is leaving; one still linked in any spelling is not', () => {
    const calendar = { path: '/storage/412/calendar.pdf', name: 'calendar.pdf' };
    const handbook = { path: '/storage/415/handbook.pdf', name: 'handbook.pdf' };
    const saved = [calendar, handbook];
    const leaving = (content: unknown) => sectionDocumentsLeaving(saved, content);

    assert.deepEqual(leaving({ links: [{ url: 'https://platform.example.test/storage/412/calendar.pdf' }], body: 'See /storage/415/handbook.pdf' }), []);
    // Cleared, replaced, its row removed.
    assert.deepEqual(leaving({ links: [{ url: '' }], body: 'See /storage/415/handbook.pdf' }), [calendar]);
    assert.deepEqual(leaving({ links: [{ url: 'https://platform.example.test/storage/500/calendar-2027.pdf' }] }), [calendar, handbook]);
    assert.deepEqual(leaving({ links: [] }), [calendar, handbook]);
    // Another host, a query, or the address percent-encoded inside a viewer's link: still linked.
    assert.deepEqual(leaving({
        links: [{ url: 'http://another-host.example.test/storage/412/calendar.pdf#page=2' }],
        button_link: 'https://viewer.example.test/view?url=https%3A%2F%2Fplatform.example.test%2Fstorage%2F415%2Fhandbook.pdf&x=100%',
    }), []);
    // A section that linked nothing has nothing to lose.
    assert.deepEqual(sectionDocumentsLeaving([], { links: [] }), []);
});

test('a saved document still linked in a spelling a browser resolves to the file is not leaving, as the server keeps it', () => {
    const calendar = { path: '/storage/412/calendar.pdf', name: 'calendar.pdf' };
    const leaving = (url: string) => sectionDocumentsLeaving([calendar], { links: [{ url }] });

    // The server keeps a file that is still linked any of these ways (PageDocuments::resolved), so
    // "taken offline when you save" would be false of it.
    for (const [what, url] of Object.entries({
        'a dot segment': 'https://platform.example.test/storage/./412/calendar.pdf',
        'a dot-dot segment': 'https://platform.example.test/storage/old/../412/calendar.pdf',
        'two of them': 'https://platform.example.test/storage/a/b/../../412/calendar.pdf',
        'a doubled slash': 'https://platform.example.test/storage//412/calendar.pdf',
        'backslashes for slashes': 'https:\\\\platform.example.test\\storage\\412\\calendar.pdf',
        'JSON-escaped slashes': 'https:\\/\\/platform.example.test\\/storage\\/412\\/calendar.pdf',
        'a dot segment, percent-encoded in a viewer\'s link': 'https://viewer.example.test/view?url=https%3A%2F%2Fplatform.example.test%2Fstorage%2F.%2F412%2Fcalendar.pdf',
    })) {
        assert.deepEqual(leaving(url), [], what);
    }

    // What is still not the same document: another number reached by a dot-dot, another name, and
    // the spellings the server does not see either (ASSUMPTIONS.md PD-17).
    for (const url of [
        'https://platform.example.test/storage/412/../413/calendar.pdf',
        'https://platform.example.test/storage/412/./calendar-2027.pdf',
        'https://platform.example.test/STORAGE/412/calendar.pdf',
        'https://platform.example.test/storage/412/calendar.PDF',
        'https:&#x2F;&#x2F;platform.example.test&#x2F;storage&#x2F;412&#x2F;calendar.pdf',
        'https://viewer.example.test/view?url=https%253A%252F%252Fplatform.example.test%252Fstorage%252F412%252Fcalendar.pdf',
    ]) {
        assert.deepEqual(leaving(url), [calendar], url);
    }
});

test('the documents a form holds that the saved section does not are the ones no save has linked', () => {
    const calendar = 'https://platform.example.test/storage/412/calendar.pdf';
    const schedule = 'https://platform.example.test/storage/413/schedule.pdf';
    const saved = sectionDocumentsIn({ links: [{ url: calendar }] });
    const notSaved = (content: unknown) => sectionDocumentsNotSaved(saved, content).map((document) => document.address);

    assert.deepEqual(notSaved({ links: [{ url: calendar }] }), []);
    // The saved document under another host, or with a query after it, is still the saved one.
    assert.deepEqual(notSaved({ links: [{ url: 'http://another-host.example.test/storage/412/calendar.pdf?v=2' }] }), []);
    // One put in beside it, in a link or in a paragraph, is not.
    assert.deepEqual(notSaved({ links: [{ url: calendar }, { url: schedule }] }), [schedule]);
    assert.deepEqual(notSaved({ links: [{ url: calendar }], body: `<a href="${schedule}">Schedule</a>` }), [schedule]);
    assert.deepEqual(notSaved({ links: [] }), []);

    // A section that was never saved has saved none of them.
    assert.deepEqual(sectionDocumentsNotSaved([], { links: [{ url: calendar }, { url: schedule }] }).map((document) => document.name), ['calendar.pdf', 'schedule.pdf']);
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

    // The thirty-first upload in an hour: the limiter's own sentence, or the same words when a 429
    // comes from something in front of the application and carries none.
    // "Tried to upload": the server counts every request, so the thirty may all have been refused.
    const limit = 'You have tried to upload a lot of documents in the last hour. Wait a little, then try again.';
    assert.equal(SECTION_DOCUMENT_TOO_MANY, limit);
    assert.equal(sectionDocumentUploadProblem(httpError(429, { status: 'error', message: limit })), limit);
    assert.equal(sectionDocumentUploadProblem(httpError(429, { status: 'error', message: 'Slow down for a minute.' })), 'Slow down for a minute.');
    assert.equal(sectionDocumentUploadProblem(httpError(429, {})), limit);
    assert.equal(sectionDocumentUploadProblem(httpError(429, { message: 'Request failed.' })), limit);

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
