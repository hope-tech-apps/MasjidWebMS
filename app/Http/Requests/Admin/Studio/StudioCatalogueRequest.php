<?php

namespace App\Http\Requests\Admin\Studio;

use App\Http\Requests\BaseFormRequest;
use App\Models\Masjid;
use Illuminate\Validation\Rule;

/**
 * Validates GET /api/admin/studio/catalogue?org_type=…
 *
 * An absent or empty org_type means `masjid`, the same normalisation
 * ProvisionMasjidRequest applies, so the catalogue Studio shows and the vertical
 * it provisions can never disagree about a blank select.
 */
class StudioCatalogueRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('org_type')) {
            $this->merge(['org_type' => Masjid::ORG_TYPE_MASJID]);
        }
    }

    public function rules(): array
    {
        return [
            'org_type' => ['required', 'string', Rule::in(Masjid::ORG_TYPES)],
        ];
    }
}
