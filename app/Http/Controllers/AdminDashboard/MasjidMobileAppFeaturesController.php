<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\MasjidMobileAppFeature;
use Illuminate\Http\Request;
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

    public function update(Request $request, $masjid_id, $feature_id)
    {
        try {

            $masjid = Masjid::with('features')->findOrFail($masjid_id);
            $feature = $masjid->features()->where('feature_id', $feature_id)->first();
            $masjidFeaturePivot = MasjidMobileAppFeature::where([
                'masjid_id' => $masjid->id,
                'feature_id' => $feature->id,
            ])->first();

            $inputs = $request->validate([
                'is_available' => 'required|boolean'
            ]);

            $masjidFeaturePivot->update(['is_available' => $inputs['is_available']]);

            return response()->json([
                'status' => 'success',
                'data' => $masjidFeaturePivot
            ], Response::HTTP_OK);

        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'data' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);

        }
    }
}
