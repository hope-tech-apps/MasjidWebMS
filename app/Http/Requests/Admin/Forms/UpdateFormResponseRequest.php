<?php

namespace App\Http\Requests\Admin\Forms;

use App\Http\Requests\BaseFormRequest;
use App\Models\FormResponse;
use Illuminate\Validation\Rule;

/**
 * An admin may triage a response — move it through the workflow and annotate it.
 *
 * The submitted `data` is deliberately NOT editable. A response is a record of what
 * somebody actually agreed to (including waivers and medical authorisations), and
 * letting staff rewrite it would destroy its value as evidence. Corrections belong in
 * admin_notes.
 */
class UpdateFormResponseRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', Rule::in(FormResponse::STATUSES)],
            'admin_notes' => 'sometimes|nullable|string|max:5000',
        ];
    }
}
