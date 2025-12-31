<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\MasjidSocialMediaLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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

    public function updateDetails(Request $request, $masjid_id)
    {
        try {

            $masjid = Masjid::findOrFail($masjid_id);

            $validator = Validator::make($request->all(), [
                'logo' => 'image|mimes:jpeg,png,jpg,gif,svg',
                'name' => 'required|string',
                'website_link' => 'nullable|string',
                'email' => 'required|string|email',
                'phone' => 'required|string|regex:/^\+?[0-9 ]+$/',
                'timezone' => 'required|string|timezone',
                'latitude' => 'required|numeric|min:-90|max:90',
                'longitude' => 'required|numeric|min:-180|max:180',
                'facebook_url' => 'nullable|string|regex:/^(https?:\/\/)?(www\.)?([A-Za-z0-9-]+\.)?facebook\.com\/[A-Za-z0-9_.-]+\/?$/',
                'youtube_url' => 'nullable|string|regex:/^(https?:\/\/)?(www\.)?([A-Za-z0-9-]+\.)?(youtube\.com\/.*)$/',
                'instagram_url' => 'nullable|string|regex:/^(https?:\/\/)?(www\.)?([A-Za-z0-9-]+\.)?instagram\.com\/[A-Za-z0-9_.-]+\/?$/',
                'whatsapp_url' => 'nullable|string|regex:/^(https?:\/\/)?(www\.)?([A-Za-z0-9-]+\.)?wa\.me\/[0-9]+\/?$/',
                'whatsapp_number' => 'nullable|string|regex:/^\+?[0-9 ]+$/'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            } else if ($validator->passes()) {

                DB::beginTransaction();

                // Update masjid account
                $masjid->name = $request['name'];
                $masjid->email = $request['email'];
                $masjid->phone = $request['phone'];
                $masjid->timezone = $request['timezone'];
                $masjid->latitude = $request['latitude'];
                $masjid->longitude = $request['longitude'];

                if($request['website_link']) {
                    $masjid->website_link = $request['website_link'];
                }

                $masjid->update();

                if ($masjid && $request->hasFile('logo')) {
                    $masjid->addMediaFromRequest('logo')->toMediaCollection('logos');
                }

                // Update or Store masjid links
                if ($request['facebook_url']) {
                    MasjidSocialMediaLink::updateOrStoreSocialMediaLink($masjid->id, 'Facebook', $request['facebook_url']);
                }
                if ($request['youtube_url']) {
                    MasjidSocialMediaLink::updateOrStoreSocialMediaLink($masjid->id, 'YouTube', $request['youtube_url']);
                }
                if ($request['instagram_url']) {
                    MasjidSocialMediaLink::updateOrStoreSocialMediaLink($masjid->id, 'Instagram', $request['instagram_url']);
                }
                if ($request['whatsapp_url']) {
                    MasjidSocialMediaLink::updateOrStoreSocialMediaLink($masjid->id, 'WhatsApp_URL', $request['whatsapp_url']);
                }
                if ($request['whatsapp_number']) {
                    MasjidSocialMediaLink::updateOrStoreSocialMediaLink($masjid->id, 'WhatsApp_Number', $request['whatsapp_number']);
                }

                $masjid = Masjid::with('logo', 'footer_logo', 'socialMediaLinks')->findOrFail($masjid->id);

                DB::commit();
                return response()->json([
                    'status' => 'success',
                    'data' => $masjid
                ], Response::HTTP_OK);

            }

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
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

    public function updateGeneralSettings(Request $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $validator = Validator::make($request->all(), [
                'header_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg',
                'footer_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg',
                'copyright_text' => 'nullable|string',
                'app_store_link' => 'nullable|url',
                'google_play_link' => 'nullable|url',
                'google_maps_key' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            DB::beginTransaction();

            // Update general settings fields
            $masjid->copyright_text = $request->input('copyright_text');
            $masjid->app_store_link = $request->input('app_store_link');
            $masjid->google_play_link = $request->input('google_play_link');
            $masjid->google_maps_key = $request->input('google_maps_key');
            $masjid->update();

            // Handle header logo upload
            if ($request->hasFile('header_logo')) {
                $masjid->addMediaFromRequest('header_logo')->toMediaCollection('header_logos');
            }

            // Handle footer logo upload
            if ($request->hasFile('footer_logo')) {
                $masjid->addMediaFromRequest('footer_logo')->toMediaCollection('footer_logos');
            }

            $masjid = Masjid::with('header_logo', 'footer_logo')->findOrFail($masjid->id);

            DB::commit();
            return response()->json([
                'status' => 'success',
                'data' => $masjid
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
