<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MealMenus\StoreMealMenuRequest;
use App\Http\Requests\Admin\MealMenus\UpdateMealMenuRequest;
use App\Models\MealMenu;
use App\Support\Errors;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: Jummah-lunch menus CRUD.
 *
 * Tenant isolation is NOT hand-rolled (FundsController is the template): the
 * `tenant` middleware binds TenantContext and BelongsToMasjid auto-scopes every
 * query and stamps masjid_id on create, so nothing here filters or sets it.
 */
class MealMenusController extends Controller
{
    public function index(Request $request, $masjid_id)
    {
        $menus = MealMenu::query()
            ->withCount('items')
            ->withCount('orders')
            ->orderByDesc('service_date')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $menus,
        ], Response::HTTP_OK);
    }

    public function store(StoreMealMenuRequest $request, $masjid_id)
    {
        try {
            // masjid_id + uuid are set by the model (creating hook / booted).
            $menu = MealMenu::create($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $menu->loadCount('items'),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** Scoped findOrFail → a cross-tenant id is a 404, never a leak. */
    public function show($masjid_id, $menu_id)
    {
        $menu = MealMenu::with(['items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->withCount('orders')
            ->findOrFail($menu_id);

        return response()->json([
            'status' => 'success',
            'data' => $menu,
        ], Response::HTTP_OK);
    }

    public function update(UpdateMealMenuRequest $request, $masjid_id, $menu_id)
    {
        $menu = MealMenu::findOrFail($menu_id);

        try {
            $menu->update($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $menu->loadCount('items'),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Upload a flyer image and return its URL. Menu-agnostic on purpose: a NEW
     * menu has no id yet, so the flyer is uploaded first and its URL saved with
     * the menu (new or existing). Stored on the public disk under lunch-flyers/;
     * the returned absolute URL is what goes into meal_menus.flyer_image_url.
     */
    public function uploadFlyer(Request $request, $masjid_id)
    {
        $request->validate([
            'flyer' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        try {
            $file = $request->file('flyer');
            $name = Str::uuid() . '.' . strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $path = $file->storeAs('lunch-flyers', $name, 'public');

            return response()->json([
                'status' => 'success',
                'data' => ['url' => url('storage/' . $path)],
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Soft-delete a menu. The menu SoftDeletes, and meal_orders.meal_menu_id is a
     * non-cascading FK — soft delete only stamps deleted_at, so a menu with
     * orders against it is hidden without severing the orders' history.
     */
    public function destroy($masjid_id, $menu_id)
    {
        $menu = MealMenu::findOrFail($menu_id);

        try {
            $menu->delete();

            return response()->json([
                'status' => 'success',
                'data' => ['id' => (int) $menu_id],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
