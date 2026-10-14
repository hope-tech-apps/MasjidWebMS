<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;

/**
 * The write half: the same file again, plus the receipt the preview issued for it.
 *
 * ---------------------------------------------------------------------------
 * WHY THE FILE IS UPLOADED A SECOND TIME
 * ---------------------------------------------------------------------------
 *
 * The obvious design parks the previewed file somewhere — a temp table, a
 * staged upload row, a cache entry — and commits it by id. That buys nothing and
 * costs a migration, an upload lifecycle, an expiry sweep, and a new place where
 * a list of children sits half-imported with nobody's name on it. A roster file
 * is kilobytes and the browser still holds the `File` the office picked, so
 * posting it again is free and the office never re-chooses it.
 *
 * ---------------------------------------------------------------------------
 * `receipt` IS REQUIRED, AND IT IS NOT A CHECKSUM
 * ---------------------------------------------------------------------------
 *
 * The preview issues an encrypted receipt naming the digest of the bytes it
 * read, the school, the administrator who read them, and when it expires.
 * `RosterImportController::commit()` opens it and refuses anything that does not
 * match the upload in front of it. A bare client-supplied checksum would not do
 * this job: a caller can compute a digest of a file nobody has looked at, which
 * is exactly the state this screen exists to make unreachable. The receipt can
 * only have come from a preview response.
 *
 * The shape check here is deliberately loose — a non-empty string with a sane
 * ceiling. Whether the receipt is genuine, unexpired, for this school and for
 * this file is a question about the request's meaning, not its shape, and it is
 * answered in one place in the controller so the failure can say which of those
 * four things went wrong.
 */
class CommitRosterImportRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // Same size envelope as preview; the receipt supplies the confirmed mapping.
            'file' => ['required', 'file', 'max:2048'],
            'receipt' => ['required', 'string', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Upload the same roster file you previewed.',
            'file.max' => 'That file is larger than 2 MB. Upload a smaller CSV or .xlsx file.',
            'receipt.required' => 'Preview this file before importing it. Nothing is written from a file nobody has read.',
        ];
    }
}
