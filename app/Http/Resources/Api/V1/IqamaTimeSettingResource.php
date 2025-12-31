<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class IqamaTimeSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->iqama_type,
            'show_iqama_times' => $this->show_iqama_times,
            'minutes_after_adhan' => $this->getMinutesAfterAdhan(),
            'specific_time_ranges' => $this->getSpecificTimeRanges(),
        ];
    }

    /**
     * Get minutes after adhan for each salah
     *
     * @return array
     */
    private function getMinutesAfterAdhan(): array
    {
        return [
            'fajr' => $this->fajr,
            'dhuhr' => $this->dhuhr,
            'asr' => $this->asr,
            'maghrib' => $this->maghrib,
            'isha' => $this->isha,
        ];
    }

    /**
     * Get specific time ranges for each salah based on current date
     *
     * @return array
     */
    private function getSpecificTimeRanges(): array
    {
        $today = Carbon::today();

        return [
            'fajr' => $this->getCurrentTimeForSalah('fajr', $today),
            'dhuhr' => $this->getCurrentTimeForSalah('dhuhr', $today),
            'asr' => $this->getCurrentTimeForSalah('asr', $today),
            'maghrib' => $this->getCurrentTimeForSalah('maghrib', $today),
            'isha' => $this->getCurrentTimeForSalah('isha', $today),
        ];
    }

    /**
     * Get the current iqama time for a specific salah based on today's date
     *
     * @param string $salah
     * @param Carbon $today
     * @return string|null
     */
    private function getCurrentTimeForSalah(string $salah, Carbon $today): ?string
    {
        // Get all time ranges for this salah
        $timeRanges = $this->timeRanges->where('salah', $salah);

        // Find the time range that includes today's date
        $currentRange = $timeRanges->first(function ($range) use ($today) {
            $startDate = Carbon::parse($range->start_date);
            $endDate = Carbon::parse($range->end_date);

            return $today->between($startDate, $endDate);
        });

        if (!$currentRange) {
            return null;
        }

        // Format time to 12-hour format without seconds (e.g., "05:30 PM")
        return Carbon::parse($currentRange->specific_time)->format('h:i A');
    }
}

