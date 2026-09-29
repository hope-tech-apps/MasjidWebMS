<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Masjids\RegenerateBrandAssetsRequest;
use App\Models\Masjid;
use App\Support\BrandAssets;
use App\Support\BrandAssetsBusy;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SuperAdmin-only: rebuild an organisation's favicon, touch icon and share
 * image from its current logo (Studio W2 S8). On a live organisation that has
 * none yet, this changes its tab icon, its share card and /api/v1/settings, so
 * it is a per-organisation decision the owner makes; Studio's confirm dialog
 * says so.
 *
 * The SuperAdmin check is RegenerateBrandAssetsRequest::authorize(), which
 * runs before validation.
 */
class BrandAssetsController extends Controller
{
    public function regenerate(RegenerateBrandAssetsRequest $request, string $masjid_id)
    {
        $masjid = Masjid::findOrFail($masjid_id);

        try {
            $data = BrandAssets::regenerate($masjid, $request->validated('background_color'), (int) Auth::id());
        } catch (BrandAssetsBusy $e) {
            // Built here, not thrown as an HttpException: the app's renderer
            // replaces an HttpException's message outside debug, and this one is
            // for the SuperAdmin to read.
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->json(['status' => 'success', 'data' => $data], Response::HTTP_OK);
    }
}
