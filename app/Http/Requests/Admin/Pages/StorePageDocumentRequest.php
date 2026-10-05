<?php

namespace App\Http\Requests\Admin\Pages;

use App\Http\Requests\BaseFormRequest;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * One PDF for a web page (`document`), stored by App\Support\PageDocuments and linked from a section.
 *
 * The file lands on the PUBLIC disk, on the origin where the admin screens keep their sign-in token,
 * so what is let through is decided by three things the browser cannot fake and one it cannot choose:
 *
 *  - `mimetypes:application/pdf` reads the type from the file's BYTES. The type the browser declared
 *    for the upload is never read: PDF bytes declared as text/html are a PDF, and a web page declared
 *    as application/pdf is a web page.
 *  - `%PDF-` must be the first five bytes. The type sniffer alone is not enough: it looks for that
 *    marker further in as well, so a file that opens with a web page and carries a PDF after it is
 *    reported as application/pdf (seen with PHP 8.3's bundled sniffer). A real PDF starts with it.
 *  - `extensions:pdf` pins the NAME, in any case, as every upload rule here does
 *    (Concerns\ValidatesVideoSection). The stored name is not the client's in any event
 *    (PageDocuments::storedName), so this is the refusal a person can act on rather than the guard.
 *  - `max:25600`: 25 MB, the media library's own ceiling (config/media-library.php `max_file_size`)
 *    and the figure every section upload uses, so nothing this rule passes is refused later.
 *
 * `bail`, so a refused file is given ONE sentence, the first that applies, and each says what to do.
 */
class StorePageDocumentRequest extends BaseFormRequest
{
    /** The most a document may weigh, in megabytes; the page tool's own check uses the same figure. */
    public const MAX_MB = 25;

    /** The same in kilobytes, Laravel's unit for `max` on a file. */
    public const MAX_KB = self::MAX_MB * 1024;

    private const NOT_A_PDF = 'This file is not a PDF. Save or print it as a PDF, then upload that.';

    public function rules(): array
    {
        return [
            'document' => [
                'bail',
                'required',
                'file',
                'mimetypes:application/pdf',
                'extensions:pdf',
                'max:' . self::MAX_KB,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->startsAsAPdf($value)) {
                        $fail(self::NOT_A_PDF);
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'document.required' => 'Choose a PDF to upload.',
            'document.file' => 'Choose a PDF to upload.',
            'document.uploaded' => 'This file did not arrive. Check that it is a PDF of at most ' . self::MAX_MB . ' MB, then try again.',
            'document.mimetypes' => self::NOT_A_PDF,
            'document.extensions' => 'This file\'s name does not end in .pdf. Save or print it as a PDF, then upload that.',
            'document.max' => 'This PDF is larger than ' . self::MAX_MB . ' MB. Export it at a lower quality, or split it into parts.',
        ];
    }

    /** Whether the file's first five bytes are `%PDF-`, read from the temporary file itself. */
    private function startsAsAPdf(mixed $file): bool
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return false;
        }

        $handle = @fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            return fread($handle, 5) === '%PDF-';
        } finally {
            fclose($handle);
        }
    }
}
