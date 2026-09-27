<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Masjids\RegenerateBrandAssetsRequest;
use App\Models\Masjid;
use App\Support\BrandAssets;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SuperAdmin-only: rebuild an organisation's favicon, touch icon and share
 * image from its current logo (Studio W2 S8). On a live organisation that has
 * none yet, this changes its tab icon, its share card and /api/v1/settings, so
 * it is a per-organisation decision the owner makes; Studio's confirm dialog
 * says so.
 */
class BrandAssetsController extends Controller
{
    public function regenerate(RegenerateBrandAssetsRequest $request, string $masjid_id)
    {
        if (Auth::user()?->type !== 'SuperAdmin') {
            abort(Response::HTTP_FORBIDDEN, 'Only a super admin can regenerate an organisation\'s brand images.');
        }

        $masjid = Masjid::findOrFail($masjid_id);

        return response()->json([
            'status' => 'success',
            'data' => BrandAssets::regenerate($masjid, $request->validated('background_color'), (int) Auth::id()),
        ], Response::HTTP_OK);
    }
}
