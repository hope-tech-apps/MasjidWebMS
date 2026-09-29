<?php

namespace App\Http\Requests\ClassStore;

use App\Http\Requests\BaseFormRequest;
use App\Models\Prize;
use Illuminate\Contracts\Validation\Validator;

/**
 * Create or edit one prize on a class store's shelf (T-003.4).
 *
 * ONE request for both realms and both verbs, because the rules are the same and only WHOSE
 * shelf differs: a teacher's route carries `{group_id}` (their class's own prize) and the
 * office's does not (a school-wide prize). The route decides the shelf; nothing in the
 * body can, so a teacher cannot write a school-wide prize by sending `group_id`, and the
 * office cannot write into a class.
 *
 *  - `title` is unique within its own shelf, compared without case or edge spaces, so
 *    "Pencil" and "pencil " are one prize (checked here, in PHP, because a NULL `group_id`
 *    is distinct from every other NULL in a unique index on both engines);
 *  - `cost_bucks` is a whole number of at least one, under Prize::MAX_COST;
 *  - `stock` is the number left on the shelf, and BLANK MEANS UNLIMITED (R6): "" and null both
 *    clear it. On a form-encoded body an empty field arrives as the empty string, which the
 *    framework's empty-string-to-null pass already reads as null;
 *  - `is_active` arrives as the string "true" or "false" from the form-encoded SPA, so it is
 *    coerced here, server-side, where a cached bundle cannot get round it
 *    (.claude/rules/shipping.md).
 *
 * On an update every field is optional, so a retire is `{is_active: false}` alone.
 */
class SavePrizeRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $this->merge([
                'is_active' => filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    private function updating(): bool
    {
        return $this->route('prize_id') !== null;
    }

    public function rules(): array
    {
        $required = $this->updating() ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'min:1', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'cost_bucks' => [$required, 'integer', 'min:1', 'max:'.Prize::MAX_COST],
            'stock' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.Prize::MAX_STOCK],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('title')) {
                return;
            }

            $groupId = $this->route('group_id');
            $mine = mb_strtolower(trim((string) $this->input('title')));

            $taken = Prize::query()
                ->when($groupId === null, fn ($q) => $q->whereNull('group_id'), fn ($q) => $q->where('group_id', (int) $groupId))
                ->when($this->updating(), fn ($q) => $q->where('id', '!=', (int) $this->route('prize_id')))
                ->pluck('title')
                ->contains(fn ($title) => mb_strtolower(trim((string) $title)) === $mine);

            if ($taken) {
                $validator->errors()->add('title', 'There is already a prize with that title on this list.');
            }
        }];
    }
}
