<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class IqamaTimeSettingResource extends JsonResource
{
    /** The masjid's zone, when the caller already has it (see masjidTimezone()). */
    private ?string $zone = null;

    public function inTimezone(?string $zone): static
    {
        $this->zone = $zone;

        return $this;
    }

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
        $today = Carbon::today($this->masjidTimezone());

        return [
            'fajr' => $this->getCurrentTimeForSalah('fajr', $today),
            'dhuhr' => $this->getCurrentTimeForSalah('dhuhr', $today),
            'asr' => $this->getCurrentTimeForSalah('asr', $today),
            'maghrib' => $this->getCurrentTimeForSalah('maghrib', $today),
            'isha' => $this->getCurrentTimeForSalah('isha', $today),
        ];
    }

    /**
     * The zone "today" is decided in: the masjid's own, as the fixture rule says
     * ("compared in the masjid's timezone") and as iOS, tvOS and Android already do.
     *
     * It used to be the app zone, UTC. For a masjid in New York that turns the date
     * over at 8 PM EDT (7 PM EST), so on the last day of a range the website served
     * the NEXT range's time for the whole evening, which is exactly when Isha is
     * prayed, while every app still showed the right one. An unknown or blank zone
     * keeps the old behaviour rather than failing the settings payload.
     */
    private function masjidTimezone(): string
    {
        $zone = (string) ($this->zone ?? $this->resource->masjid?->timezone ?? '');

        if ($zone !== '' && in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            return $zone;
        }

        return (string) config('app.timezone', 'UTC');
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

        // Find the time range that includes today's date. Compared as Y-m-d strings,
        // both bounds inclusive: `today` is midnight in the MASJID's zone while the
        // stored dates parse as midnight in the app zone, so comparing instants would
        // drop the last day of every range for any masjid west of UTC.
        $day = $today->format('Y-m-d');

        $currentRange = $timeRanges->first(function ($range) use ($day) {
            $startDate = Carbon::parse($range->start_date)->format('Y-m-d');
            $endDate = Carbon::parse($range->end_date)->format('Y-m-d');

            return $startDate <= $day && $day <= $endDate;
        });

        if (!$currentRange) {
            return null;
        }

        // Format time to 12-hour format without seconds (e.g., "05:30 PM")
        return Carbon::parse($currentRange->specific_time)->format('h:i A');
    }
}

