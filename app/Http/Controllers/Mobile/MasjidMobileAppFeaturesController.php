<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
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
}
