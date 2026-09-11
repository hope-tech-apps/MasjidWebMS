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
 *
 * It also reads the card-fee checkbox, `cover_fees`, as a real boolean before the
 * `boolean` rule does (.claude/rules/shipping.md): the browser posts it form-encoded,
 * as the strings "true" and "false", and the rule refuses exactly those.
 */
class SubmitFormResponseRequest extends BaseFormRequest
{
    /** What a page that sent no usable replay key is told (here, and FormSubmissionsController). */
    public const OUT_OF_DATE = 'This page is out of date. Reload it and try again.';

    /**
     * The endpoint is deliberately unauthenticated — it is the public submit URL.
     * Which form may be submitted to, and by whom, is decided in the controller
     * from the `masjid-id` header; there is no user here to authorize.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `cover_fees` as a real boolean. Only a readable answer replaces the value, so
     * genuine nonsense is still refused by the rule rather than quietly read as "no";
     * a blank is no answer at all.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('cover_fees')) {
            return;
        }

        $value = $this->input('cover_fees');

        if (is_string($value) && trim($value) === '') {
            $this->merge(['cover_fees' => null]);

            return;
        }

        if ($value === null || is_bool($value) || is_array($value)) {
            return;
        }

        $coerced = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($coerced !== null) {
            $this->merge(['cover_fees' => $coerced]);
        }
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

            // The replay guard's key, minted once per form render by the renderer. The
            // controller requires it wherever money moves (a form that takes payment,
            // or any request carrying a staff credential), because only it knows the
            // form. A staff credential — staff_code or
            // staff_token — is deliberately NOT validated here: FormStaffCodes
            // refuses a malformed one exactly as it refuses a wrong one, and counts
            // it, so no caller can tell the two apart by the error they get.
            'client_submission_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}\z/'],

            // The card-fee checkbox (FormSubmissionsController): a yes/no, never an
            // amount; the fee is priced on the server, and only for a card payment.
            // The return address is checked there too, and only for a card payment,
            // so every other form is untouched by it (App\Support\FormPaymentReturn).
            'cover_fees' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        $maxKb = (int) config('forms.attachments.max_size_kb', 8192);

        return [
            FormSchema::UPLOAD_KEY . '.*.file' => 'The upload could not be read as a file.',
            FormSchema::UPLOAD_KEY . '.*.mimetypes' => 'That file type is not accepted.',
            FormSchema::UPLOAD_KEY . '.*.max' => 'That file is larger than the ' . $maxKb . 'KB limit.',
            'client_submission_key.string' => self::OUT_OF_DATE,
            'client_submission_key.regex' => self::OUT_OF_DATE,
            'cover_fees.boolean' => 'The card fee answer must be yes or no.',
        ];
    }
}
