<?php

namespace App\Http\Requests\Admin\Forms;

use App\Models\Form;
use Illuminate\Validation\Rule;

/**
 * Same shape as StoreFormRequest, with every field optional (`sometimes`) so the
 * builder can PATCH just the schema, or just the window, without resending everything.
 *
 * Extends the store request so the schema structure rule, the identity/fee cross-checks
 * and the JSON-string coercion stay in exactly one place — a form that is valid to
 * create must remain valid to edit.
 */
class UpdateFormRequest extends StoreFormRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        // Ignore this form's own row when checking slug uniqueness.
        $rules['slug'] = [
            'sometimes',
            'required',
            'string',
            'max:255',
            'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            Rule::unique('forms', 'slug')
                ->where('masjid_id', (int) $this->route('masjid_id'))
                ->whereNull('deleted_at')
                ->ignore((int) $this->route('form_id')),
        ];

        foreach (['name', 'schema'] as $key) {
            if (isset($rules[$key]) && is_array($rules[$key])) {
                array_unshift($rules[$key], 'sometimes');
            } elseif (isset($rules[$key])) {
                $rules[$key] = 'sometimes|' . $rules[$key];
            }
        }

        return $rules;
    }

    /**
     * THE CROSS-CHECK IS ABOUT THE FORM THAT WILL EXIST AFTER THIS WRITE, not
     * about the half of it the payload happens to carry.
     *
     * This used to read: "The cross-field checks in the parent read `schema` off
     * the request. On a partial update that omits `schema`, there is nothing to
     * check against — skip rather than reject, since the stored schema is already
     * known-valid." Every clause of that is true and the conclusion is wrong: the
     * stored schema being valid says nothing about whether the INCOMING
     * `settings` agree with it. Measured on this branch, on a live camp form
     * charging $100 per attendee with two attendees on the response:
     *
     *     before  FormSchema::amountDue() = 200.0
     *     PUT  {"settings":{"fee":{"amount":140,"perEntryOfSection":"attendee",…}}}
     *          -> 200 OK, no error anywhere
     *     after   FormSchema::amountDue() = 0.0
     *
     * "attendee" instead of "attendees". The same document is REFUSED by
     * `POST /forms` and by `form:import`, both of which run the cross-check; this
     * door skipped it, so the one word that empties a camp's fee got in through
     * the door an administrator uses most. `amountDue()` is stored on each
     * response at submission time, so the zero is not a rendering artefact that
     * the next save repairs — it is what the family agreed to pay.
     *
     * The mirror image was open too, and closes here with it: a PUT carrying only
     * a new `schema` was accepted even when it DELETED the repeatable section the
     * stored fee is charged per entry of, or the question the stored identity map
     * names (measured: 200, both times, leaving `perEntryOfSection: "attendees"`
     * pointing at nothing).
     *
     * So both halves are resolved — payload where present, stored row otherwise —
     * and the pair is checked. A partial write that touches neither is unaffected;
     * a form the two doors would accept is a form this door accepts.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $schema = $this->input('schema');
            $settings = $this->input('settings');

            // Nothing this write touches can change the answer.
            if (! is_array($schema) && ! is_array($settings)) {
                return;
            }

            // A schema the structure rule has already refused tells us nothing.
            if (is_array($schema) && $validator->errors()->has('schema')) {
                return;
            }

            $stored = Form::query()
                ->where('masjid_id', (int) $this->route('masjid_id'))
                ->whereKey((int) $this->route('form_id'))
                ->first();

            // A missing / foreign id is the controller's 404 to give, not a
            // validation error to invent.
            if (! $stored && (! is_array($schema) || ! is_array($settings))) {
                return;
            }

            $effectiveSchema = is_array($schema) ? $schema : (array) $stored->schema;
            $effectiveSettings = is_array($settings) ? $settings : (array) $stored->settings;

            foreach (self::crossCheck($effectiveSchema, $effectiveSettings) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }
}
