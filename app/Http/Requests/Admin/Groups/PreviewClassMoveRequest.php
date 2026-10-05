<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;

/**
 * Ask what moving a whole class to another class would do (the read before
 * the tap).
 *
 * Only the SHAPE is checked here, as in PreviewMoveRequest beside it. Whether
 * the class exists, is this school's and can take the students is settled by
 * App\Support\RosterClassMove against the tenant-scoped models, and answered
 * as `can_move: false` with the sentence to read.
 */
class PreviewClassMoveRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'to_group_id' => 'required|integer',
            // Absent means today on the SCHOOL's clock. "Not in the future" is
            // judged against that clock too, so it is not a rule here.
            'moved_on' => 'nullable|date_format:Y-m-d',
            // What happens to grades: keep each student's, give everyone one
            // label, or move each up one. Absent while the office has not
            // chosen; the preview then shows what `keep` would give.
            'grade_mode' => 'nullable|in:keep,set,up',
            'grade_label' => 'nullable|string|max:32',
        ];
    }
}
