<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MealMenus\StoreMealMenuItemRequest;
use App\Http\Requests\Admin\MealMenus\UpdateMealMenuItemRequest;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Support\Errors;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: the items on a Jummah-lunch menu. Tenant-scoped by the bound tenant;
 * the parent menu is resolved with a scoped findOrFail so a cross-tenant menu id
 * is a 404.
 */
class MealMenuItemsController extends Controller
{
    public function store(StoreMealMenuItemRequest $request, $masjid_id, $menu_id)
    {
        $menu = MealMenu::findOrFail($menu_id);

        try {
            $item = $menu->items()->create($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $item,
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(UpdateMealMenuItemRequest $request, $masjid_id, $menu_id, $item_id)
    {
        // Both scoped: the item must belong to THIS menu (and this tenant).
        $item = MealMenuItem::where('meal_menu_id', $menu_id)->findOrFail($item_id);

        try {
            $item->update($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $item,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete an item. meal_order_items.meal_menu_item_id is nullOnDelete, so past
     * orders keep their snapshotted name + price; only the live catalog row goes.
     */
    public function destroy($masjid_id, $menu_id, $item_id)
    {
        $item = MealMenuItem::where('meal_menu_id', $menu_id)->findOrFail($item_id);

        try {
            $item->delete();

            return response()->json([
                'status' => 'success',
                'data' => ['id' => (int) $item_id],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
