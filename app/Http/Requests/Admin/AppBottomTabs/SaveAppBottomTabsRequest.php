<?php

namespace App\Http\Requests\Admin\AppBottomTabs;

use App\Http\Requests\BaseFormRequest;

/** Ordered catalogue IDs only; availability is validated under the organisation mutex. */
class SaveAppBottomTabsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return ['feature_ids' => ['present', 'array', 'max:4', 'list'], 'feature_ids.*' => ['required', 'integer', 'min:1', 'distinct']];
    }
}
