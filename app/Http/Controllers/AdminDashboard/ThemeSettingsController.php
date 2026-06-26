<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Theme\SaveThemeSettingsRequest;
use App\Models\Masjid;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

class ThemeSettingsController extends Controller
{
    public function index($masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);
        $settings = $masjid->themeSettings;

        return response()->json([
            'status' => 'success',
            'data' => $settings
        ], Response::HTTP_OK);
    }

    public function save(SaveThemeSettingsRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $settings = $masjid->themeSettings;

            $data = $request->safe()->only([
                'primary_color',
                'secondary_color',
                'accent_color',
                'background_color',
            ]);

            if ($settings) {
                $settings->update($data);
            } else {
                $settings = $masjid->themeSettings()->create($data);
            }

            // The theme is baked into the mobile masjid SHOW payload — invalidate
            // so the apps pick up the new colors on next fetch. (The web /api/v1
            // surface is uncached and needs no flush.)
            MobileCache::flushMasjid((int) $masjid_id, MobileCache::SHOW);

            return response()->json([
                'status' => 'success',
                'data' => $settings
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
