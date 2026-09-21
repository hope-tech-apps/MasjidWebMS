<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Http\Resources\Api\V1\IqamaTimeSettingResource;
use App\Http\Resources\Api\V1\PrayerCalculationSettingResource;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Http\Resources\Api\V1\ThemeSettingResource;
use App\Models\Announcement;
use App\Models\Masjid;
use App\Models\Service;
use Illuminate\Http\Request;

class SettingController extends Controller
{

    protected $masjid;

    public function init()
    {
        $this->masjid = Masjid::with(
            'logo',
            'header_logo',
            'footer_logo',
            'favicon',
            'touch_icon',
            'share_image',
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
            'prayerCalculationSettings',
            'themeSettings'
        )->findOrFail(request()->header('masjid-id'));
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $this->init();
        $masjid = $this->masjid;
        $data = [
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
                ? new PrayerCalculationSettingResource($masjid->prayerCalculationSettings)
                : null,
            // Per-masjid color theme. null when no row → clients fall back to their
            // built-in defaults. Same shape as the mobile surface (MasjidsController).
            'theme' => $masjid->themeSettings
                ? new ThemeSettingResource($masjid->themeSettings)
                : null,
            'logo_url' => $masjid->logo->original_url ?? null,
            'header_logo_url' => $masjid->header_logo->original_url ?? null,
            'footer_logo_url' => $masjid->footer_logo->original_url ?? null,
            'copyright_text' => $masjid->copyright_text ?? null,
            'app_store_link' => $masjid->app_store_link ?? null,
            'google_play_link' => $masjid->google_play_link ?? null,
            // Public on purpose, unlike the mobile directory (which denylists
            // it): the website renderer loads this tenant's styled Maps
            // JavaScript API map with it; removing it would drop Burlington to
            // the keyless embed. The tenant is whatever `masjid-id` the caller
            // names and ids are public, so anyone can read any tenant's key
            // here, whether or not that tenant's website is on or live. So the
            // only protection is on Google's side: each key must be restricted
            // to its site's HTTP referrers and to the Maps JavaScript API. That
            // is set in the tenant's Google Cloud console, not here, and nothing
            // in this repo can check it.
            'google_maps_key' => $masjid->google_maps_key ?? null,
            'social_media' =>  $masjid->socialMediaLinks->map(fn($item) => array(
                'type' => $item->type,
                'value' => $item->value
            )),
            'activated_features' => $masjid->features->filter(function ($feature) {
                return $feature->pivot->is_available == true || $feature->pivot->is_available == 1 || $feature->pivot->is_available == "1";
            })->map(function ($feature) {
                return [
                    'id' => $feature->id,
                    'key' => $feature->key,
                    'name' => $feature->name,
                    'icon_url' => $feature->icon->original_url ?? null,
                ];
            })->values(),
            // The resource decides "today" in the masjid's own zone; hand it the zone we
            // already hold rather than have it load the masjid again.
            'iqama_settings' => $masjid->iqamaTimeSettings
                ? (new IqamaTimeSettingResource($masjid->iqamaTimeSettings))->inTimezone($masjid->timezone)
                : null,
            'jumaa_settings' => $this->getJumaaSettings(),
        ];

        return response()->api(200, __('api.success'), $this->withBrandDerivatives($data, $masjid));
    }

    /**
     * Studio's favicon, touch icon and share image URLs, placed after
     * `footer_logo_url`, and ONLY for an organisation that has that row
     * (docs/manara-studio-w1.md R11, S8).
     *
     * Not `null` when absent: every live organisation has none of the three, and
     * a key it never had would change the bytes of its settings payload and of
     * anything the renderer serializes from it. Never taken from `logos`
     * either, or Burlington's and MEC's tab icons would change unasked.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withBrandDerivatives(array $data, Masjid $masjid): array
    {
        $derivatives = array_filter([
            'favicon_url' => $masjid->favicon?->original_url,
            'touch_icon_url' => $masjid->touch_icon?->original_url,
            'share_image_url' => $masjid->share_image?->original_url,
        ], fn ($url) => $url !== null);

        if ($derivatives === []) {
            return $data;
        }

        $at = array_search('footer_logo_url', array_keys($data), true) + 1;

        return array_slice($data, 0, $at, true) + $derivatives + array_slice($data, $at, null, true);
    }

    public function getJumaaSettings()
    {
        return isset($this->masjid->jumaaSettings) ? [
            'athans' => collect($this->masjid->jumaaSettings->athans)->map(fn($item,$index) => array('time' => $item,'name' => 'Shift #'.($index+1), 'formatted_time' => date('h:i A', strtotime($item)))),
        ]: (object) ['athans' => []];
    }

}
