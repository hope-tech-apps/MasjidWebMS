<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Http\Resources\Api\V1\IqamaTimeSettingResource;
use App\Http\Resources\Api\V1\PrayerCalculationSettingResource;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\Announcement;
use App\Models\Masjid;
use App\Models\Service;
use App\Support\MobileCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{

    protected $masjid;

    public function init()
    {
        $this->masjid = Masjid::with(
            'logo',
            'header_logo',
            'footer_logo',
            'donationLink.image',
            'masjidAbout.aboutImage',
            'masjidAbout.missionIcon',
            'masjidAbout.visionIcon',
            'socialMediaLinks',
            'features.icon',
            'iqamaTimeSettings.timeRanges',
            'jumaaSettings',
            'country',
            'city',
            'prayerCalculationSettings'
        )->findOrFail(request()->header('masjid-id'));
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $masjidId = (int) $request->header('masjid-id');

        $payload = Cache::remember(
            MobileCache::masjidKey($masjidId, MobileCache::V1_SETTINGS),
            MobileCache::TTL_MEDIUM,
            fn() => $this->buildPayload()
        );

        return response()->api(200, __('api.success'), $payload);
    }

    /**
     * Assemble the (cacheable) /v1/settings payload. Kept separate from index()
     * so the result can be memoized via MobileCache; admin edits to masjid
     * details, branding, prayer-calc, features, about, donation, iqama or jumaa
     * settings flush MobileCache::V1_SETTINGS so the next request rebuilds it.
     */
    private function buildPayload(): array
    {
        $this->init();
        $masjid = $this->masjid;
        return [
            'masjid' => [
                'id' => $masjid->id,
                'name' => $masjid->name,
                'email' => $masjid->email,
                'phone' => $masjid->phone,
                'address' => $masjid->address,
                'latitude' => $masjid->latitude,
                'longitude' => $masjid->longitude,
                'country' => $masjid->country->name ?? null,
                'city' => $masjid->city->name ?? null,
                'timezone' => $masjid->timezone,
            ],
            'prayer_calculation' => $masjid->prayerCalculationSettings
                ? (new PrayerCalculationSettingResource($masjid->prayerCalculationSettings))->resolve()
                : null,
            'logo_url' => $masjid->logo->original_url ?? null,
            'header_logo_url' => $masjid->header_logo->original_url ?? null,
            'footer_logo_url' => $masjid->footer_logo->original_url ?? null,
            'copyright_text' => $masjid->copyright_text ?? null,
            'app_store_link' => $masjid->app_store_link ?? null,
            'google_play_link' => $masjid->google_play_link ?? null,
            'google_maps_key' => $masjid->google_maps_key ?? null,
            'social_media' =>  $masjid->socialMediaLinks->map(fn($item) => array(
                'type' => $item->type,
                'value' => $item->value
            ))->values()->all(),
            'activated_features' => $masjid->features->filter(function ($feature) {
                return $feature->pivot->is_available == true || $feature->pivot->is_available == 1 || $feature->pivot->is_available == "1";
            })->map(function ($feature) {
                return [
                    'id' => $feature->id,
                    'key' => $feature->key,
                    'name' => $feature->name,
                    'icon_url' => $feature->icon->original_url ?? null,
                ];
            })->values()->all(),
            'iqama_settings' => $masjid->iqamaTimeSettings
                ? (new IqamaTimeSettingResource($masjid->iqamaTimeSettings))->resolve()
                : null,
            'jumaa_settings' => $this->getJumaaSettings(),
        ];
    }

    public function getJumaaSettings()
    {
        return isset($this->masjid->jumaaSettings) ? [
            'athans' => collect($this->masjid->jumaaSettings->athans)->map(fn($item,$index) => array('time' => $item,'name' => 'Shift #'.($index+1), 'formatted_time' => date('h:i A', strtotime($item))))->values()->all(),
        ]: ['athans' => []];
    }

}
