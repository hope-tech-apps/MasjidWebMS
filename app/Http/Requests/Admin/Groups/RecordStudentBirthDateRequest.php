<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;
use App\Support\SchoolCalendar;
use App\Support\TenantContext;

/**
 * Record a student's date of birth.
 *
 * Only the SHAPE is checked here. Whether the membership exists, and whether it
 * is a student in a class at all, is settled in GroupBirthDateController against
 * the tenant-scoped model, so another organization's membership id is a 404 miss
 * rather than a validation message confirming the row exists somewhere. Same
 * arrangement as RecordStudentWithdrawalRequest beside it.
 *
 * Pinned to Y-m-d (not the looser `date`) so the value that gets ENCRYPTED is
 * canonical: ciphertext cannot be normalised later without decrypting every row.
 * The same rule as an appointment request's date of birth, with one difference.
 *
 * "NOT IN THE FUTURE" IS JUDGED ON THE SCHOOL'S CLOCK, not the server's. Laravel
 * reads `today` in the application's time zone (UTC), so after 8 pm Eastern it
 * would accept tomorrow's local date; the age is worked out on the school's
 * today (App\Support\StudentAge), reads that as a future date, and the office
 * would save a date and see no age with no reason given.
 */
class RecordStudentBirthDateRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'date_of_birth' => 'required|date_format:Y-m-d|before_or_equal:'.$this->schoolToday().'|after:1900-01-01',
        ];
    }

    public function messages(): array
    {
        return [
            'date_of_birth.required' => 'Enter the date of birth, or use Remove to clear it.',
            'date_of_birth.date_format' => 'The date of birth must be a real day in YYYY-MM-DD format.',
            'date_of_birth.before_or_equal' => 'The date of birth cannot be in the future.',
            'date_of_birth.after' => 'The date of birth cannot be before 1900.',
        ];
    }

    /** Today as 'Y-m-d' where the school is. Server-derived tenant first, as OfferingFormRequest does. */
    private function schoolToday(): string
    {
        $masjidId = (int) (app(TenantContext::class)->get() ?? $this->route('masjid_id'));

        return SchoolCalendar::for($masjidId)->today();
    }
}
