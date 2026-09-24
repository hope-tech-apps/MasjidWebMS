<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Studio\StudioLayoutPresetsRequest;
use App\Support\Studio\LayoutPresets;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manara Studio's layout step: the starter websites one vertical is offered
 * (config/studio_layouts.php, docs/manara-studio-w1.md S4). SuperAdmin-only
 * through the `super` middleware on the studio route group, and read-only.
 */
class StudioLayoutPresetsController extends Controller
{
    public function index(StudioLayoutPresetsRequest $request)
    {
        $orgType = (string) $request->validated('org_type');

        return response()->json([
            'status' => 'success',
            'data' => LayoutPresets::optionsPayload()[$orgType] ?? [],
        ], Response::HTTP_OK);
    }
}
