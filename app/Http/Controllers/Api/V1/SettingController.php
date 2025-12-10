<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\Announcement;
use App\Models\Masjid;
use App\Models\Service;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {

        $masjid = Masjid::with(
            'logo',
            'footer_logo',
            'donationLink.image',
            'masjidAbout.aboutImage',
            'masjidAbout.missionIcon',
            'masjidAbout.visionIcon',
            'socialMediaLinks',
            'features.icon',
            'iqamaTimeSettings',
            'jumaaSettings',
            'country',
            'city'
        )->findOrFail(request()->header('masjid-id'));

        return response()->api(200, __('api.success'), [
            'masjid' => [
                'id' => $masjid->id,
                'name' => $masjid->name,
                'email' => $masjid->email,
                'phone' => $masjid->phone,
                'address' => $masjid->address,
                'latitude' => $masjid->latitude,
                'longitude' => $masjid->longitude,
                'country' => $masjid->country,
                'city' => $masjid->city,
            ],
            'logo_url' => $masjid->logo->original_url ?? null,
            'footer_logo_url' => $masjid->footer_logo->original_url ?? null,
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
            'iqama_settings' => $masjid->iqamaTimeSettings,
            'jumaa_settings' => $masjid->jumaaSettings,
        ]);
    }

}
