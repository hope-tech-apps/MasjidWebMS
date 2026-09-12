<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;

/**
 * Record that a student has LEFT a class.
 *
 * Only the SHAPE is checked here. Whether the membership exists, and whether it
 * is a student row rather than a guardian edge, is settled in
 * GroupWithdrawalController against the tenant-scoped model — so another
 * organization's membership id is a 404 miss rather than a validation message
 * confirming the row exists somewhere. Same arrangement as
 * RecordGuardianConsentRequest beside it.
 */
class RecordStudentWithdrawalRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // The day they actually left, if the office is recording it after
            // the fact — the common case, since a family tells the school and
            // the roster is updated the following week. Absent means today.
            // Never in the future: a child who has not left yet is still in the
            // class, and a register taken tomorrow must still include them.
            'left_on' => 'nullable|date|before_or_equal:now',
        ];
    }

    public function messages(): array
    {
        return [
            'left_on.before_or_equal' => 'A leaving date cannot be in the future — until that day, the child is still in the class.',
        ];
    }
}
