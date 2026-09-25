<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use App\Support\IqamaResolver;
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
     * Today's fixed iqama time for each salah, or null where the offset applies.
     *
     * Resolved by App\Support\IqamaResolver, the same rule the backstop push and
     * the stored prayer rows use, so the website cannot say one time while a
     * phone is pushed another. Null also when the masjid is on Minutes After
     * Adhan: the website prints any non-null value here as the iqama without
     * looking at `type`, while the apps honour `type`, so a stored range left
     * over from an earlier Specific Time Ranges schedule used to show on the
     * website only.
     *
     * @return array<string, string|null> "05:30 PM", as the website prints it
     */
    private function getSpecificTimeRanges(): array
    {
        $resolver = IqamaResolver::for($this->resource, $this->masjidTimezone());
        $today = $resolver->today();

        $times = [];

        foreach (IqamaResolver::PRAYERS as $salah) {
            $fixed = $resolver->fixedTime($salah, $today);

            $times[$salah] = $fixed === null ? null : Carbon::parse($fixed)->format('h:i A');
        }

        return $times;
    }

    /**
     * The zone "today" is decided in: the masjid's own, as the fixture rule says
     * ("compared in the masjid's timezone") and as iOS, tvOS and Android already do.
     *
     * It used to be the app zone, UTC. For a masjid in New York that turns the date
     * over at 8 PM EDT (7 PM EST), so on the last day of a range the website served
     * the NEXT range's time for the whole evening, which is exactly when Isha is
     * prayed, while every app still showed the right one. An unknown or blank zone
     * keeps the old behaviour rather than failing the settings payload
     * (IqamaResolver::zone).
     */
    private function masjidTimezone(): string
    {
        return IqamaResolver::zone($this->zone ?? $this->resource->masjid?->timezone);
    }
}

