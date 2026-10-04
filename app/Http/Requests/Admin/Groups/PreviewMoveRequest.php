<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;

/**
 * Ask what moving a student to another class would do (the read before the
 * tap).
 *
 * Only the SHAPE is checked here, as in RecordStudentWithdrawalRequest beside
 * it: a missing class or a malformed date is the legacy validation envelope.
 * Whether the class exists, is this school's and can take the student is
 * settled by App\Support\RosterMove against the tenant-scoped models, and is
 * answered as `can_move: false` with the sentence to read.
 */
class PreviewMoveRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'to_group_id' => 'required|integer',
            // Absent means today on the SCHOOL's clock. "Not in the future" is
            // judged against that clock too, so it is not a rule here.
            'moved_on' => 'nullable|date_format:Y-m-d',
        ];
    }
}
