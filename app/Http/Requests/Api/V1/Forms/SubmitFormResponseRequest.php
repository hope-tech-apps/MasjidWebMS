<?php

namespace App\Http\Requests\Api\V1\Forms;

use App\Http\Requests\BaseFormRequest;
use App\Support\FormSchema;

/**
 * The request boundary for the PUBLIC form-submission endpoint.
 *
 * It validates ONE thing: that any file riding along with the submission is a type
 * we accept and is within the size ceiling, both read from
 * `config('forms.attachments')`. Everything else — which questions exist, which
 * are required, what a select may contain — is derived from the stored schema
 * inside the controller (App\Support\FormSchema), because only the form knows it.
 *
 * Two reasons the file rules live HERE rather than in FormSchema:
 *
 *  - They must hold for every form equally. A masjid editing its own schema must
 *    not be able to widen what an anonymous caller may upload to this server.
 *  - They must run before anything else touches the upload.
 *
 * Failures come back as the legacy `{status:'failed', data:<errors>}` 422 from
 * BaseFormRequest, matching what the SPA and the Nuxt renderer already read off
 * this endpoint.
 *
 * Behaviour-neutral for every form without a `file` field: `files` is absent, the
 * `nullable` rules pass, and the request is the same request it always was.
 */
class SubmitFormResponseRequest extends BaseFormRequest
{
    /**
     * The endpoint is deliberately unauthenticated — it is the public submit URL.
     * Which form may be submitted to, and by whom, is decided in the controller
     * from the `masjid-id` header; there is no user here to authorize.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $bag = FormSchema::UPLOAD_KEY;

        $mimeTypes = (array) config('forms.attachments.mime_types', []);
        $maxKb = (int) config('forms.attachments.max_size_kb', 8192);

        $each = ['file'];

        // An empty allowlist would make `mimetypes:` match nothing and reject every
        // upload, which is the safe direction but an unhelpful one to debug — so it
        // is only applied when something is actually configured.
        if ($mimeTypes !== []) {
            $each[] = 'mimetypes:' . implode(',', $mimeTypes);
        }

        if ($maxKb > 0) {
            $each[] = 'max:' . $maxKb;
        }

        return [
            $bag => ['nullable', 'array'],
            // Keyed by field name, one level deep. A nested array (someone trying
            // files[attendees][0][photo]) fails `file` and is rejected rather than
            // silently flattened.
            $bag . '.*' => $each,
        ];
    }

    public function messages(): array
    {
        $maxKb = (int) config('forms.attachments.max_size_kb', 8192);

        return [
            FormSchema::UPLOAD_KEY . '.*.file' => 'The upload could not be read as a file.',
            FormSchema::UPLOAD_KEY . '.*.mimetypes' => 'That file type is not accepted.',
            FormSchema::UPLOAD_KEY . '.*.max' => 'That file is larger than the ' . $maxKb . 'KB limit.',
        ];
    }
}
