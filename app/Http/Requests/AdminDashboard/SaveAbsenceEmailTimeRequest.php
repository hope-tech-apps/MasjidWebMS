<?php

namespace App\Http\Requests\AdminDashboard;

use App\Http\Requests\BaseFormRequest;
use App\Models\Masjid;
use App\Support\AttendanceAbsenceSettings;

/** Minimal office attendance setting, hidden even before validation when the grant is off. */
class SaveAbsenceEmailTimeRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        abort_unless(AttendanceAbsenceSettings::enabled(Masjid::find($this->route('masjid_id'))), 404);
        return true;
    }
    public function rules(): array { return ['send_time' => ['required', 'date_format:H:i']]; }
}
