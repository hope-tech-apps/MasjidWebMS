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
 *    (.claude/rules/shipping.md). An ABSENT, null or empty `is_active` means NO CHANGE: it
 *    used to go through filter_var, which reads null and '' as false, so a client that sent
 *    the field empty silently retired the prize;
 *  - on an update, a `stock` must come with `expected_stock`, the number the editor LOADED
 *    (null for unlimited). The save compares it with the row under a lock and answers 409
 *    `stock_changed` when a redemption has moved it since, instead of writing a stale count
 *    over the prizes that were given meanwhile (ClassStore::updatePrize).
 *
 * On an update every field is optional, so a retire is `{is_active: false}` alone.
 */
class SavePrizeRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $raw = $this->input('is_active');

            if ($raw === null || $raw === '') {
                // No value is no change (never "false": filter_var(null) is false).
                $this->offsetUnset('is_active');
            } else {
                $this->merge([
                    'is_active' => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                ]);
            }
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
            // On an update only: required alongside `stock`, and compared under the row lock.
            'expected_stock' => $this->updating()
                ? ['present_with:stock', 'nullable', 'integer', 'min:0', 'max:'.Prize::MAX_STOCK]
                : ['exclude'],
        ];
    }

    /**
     * What this request changes, field by field, in the types the row stores: only the fields
     * the body carries, and `is_active` only when it carried a value (see above).
     *
     * @return array<string,mixed>
     */
    public function changes(): array
    {
        $out = [];

        foreach (['title', 'description', 'cost_bucks', 'stock', 'is_active'] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $out[$field] = match ($field) {
                'cost_bucks' => $this->integer('cost_bucks'),
                'stock' => $this->filled('stock') ? $this->integer('stock') : null,
                'is_active' => (bool) $this->boolean('is_active'),
                default => $this->input($field),
            };
        }

        return $out;
    }

    /** The stock the editor loaded (null = unlimited), for the compare under the lock. */
    public function expectedStock(): ?int
    {
        return $this->filled('expected_stock') ? $this->integer('expected_stock') : null;
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
