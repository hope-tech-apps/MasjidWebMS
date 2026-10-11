<?php

namespace App\Http\Requests\AdminDashboard;

use App\Http\Requests\Teacher\SaveAttendanceRequest;
use App\Support\{SchoolCalendarReaders, TenantContext};

/** Identical marks and closure validation; the office's future bound uses the school clock. */
class SaveOfficeAttendanceRequest extends SaveAttendanceRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $school = app(TenantContext::class)->get();
        if ($school !== null) {
            $rules['session_date'] = ['required', 'date_format:Y-m-d', 'before_or_equal:'.SchoolCalendarReaders::for((int) $school)->today()];
        }
        return $rules;
    }
}
