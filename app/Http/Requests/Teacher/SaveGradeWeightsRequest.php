<?php

namespace App\Http\Requests\Teacher;

use App\Http\Requests\BaseFormRequest;
use App\Models\ClassAssignment;
use App\Models\ClassGradeWeight;
use Illuminate\Contracts\Validation\Validator;

/**
 * Set a class's weight for every type of work, or clear them (T-001.2).
 *
 * `weights` is `{type: 0-100}` for EXACTLY the five ClassAssignment::TYPES, or the
 * request is `{clear: true}`. Nothing in between: a class with a weight for tests
 * and none for homework has no answer to "how much does homework count?", and the
 * only ways to answer it are to refuse an average or to invent a number.
 *
 * At least one type must count for something (an all-zero set divides by zero and
 * would say every average is blank).
 *
 * `clear` arrives as the string "true" or "1" from the form-encoded SPA, so it is
 * coerced here, server-side, where a cached bundle cannot get round it
 * (.claude/rules/shipping.md).
 */
class SaveGradeWeightsRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('clear')) {
            $this->merge([
                'clear' => filter_var($this->input('clear'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        $clearing = $this->input('clear') === true;

        return [
            'clear' => ['sometimes', 'boolean'],
            'weights' => $clearing ? ['prohibited'] : ['required', 'array'],
            'weights.*' => ['required', 'integer', 'min:0', 'max:' . ClassGradeWeight::MAX],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('clear') === true || $validator->errors()->isNotEmpty()) {
                return;
            }

            $sent = array_keys((array) $this->input('weights'));
            $types = ClassAssignment::TYPES;

            if (array_diff($types, $sent) !== [] || array_diff($sent, $types) !== []) {
                $validator->errors()->add('weights', 'Set a weight for every type of work ('
                    . implode(', ', array_map(fn ($t) => ClassAssignment::TYPE_LABELS[$t], $types))
                    . '), or clear them all.');

                return;
            }

            if (array_sum(array_map('intval', (array) $this->input('weights'))) <= 0) {
                $validator->errors()->add('weights', 'At least one type of work has to count for something.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'weights.*.max' => 'A weight is between 0 and ' . ClassGradeWeight::MAX . '.',
            'weights.*.integer' => 'A weight is a whole number.',
        ];
    }
}
