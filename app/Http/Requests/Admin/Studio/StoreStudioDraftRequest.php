<?php

namespace App\Http\Requests\Admin\Studio;

use App\Http\Requests\BaseFormRequest;
use App\Models\Masjid;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/admin/studio/drafts: "New client".
 *
 * Both fields are optional. Unlike the catalogue request, an absent org_type is
 * NOT read as `masjid`: Step 0 asks for it, and a draft that silently became a
 * masjid would steer every later step for a school.
 */
class StoreStudioDraftRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'org_type' => ['nullable', 'string', Rule::in(Masjid::ORG_TYPES)],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
