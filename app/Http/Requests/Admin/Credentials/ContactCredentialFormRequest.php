<?php

namespace App\Http\Requests\Admin\Credentials;

use App\Http\Requests\BaseFormRequest;
use App\Models\ContactCredential;

/**
 * Shared boundary rules for the credential write requests.
 *
 * Extends BaseFormRequest so a rejection leaves as the legacy {status:'failed'}
 * 422 instead of a raw ValidationException, which this app's JSON renderer
 * would turn into a 500.
 *
 * masjid_id and contact_id are NOT accepted by either subclass and never will
 * be — the BelongsToMasjid creating hook stamps the tenant, and the contact
 * comes from the route's scoped findOrFail. A payload cannot re-parent a
 * credential.
 */
abstract class ContactCredentialFormRequest extends BaseFormRequest
{
    /**
     * The optional scanned document. Allowlist and ceiling are config-driven
     * (config/credentials.php) and matched with `mimetypes:` against the type
     * SNIFFED from the bytes — never `mimes:` on a client-supplied extension
     * and never the Content-Type header. Per .claude/rules/private-uploads.md,
     * validation belongs at this boundary and nothing tenant-editable may
     * widen it.
     *
     * @return array<int,string>
     */
    protected function documentRules(): array
    {
        return [
            'sometimes',
            'nullable',
            'file',
            'mimetypes:' . implode(',', (array) config('credentials.document.mime_types', [])),
            'max:' . (int) config('credentials.document.max_size_kb', 8192),
        ];
    }

    public function messages(): array
    {
        return [
            'kind.in' => 'That is not a recognized credential kind. Use "' . ContactCredential::KIND_OTHER . '" with a label for anything unlisted.',
            'label.required_if' => 'A label naming the credential is required when the kind is "' . ContactCredential::KIND_OTHER . '".',
            'document.mimetypes' => 'The document must be a PDF or an image (JPEG/PNG scan).',
            'document.max' => 'The document exceeds the allowed size.',
        ];
    }
}
