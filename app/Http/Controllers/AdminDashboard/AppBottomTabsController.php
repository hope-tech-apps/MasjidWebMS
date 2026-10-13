<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppBottomTabs\SaveAppBottomTabsRequest;
use App\Support\{AppBottomTabs, TenantContext};
use Illuminate\Http\JsonResponse;

/** Same admin and tenant fences as General Settings; no CRM or new person permission. */
class AppBottomTabsController extends Controller
{
    /** Return the bound organisation’s selectable and retained app feature choices. */
    public function index(): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => AppBottomTabs::editor()]);
    }

    /** Save a validated ordered list under the bound organisation’s mutex. */
    public function update(SaveAppBottomTabsRequest $request): JsonResponse
    {
        AppBottomTabs::save(app(TenantContext::class)->get(), $request->validated('feature_ids'));
        return $this->index();
    }
}
