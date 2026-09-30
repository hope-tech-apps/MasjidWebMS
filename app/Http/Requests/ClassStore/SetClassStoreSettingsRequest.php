<?php

namespace App\Http\Requests\ClassStore;

use App\Http\Requests\BaseFormRequest;
use App\Support\ClassStoreSettings;
use Illuminate\Contracts\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A SuperAdmin setting how a school's points turn into Manara Bucks (T-003.4).
 *
 * SuperAdmin only, like the capability that switches the store on: the same person decides
 * that a school has the store, how many points make a buck, and whether the paper cash-out is
 * offered at all. The check is authorize(), not a controller line: a FormRequest validates at
 * injection, so a check in the body would show a non-super administrator (who passes the
 * route's middleware) validation errors before refusing them.
 *
 * Any subset may be sent; a field the request omits stays as it was. `bucks_from` may be null
 * ("count from the week the store is first seen on"). `paper_bucks_enabled` arrives as the
 * string "true" or "false" from the form-encoded SPA, so it is coerced here
 * (.claude/rules/shipping.md).
 */
class SetClassStoreSettingsRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->type === 'SuperAdmin';
    }

    protected function failedAuthorization(): void
    {
        throw new HttpException(Response::HTTP_FORBIDDEN, 'Only a super admin can change how a school\'s points become Manara Bucks.');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('paper_bucks_enabled')) {
            $this->merge([
                'paper_bucks_enabled' => filter_var($this->input('paper_bucks_enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'points_per_buck' => ['sometimes', 'integer', 'min:1', 'max:'.ClassStoreSettings::MAX_POINTS_PER_BUCK],
            'paper_bucks_enabled' => ['sometimes', 'boolean'],
            'bucks_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->exists('points_per_buck') && ! $this->exists('paper_bucks_enabled') && ! $this->exists('bucks_from')) {
                $validator->errors()->add('points_per_buck', 'Send points_per_buck, paper_bucks_enabled, bucks_from, or any of them.');

                return;
            }

            $from = $this->input('bucks_from');

            if (is_string($from) && \App\Support\SchoolCalendar::day($from) === null) {
                $validator->errors()->add('bucks_from', 'That is not a real date.');
            }
        }];
    }
}
