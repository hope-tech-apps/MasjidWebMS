<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MasjidDetails\UpdateGeneralSettingsRequest;
use App\Http\Requests\Admin\MasjidDetails\UpdateMasjidDetailsRequest;
use App\Models\Masjid;
use App\Models\MasjidSocialMediaLink;
use App\Support\MobileCache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class MasjidDetailsController extends Controller
{
    public function getTimezones()
    {
        $timezones = \DateTimeZone::listIdentifiers();

        return response()->json([
            'status' => 'success',
            'data' => $timezones
        ], Response::HTTP_OK);
    }

    public function getDetails($masjid_id)
    {
        $masjid = Masjid::with('logo', 'footer_logo', 'socialMediaLinks')->findOrFail($masjid_id);

        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ], Response::HTTP_OK);
    }

    public function updateDetails(UpdateMasjidDetailsRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            DB::beginTransaction();

            // Update masjid account
            $masjid->name = $request->input('name');
            $masjid->email = $request->input('email');
            $masjid->phone = $request->input('phone');
            $masjid->timezone = $request->input('timezone');
            $masjid->latitude = $request->input('latitude');
            $masjid->longitude = $request->input('longitude');

            if ($request->filled('website_link')) {
                $masjid->website_link = $request->input('website_link');
            }

            $masjid->update();

            if ($request->hasFile('logo')) {
                $masjid->addMediaFromRequest('logo')->toMediaCollection('logos');
            }

            // Update or store social media links
            if ($request->filled('facebook_url')) {
                MasjidSocialMediaLink::updateOrStoreSocialMediaLink($masjid->id, 'Facebook', $request->input('facebook_url'));
            }
            if ($request->filled('youtube_url')) {
                MasjidSocialMediaLink::updateOrStoreSocialMediaLink($masjid->id, 'YouTube', $request->input('youtube_url'));
            }
            if ($request->filled('instagram_url')) {
                MasjidSocialMediaLink::updateOrStoreSocialMediaLink($masjid->id, 'Instagram', $request->input('instagram_url'));
            }
            if ($request->filled('whatsapp_url')) {
                MasjidSocialMediaLink::updateOrStoreSocialMediaLink($masjid->id, 'WhatsApp_URL', $request->input('whatsapp_url'));
            }
            if ($request->filled('whatsapp_number')) {
                MasjidSocialMediaLink::updateOrStoreSocialMediaLink($masjid->id, 'WhatsApp_Number', $request->input('whatsapp_number'));
            }

            $masjid = Masjid::with('logo', 'footer_logo', 'socialMediaLinks')->findOrFail($masjid->id);

            DB::commit();

            // Masjid metadata flows into multiple mobile endpoints (show, about).
            MobileCache::flushMasjidAll((int) $masjid_id);
            MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

            return response()->json([
                'status' => 'success',
                'data' => $masjid
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getGeneralSettings($masjid_id)
    {
        $masjid = Masjid::with('header_logo', 'footer_logo')->findOrFail($masjid_id);

        return response()->json([
            'status' => 'success',
            'data' => $masjid
        ], Response::HTTP_OK);
    }

    public function updateGeneralSettings(UpdateGeneralSettingsRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            DB::beginTransaction();

            $masjid->copyright_text = $request->input('copyright_text');
            $masjid->app_store_link = $request->input('app_store_link');
            $masjid->google_play_link = $request->input('google_play_link');
            $masjid->google_maps_key = $request->input('google_maps_key');
            $masjid->update();

            if ($request->hasFile('header_logo')) {
                $masjid->addMediaFromRequest('header_logo')->toMediaCollection('header_logos');
            }

            if ($request->hasFile('footer_logo')) {
                $masjid->addMediaFromRequest('footer_logo')->toMediaCollection('footer_logos');
            }

            $masjid = Masjid::with('header_logo', 'footer_logo')->findOrFail($masjid->id);

            DB::commit();

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::SHOW);
            MobileCache::flushGlobal(MobileCache::MASJIDS_LIST);

            return response()->json([
                'status' => 'success',
                'data' => $masjid
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
