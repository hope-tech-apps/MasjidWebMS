<?php

namespace App\Http\Requests\Admin\Countries;

use App\Http\Requests\BaseFormRequest;

/**
 * Validates GET /api/admin/countries/{country_id}/cities[?q=…|?id=…].
 *
 * With neither parameter the endpoint answers exactly as it always has, every
 * city of the country: the onboarding wizard and the super masjid form read
 * that whole list. `q` is Studio's type-to-find (a US list is 21,008 rows, too
 * many for a select) and `id` is how Studio shows the name of a city a draft
 * already holds. Asking for both at once has no meaning, so it is refused
 * rather than one being silently ignored.
 *
 * A `q` of only whitespace is treated as absent, like an empty one, so it
 * falls back to the full list instead of 422ing on a box the operator cleared.
 */
class CountryCitiesRequest extends BaseFormRequest
{
    public const QUERY_MAX = 100;

    protected function prepareForValidation(): void
    {
        $q = $this->query('q');

        if (is_string($q)) {
            $trimmed = trim($q);
            $this->merge(['q' => $trimmed === '' ? null : $trimmed]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'min:1', 'max:' . self::QUERY_MAX, 'prohibits:id'],
            'id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
