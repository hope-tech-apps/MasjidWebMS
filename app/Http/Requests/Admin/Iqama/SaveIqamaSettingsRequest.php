<?php

namespace App\Http\Requests\Admin\Iqama;

use App\Http\Requests\BaseFormRequest;

class SaveIqamaSettingsRequest extends BaseFormRequest
{
    /**
     * Normalize show_iqama_times to a real boolean before validation so the
     * controller doesn't have to do this filter dance anymore.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('show_iqama_times')) {
            $this->merge([
                'show_iqama_times' => filter_var($this->show_iqama_times, FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    public function rules(): array
    {
        // On Specific Time Ranges an offset is only the fallback for days no range
        // covers, and 0 there is a real, stored setting ("iqama at the adhan"):
        // Burlington and NAFIS Apex both store 0 for Fajr/Dhuhr/Asr. Now that the admin
        // screen sends the offsets in that mode too, min:1 would refuse their own saved
        // values. Minutes After Adhan keeps its existing rule.
        $offset = $this->input('iqama_type') === 'specific_time_ranges'
            ? 'nullable|integer|min:0'
            : 'required_if:iqama_type,minutes_after_adhan|nullable|integer|min:1';

        return [
            'iqama_type' => 'required|in:minutes_after_adhan,specific_time_ranges',
            'show_iqama_times' => 'nullable|boolean',
            'fajr' => $offset,
            'dhuhr' => $offset,
            'asr' => $offset,
            'maghrib' => $offset,
            'isha' => $offset,
            'time_ranges' => 'required_if:iqama_type,specific_time_ranges|nullable|array',
            'time_ranges.*.salah' => 'required|in:fajr,dhuhr,asr,maghrib,isha',
            'time_ranges.*.start_date' => 'required|date',
            'time_ranges.*.end_date' => 'required|date|after_or_equal:time_ranges.*.start_date',
            'time_ranges.*.specific_time' => ['required', 'regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
        ];
    }
}
