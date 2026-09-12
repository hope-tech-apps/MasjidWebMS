<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;

/**
 * The upload half of "show me what this roster file would do".
 *
 * Only the ENVELOPE is checked here — that something was uploaded, that it is
 * small, that it is plausibly a text file. What a valid roster IS remains
 * `App\Services\Schools\RosterImportService`'s answer, because it is also the
 * console command's answer, and a second opinion in a FormRequest is how the
 * two paths start refusing different files.
 *
 * ---------------------------------------------------------------------------
 * `mimes:`, NOT `mimetypes:` — and this is the one upload where copying
 * StoreGroupResourceRequest would be wrong
 * ---------------------------------------------------------------------------
 *
 * `mimetypes:` matches the SNIFFED type, which is the right rule for a document
 * library: the bytes decide, and a renamed executable is refused. A roster CSV
 * has no distinguishing bytes. The same spreadsheet exported from Excel arrives
 * sniffed as `text/plain`, `text/csv` or `application/vnd.ms-excel` depending on
 * the machine and the locale, so a sniffed allowlist would reject the exact
 * files this feature exists to accept, intermittently, with an error the office
 * cannot act on.
 *
 * Nothing is lost by that, because the protections here were never the MIME
 * type. The file is parsed by our own reader, which requires the eight headers
 * exactly and strips a leading apostrophe from every cell; a file that is not a
 * roster fails on its headers, and one that is a roster carrying a formula is
 * defused. The size ceiling is the real limit on what an upload can cost.
 *
 * 2 MB is roughly twenty thousand roster rows — far past any real school year,
 * and small enough that the whole file is read twice (once to digest it, once to
 * parse it) without thinking about memory.
 */
class PreviewRosterImportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose the roster CSV you want to preview.',
            'file.mimes' => 'That does not look like a CSV. Save the spreadsheet as CSV and upload that file.',
            'file.max' => 'That file is larger than 2 MB. A roster CSV should be a few hundred kilobytes at most.',
        ];
    }
}
