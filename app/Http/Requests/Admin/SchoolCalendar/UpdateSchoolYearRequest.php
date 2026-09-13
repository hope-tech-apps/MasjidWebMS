<?php

namespace App\Http\Requests\Admin\SchoolCalendar;

/**
 * The same body and rules as creating a year; only the overlap check leaves the
 * year being edited out.
 */
class UpdateSchoolYearRequest extends StoreSchoolYearRequest
{
    protected function yearBeingEdited(): ?int
    {
        $id = $this->route('year_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
