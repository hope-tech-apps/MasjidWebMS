<?php

namespace App\Http\Requests\Admin\Forms;

use App\Http\Requests\BaseFormRequest;

/**
 * Issuing one staff member's cash code on a form
 * (App\Http\Controllers\AdminDashboard\FormStaffCodesController::store()).
 *
 * holder_name is capped here as well as in the column: SQLite, which the suite runs on,
 * does not enforce VARCHAR(120), so only this rule stops the 121st character before
 * MySQL in strict mode refuses it at write time.
 *
 * expires_at is optional: a calendar day ("2026-10-17", good to the end of that day) or
 * a date and time. Left out, the form's event day decides (settings.payment.eventDate),
 * and a form that names none issues no code: the day is never guessed. The controller
 * reads it on the masjid's clock and refuses one that has already passed, because only
 * it knows the masjid's timezone. Nothing else is
 * taken from the body: the digest, the hint, the counters and the device binding are
 * the server's (FormStaffCode::$fillable).
 */
class StoreFormStaffCodeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'holder_name' => 'required|string|max:120',
            'expires_at' => 'nullable|date',
        ];
    }
}
