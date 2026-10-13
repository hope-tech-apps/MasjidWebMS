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
            if (\App\Support\AppBottomTabs::enabled($masjid)) {
                return $this->updateWithTabs($request, $masjid);
            }
            $feature = $masjid->features()->where('feature_id', $feature_id)->first();
            $masjidFeaturePivot = MasjidMobileAppFeature::where([
                'masjid_id' => $masjid->id,
                'feature_id' => $feature->id,
            ])->first();

            $masjidFeaturePivot->update(['is_available' => $request->boolean('is_available')]);

            MobileCache::flushMasjid((int) $masjid_id, MobileCache::FEATURES);

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

    /** Share the tab writer's organisation mutex only while opted in, preserving the OFF writer. */
    private function updateWithTabs(UpdateFeatureAvailabilityRequest $request, Masjid $masjid)
    {
        $pivot = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $masjid) {
            Masjid::whereKey($masjid->id)->lockForUpdate()->firstOrFail();
            $pivot = MasjidMobileAppFeature::where('masjid_id', $masjid->id)
                ->where('feature_id', $request->route('feature_id'))->firstOrFail();
            $pivot->update(['is_available' => $request->boolean('is_available')]);
            return $pivot;
        });
        MobileCache::flushMasjid((int) $masjid->id, MobileCache::FEATURES);
        return response()->json(['status' => 'success', 'data' => $pivot], Response::HTTP_OK);
    }
}
