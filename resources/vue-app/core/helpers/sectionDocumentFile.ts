/**
 * A PDF an office attaches to a web page: what the page tool checks before it sends one, and how
 * it reads the address it gets back.
 *
 * The server decides (App\Http\Requests\Admin\Pages\StorePageDocumentRequest: a PDF by its bytes,
 * named `.pdf`, at most 25 MB). This is the same rule, told to the office BEFORE a large file is sent
 * and refused, and in words about the file. The browser only knows the file's name and the type the
 * computer gave it, so a file that passes here can still be refused by the server.
 *
 * A document is uploaded at once (components/form/SectionDocumentUpload.vue) and is then only an
 * ADDRESS, a plain string in a link field. It is never queued with a section's images, and a section's
 * content never holds a File, a `blob:` or a `data:` value for one.
 */

/** 25 MB as the server counts it: Laravel's `max:25600` is in kilobytes of 1024 bytes. */
export const SECTION_DOCUMENT_MAX_BYTES = 25600 * 1024;

export const SECTION_DOCUMENT_MIME = 'application/pdf';

/** The file picker's filter: by type, and by name for a computer that gives a PDF no type. */
export const SECTION_DOCUMENT_ACCEPT = 'application/pdf,.pdf';

/** The icon a Link Buttons button is given when it has none: the website draws it as a download mark. */
export const SECTION_DOCUMENT_ICON = 'bi-file-earmark-arrow-down';

/** The server pins the NAME too (`extensions:pdf`, any case). */
const SECTION_DOCUMENT_NAME = /\.pdf$/i;

/**
 * A stored page document's address: `…/storage/{number}/{name}.pdf` on an http(s) host, with nothing
 * after it but a query or a fragment. The name is the slug the server made (lower-case letters,
 * digits and dashes), which is what tells one of ours from a PDF somewhere else. The number has no
 * leading zero, as on the server (App\Support\PageDocuments::ADDRESS): `/storage/03/x.pdf` has no file
 * behind it and is nobody's document.
 */
const SECTION_DOCUMENT_ADDRESS = /^https?:\/\/[^/\s]+(\/storage\/[1-9]\d*\/([a-z0-9-]+\.pdf))(?:[?#]\S*)?$/;

/**
 * The same path wherever it is written inside a longer text (a paragraph, a viewer's link), which is
 * how the server looks for the documents a section links. The path is the same pattern as
 * PageDocuments::ADDRESS. The host written straight in front of it, when there is one, is taken too,
 * so the document can be named by an address a person can open; it never decides what is found (it
 * holds no `/`, so it cannot reach over one path to another).
 */
const SECTION_DOCUMENT_ANYWHERE = /(https?:\/\/[^/\s"'<>]+)?(\/storage\/[1-9]\d*\/([a-z0-9-]+\.pdf))(?![\w.-]*\w)/g;

/** The two sentences the server gives for a file that is not a PDF, word for word (StorePageDocumentRequest). */
export const SECTION_DOCUMENT_NOT_A_PDF = 'This file is not a PDF. Save or print it as a PDF, then upload that.';
export const SECTION_DOCUMENT_WRONG_NAME = 'This file\'s name does not end in .pdf. Save or print it as a PDF, then upload that.';

export function sectionDocumentFileProblem(file: { name: string; type: string; size: number }): string | null {
    // An empty type is a computer that does not know the file's kind: the name decides here and the
    // bytes decide on the server. Any OTHER type is a file the computer knows is not a PDF.
    if (file.type !== '' && file.type !== SECTION_DOCUMENT_MIME) {
        return SECTION_DOCUMENT_NOT_A_PDF;
    }
    if (!SECTION_DOCUMENT_NAME.test(file.name)) {
        return SECTION_DOCUMENT_WRONG_NAME;
    }
    if (file.size > SECTION_DOCUMENT_MAX_BYTES) {
        // One decimal place, unless that would print the limit itself: a file a few kilobytes over
        // must not be told "This file is 25.0 MB. The limit is 25 MB".
        const megabytes = (bytes: number) => (bytes / (1024 * 1024)).toFixed(1);
        const size = megabytes(file.size) === megabytes(SECTION_DOCUMENT_MAX_BYTES)
            ? 'a little over 25 MB'
            : `${megabytes(file.size)} MB`;

        return `This file is ${size}. The limit is 25 MB: export it at a lower quality or split it into parts.`;
    }
    return null;
}

/** The file name of a stored page document, when this value is the address of one; otherwise null. */
export function sectionDocumentName(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }
    const match = SECTION_DOCUMENT_ADDRESS.exec(value.trim());

    return match ? match[2] : null;
}

/**
 * The path of a stored page document (`/storage/{number}/{name}.pdf`), when this value is the address
 * of one; otherwise null. The path is what makes two addresses the same document: the host and the
 * scheme in front of it, and a query or a fragment after it, do not.
 */
export function sectionDocumentPath(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }
    const match = SECTION_DOCUMENT_ADDRESS.exec(value.trim());

    return match ? match[1] : null;
}

/**
 * A page document a section's content links: its path, its file's name for a person to read, and its
 * address as it is written there (host and path, with nothing after the path; the bare path when no
 * host was written). The PATH is what makes two of these the same document.
 */
export interface SectionDocument {
    path: string;
    name: string;
    address: string;
}

/** Every string anywhere in a section's content. */
function stringsIn(content: unknown, found: string[] = []): string[] {
    if (typeof content === 'string') {
        found.push(content);
    } else if (content && typeof content === 'object') {
        Object.values(content as Record<string, unknown>).forEach((value) => stringsIn(value, found));
    }

    return found;
}

/**
 * `%2F` read as `/`, and so on, leaving anything that is not a percent-encoding as it is (PHP's
 * rawurldecode does the same; decodeURIComponent throws on a stray `%`). Enough to find a path inside
 * an address that was encoded into a viewer's link.
 */
function percentDecoded(value: string): string {
    return value.replace(/%([0-9a-f]{2})/gi, (_all, hex: string) => String.fromCharCode(parseInt(hex, 16)));
}

/**
 * A text with the spellings a browser resolves before it asks for a file made plain: a tab or a line
 * break dropped wherever it stands, backslashes and JSON-escaped slashes read as slashes, a doubled
 * slash as one, and `.` and `..` segments resolved. The server reads a section's content this way
 * when it asks whether the section STILL LINKS a document (PageDocuments::resolved), and keeps the
 * file if so. Only ever searched for a path, never shown or stored.
 *
 * IN ONE PASS, as on the server: the text is cut at its slashes once and its segments walked once, a
 * `..` stepping back over the segment before it (any segment but one holding `?` or `#`, where a
 * browser's path has ended). The footer reads the whole content on every edit, and one step per pass
 * over the text made a link of 40,000 steps hold each keystroke for nine seconds.
 */
function resolved(value: string): string {
    const [front, ...segments] = value.replace(/[\t\r\n]/g, '').replace(/\\/g, '/').split('/');
    const path: string[] = [];

    for (const segment of segments) {
        // An empty segment is a doubled slash; `.` is where it stands.
        if (segment === '' || segment === '.') {
            continue;
        }
        if (segment === '..') {
            const last = path[path.length - 1];
            if (last === undefined) {
                // Nothing to step back over: a browser stays at the root.
                continue;
            }
            if (last !== '..' && !/[?#]/.test(last)) {
                path.pop();
                continue;
            }
        }
        path.push(segment);
    }

    return path.length === 0 ? front : `${front}/${path.join('/')}`;
}

/**
 * The page documents written anywhere in a section's content, in any field of any section type.
 *
 * This is the page tool's reading of what the server reads when a section is saved
 * (PageDocuments::forgetUnlinked): a document a SAVED section links is deleted by the save that stops
 * linking it. The page tool cannot know whose file an address is (PD-10 in ASSUMPTIONS.md); the server
 * only ever deletes the organisation's own.
 */
export function sectionDocumentsIn(content: unknown): SectionDocument[] {
    const found = new Map<string, SectionDocument>();

    for (const text of stringsIn(content)) {
        for (const [, host = '', path, name] of text.matchAll(SECTION_DOCUMENT_ANYWHERE)) {
            // Once for each document, with the address it was first written with.
            if (!found.has(path)) {
                found.set(path, { path, name, address: host + path });
            }
        }
    }

    return [...found.values()];
}

/**
 * The page documents a form holds that the SAVED section does not: uploaded, or put in by hand, since
 * the section was last saved (every one of them, for a section that has never been saved). No save
 * has linked them, so if the form is closed without saving, nothing here says where they are.
 */
export function sectionDocumentsNotSaved(saved: readonly SectionDocument[], content: unknown): SectionDocument[] {
    return sectionDocumentsIn(content).filter((document) => !saved.some((kept) => kept.path === document.path));
}

/**
 * Of the documents a section linked when it was last saved, the ones its content no longer links:
 * the files the next save takes offline (unless another saved section still links them, which only
 * the server knows). "Links" as the server reads it when it decides whether to KEEP a file: the path
 * anywhere in any text, plain or percent-encoded inside another address, and either of those in a
 * spelling a browser resolves to the same file (resolved()).
 */
export function sectionDocumentsLeaving(saved: readonly SectionDocument[], content: unknown): SectionDocument[] {
    if (saved.length === 0) {
        return [];
    }
    const texts = stringsIn(content)
        .flatMap((text) => [text, percentDecoded(text)])
        .flatMap((text) => [text, resolved(text)]);

    return saved.filter((document) => !texts.some((text) => text.includes(document.path)));
}

/**
 * A label made from a file's name, for a button that has none: `academic-calendar_2026.pdf` reads
 * `academic calendar 2026`. Underscores and dashes are what a file name has in place of spaces. A
 * single dash between two digits is kept, because there it is part of what is being said: `2026-27
 * School Calendar`, `2026-09-01 Newsletter`. Empty when the name has nothing to read.
 */
export function sectionDocumentLabel(name: unknown): string {
    if (typeof name !== 'string') {
        return '';
    }

    return name
        .replace(SECTION_DOCUMENT_NAME, '')
        .replace(/_+/g, ' ')
        .replace(/-+/g, (dashes: string, at: number, whole: string) => (
            dashes === '-' && /\d/.test(whole[at - 1] ?? '') && /\d/.test(whole[at + 1] ?? '') ? dashes : ' '
        ))
        .replace(/\s+/g, ' ')
        .trim();
}

/** What the page tool says when an upload fails and the server gave no sentence of its own. */
export const SECTION_DOCUMENT_UPLOAD_FAILED = 'The PDF could not be uploaded. Check your connection and try again.';

/**
 * The server's sentence for the thirty-first request in an hour (the `page-documents` limiter), for a
 * 429 that carries none. "Tried to upload": the server counts every request to the upload's address,
 * so someone whose files were all refused meets it too.
 */
export const SECTION_DOCUMENT_TOO_MANY = 'You have tried to upload a lot of documents in the last hour. Wait a little, then try again.';

/**
 * The sentence for a failed upload, from the failed request.
 *
 * A refusal by the server's own rule (422) is shown WORD FOR WORD: it says what is wrong with the file
 * and what to do. A file over the web server's own ceiling never reaches the application and comes
 * back as a bare 413, a switched-off module comes back with its own sentence, and so does the hourly
 * limit (429). Anything else (a dropped connection, a fault) has nothing an office can act on in it,
 * so it gets one plain sentence rather than "Request failed with status code 500".
 */
export function sectionDocumentUploadProblem(error: unknown): string {
    const response = (error as { response?: { status?: number; data?: any } } | null)?.response;
    const body = response?.data;

    if (response?.status === 413) {
        return 'This PDF is too large for the server to accept. Export it at a lower quality, or split it into parts.';
    }

    if (response?.status === 422 && body?.data && typeof body.data === 'object') {
        const first = Object.values(body.data as Record<string, unknown>)
            .flatMap((messages) => (Array.isArray(messages) ? messages : [messages]))
            .find((message) => typeof message === 'string' && message !== '');
        if (typeof first === 'string') {
            return first;
        }
    }

    // "Request failed." is what the server says in place of any sentence it will not show.
    const message = typeof body?.message === 'string' && body.message !== 'Request failed.' ? body.message : '';

    if (response?.status === 403) {
        return message !== '' ? message : 'You may not upload a PDF for this organisation.';
    }

    if (response?.status === 429) {
        return message !== '' ? message : SECTION_DOCUMENT_TOO_MANY;
    }

    return SECTION_DOCUMENT_UPLOAD_FAILED;
}
