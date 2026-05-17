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
        return [
            'iqama_type' => 'required|in:minutes_after_adhan,specific_time_ranges',
            'show_iqama_times' => 'nullable|boolean',
            'fajr' => 'required_if:iqama_type,minutes_after_adhan|nullable|integer|min:1',
            'dhuhr' => 'required_if:iqama_type,minutes_after_adhan|nullable|integer|min:1',
            'asr' => 'required_if:iqama_type,minutes_after_adhan|nullable|integer|min:1',
            'maghrib' => 'required_if:iqama_type,minutes_after_adhan|nullable|integer|min:1',
            'isha' => 'required_if:iqama_type,minutes_after_adhan|nullable|integer|min:1',
            'time_ranges' => 'required_if:iqama_type,specific_time_ranges|nullable|array',
            'time_ranges.*.salah' => 'required|in:fajr,dhuhr,asr,maghrib,isha',
            'time_ranges.*.start_date' => 'required|date',
            'time_ranges.*.end_date' => 'required|date|after_or_equal:time_ranges.*.start_date',
            'time_ranges.*.specific_time' => ['required', 'regex:/^([0-1]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
        ];
    }
}
