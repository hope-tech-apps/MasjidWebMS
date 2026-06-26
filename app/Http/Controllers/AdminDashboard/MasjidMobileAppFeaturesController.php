<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MobileAppFeatures\UpdateFeatureAvailabilityRequest;
use App\Models\Masjid;
use App\Models\MasjidMobileAppFeature;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

class MasjidMobileAppFeaturesController extends Controller
{
    public function index($masjid_id)
    {
        $masjid = Masjid::with('features.icon')->findOrFail($masjid_id);
        $features = $masjid->features;
        return response()->json([
            'status' => 'success',
            'data' => $features
        ], Response::HTTP_OK);
    }

    public function update(UpdateFeatureAvailabilityRequest $request, $masjid_id, $feature_id)
    {
        try {
            $masjid = Masjid::with('features')->findOrFail($masjid_id);
            $feature = $masjid->features()->where('feature_id', $feature_id)->first();
            $masjidFeaturePivot = MasjidMobileAppFeature::where([
                'masjid_id' => $masjid->id,
                'feature_id' => $feature->id,
            ])->first();

            $masjidFeaturePivot->update(['is_available' => $request->boolean('is_available')]);

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::FEATURES);
            // activated_features rides along in the V1 /settings payload.
            MobileCache::flushSettings((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'data' => $masjidFeaturePivot
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'data' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
