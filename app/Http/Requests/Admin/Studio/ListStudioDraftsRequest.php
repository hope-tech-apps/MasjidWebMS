<?php

namespace App\Http\Requests\Admin\Studio;

use App\Http\Requests\BaseFormRequest;
use App\Models\StudioDraft;
use Illuminate\Validation\Rule;

/**
 * Validates GET /api/admin/studio/drafts?status=draft|provisioned|all.
 *
 * Absent means `draft`: the list's job is resuming work, and a provisioned
 * draft is history the SPA asks for by name.
 */
class ListStudioDraftsRequest extends BaseFormRequest
{
    public const ALL = 'all';

    protected function prepareForValidation(): void
    {
        if (! $this->filled('status')) {
            $this->merge(['status' => StudioDraft::STATUS_DRAFT]);
        }
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([...StudioDraft::STATUSES, self::ALL])],
        ];
    }
}
