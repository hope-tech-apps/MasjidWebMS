<?php

namespace App\Http\Requests\Admin\Studio;

use App\Http\Requests\BaseFormRequest;
use App\Models\Masjid;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/admin/studio/organisations/{id}/website-locale (Studio W2 S12):
 * `locale` is `en`, `ar`, or empty to clear the choice (the empty string
 * reaches the rules as null through ConvertEmptyStringsToNull).
 */
class SetWebsiteLocaleRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'locale' => ['present', 'nullable', 'string', Rule::in(Masjid::WEBSITE_LOCALES)],
        ];
    }
}
