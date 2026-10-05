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
 * digits and dashes), which is what tells one of ours from a PDF somewhere else.
 */
const SECTION_DOCUMENT_ADDRESS = /^https?:\/\/[^/\s]+\/storage\/\d+\/([a-z0-9-]+\.pdf)(?:[?#]\S*)?$/;

export function sectionDocumentFileProblem(file: { name: string; type: string; size: number }): string | null {
    // An empty type is a computer that does not know the file's kind: the name decides here and the
    // bytes decide on the server. Any OTHER type is a file the computer knows is not a PDF.
    const wrongType = file.type !== '' && file.type !== SECTION_DOCUMENT_MIME;
    if (wrongType || !SECTION_DOCUMENT_NAME.test(file.name)) {
        return 'Choose a PDF file (a name ending in .pdf).';
    }
    if (file.size > SECTION_DOCUMENT_MAX_BYTES) {
        const mb = (file.size / (1024 * 1024)).toFixed(1);
        return `This file is ${mb} MB. The limit is 25 MB: export it at a lower quality or split it into parts.`;
    }
    return null;
}

/** The file name of a stored page document, when this value is the address of one; otherwise null. */
export function sectionDocumentName(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }
    const match = SECTION_DOCUMENT_ADDRESS.exec(value.trim());

    return match ? match[1] : null;
}

/**
 * A label made from a file's name, for a button that has none: `Academic_Calendar 2026.pdf` reads
 * `Academic Calendar 2026`. Empty when the name has nothing to read.
 */
export function sectionDocumentLabel(name: unknown): string {
    if (typeof name !== 'string') {
        return '';
    }

    return name.replace(SECTION_DOCUMENT_NAME, '').replace(/_+/g, ' ').replace(/\s+/g, ' ').trim();
}

/** What the page tool says when an upload fails and the server gave no sentence of its own. */
export const SECTION_DOCUMENT_UPLOAD_FAILED = 'The PDF could not be uploaded. Check your connection and try again.';

/**
 * The sentence for a failed upload, from the failed request.
 *
 * A refusal by the server's own rule (422) is shown WORD FOR WORD: it says what is wrong with the file
 * and what to do. A file over the web server's own ceiling never reaches the application and comes
 * back as a bare 413, and a switched-off module comes back with its own sentence. Anything else (a
 * dropped connection, a fault) has nothing an office can act on in it, so it gets one plain sentence
 * rather than "Request failed with status code 500".
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

    if (response?.status === 403) {
        const message = typeof body?.message === 'string' ? body.message : '';

        // "Request failed." is what the server says in place of any sentence it will not show.
        return message !== '' && message !== 'Request failed.'
            ? message
            : 'You may not upload a PDF for this organisation.';
    }

    return SECTION_DOCUMENT_UPLOAD_FAILED;
}
