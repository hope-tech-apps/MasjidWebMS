<?php

namespace App\Http\Requests\Family;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Validation\Rule;

/**
 * What a parent may ask to have translated in one request.
 *
 * Every rule here is a SPENDING CEILING as well as a validation rule, and that
 * is the unusual thing about this request. Elsewhere in the family realm a
 * `max:` protects a column; here it protects a bill. The endpoint takes text
 * rather than record ids on purpose (see TranslationsController), which removes
 * the audience question entirely — nothing is disclosed that the caller did not
 * already have — and leaves cost as the only thing an authenticated parent can
 * abuse. So the limits are the control, they all live in config/translation.php
 * where an operator can tighten them during an incident, and none of them is a
 * literal in this file.
 *
 * The three ceilings are not redundant. `max_items` bounds how many strings;
 * `max_chars_per_item` bounds how long one may be; and the TOTAL rule bounds
 * their product, which is the number that actually reaches the model — twenty
 * items of six thousand characters each is 120,000 characters from one tap, and
 * the two per-item rules alone would allow it.
 *
 * `distinct` on the keys is the fourth, and it guards a different failure. The
 * response is a map keyed by what the caller sent, so two items sharing a key
 * would collapse into one entry and the client would render one paragraph in
 * Arabic and leave the other in English with no error anywhere — the
 * silent-partial-success shape this feature spends most of its design avoiding.
 * A 422 says it out loud.
 */
class TranslateContentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // Not a free string. An unlisted tag would become part of the prompt
            // ("translate this into <whatever the caller sent>"), which is the
            // caller writing instructions for the model.
            'target' => ['required', 'string', Rule::in((array) config('translation.languages', ['ar']))],

            'items' => [
                'required',
                'array',
                'min:1',
                'max:' . (int) config('translation.max_items', 20),
            ],

            // The caller's own handle for the paragraph — a record slug, a DOM
            // id. Never interpreted, only echoed back, which is what lets this
            // endpoint stay ignorant of posts, threads and children.
            'items.*.key' => ['required', 'string', 'max:120', 'distinct'],

            'items.*.text' => [
                'required',
                'string',
                'max:' . (int) config('translation.max_chars_per_item', 6000),
            ],
        ];
    }

    /**
     * The total-characters ceiling.
     *
     * Attached to `items` rather than to a wildcard because it is a property of
     * the request as a whole. Counted with mb_strlen so an Arabic or mixed-script
     * source is measured in characters rather than bytes — the same unit the
     * per-item `max:` rule uses, so the two ceilings cannot disagree about what
     * "6000 characters" means.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $items = $this->input('items');

            // Shape errors are already reported by the rules above; re-reporting
            // them from here would put two messages on one field.
            if (! is_array($items)) {
                return;
            }

            $total = 0;

            foreach ($items as $item) {
                if (is_array($item) && is_string($item['text'] ?? null)) {
                    $total += mb_strlen($item['text']);
                }
            }

            $max = (int) config('translation.max_chars_per_request', 20000);

            if ($total > $max) {
                $validator->errors()->add(
                    'items',
                    "That is too much text to translate at once (limit {$max} characters). "
                    . 'Translate one section at a time.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'target.in' => 'That language is not available for translation.',
            'items.max' => 'That is too many items to translate at once.',
            'items.*.key.distinct' => 'Each item needs its own key.',
            'items.*.text.max' => 'One of those passages is too long to translate in one go.',
        ];
    }
}
