<?php

use App\Http\Controllers\AdminDashboard\MealMenuItemsController;
use App\Http\Controllers\AdminDashboard\MealMenusController;
use App\Http\Controllers\AdminDashboard\MealOrdersController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| The lunch realm
|------------------------------------------------------------------------------
|
| A staff login (users.type='LunchStaff') that can reach the Jummah-lunch board
| and NOTHING else — the kitchen volunteer who takes the orders but has no
| business in the donation ledger or the member directory.
|
| In its OWN file, never a sibling inside routes/admin.php, for the reason
| teacher.php and family.php are: admin.php has exactly one `auth:sanctum` group
| and it always carries `admin`, which REJECTS this type. Widening `admin` to
| admit LunchStaff would open EVERY admin endpoint at once — the failure mode
| this whole realm exists to prevent.
|
| The stack is `auth:sanctum` + `lunch` + `tenant`:
|   `lunch`   — is the caller a LunchStaff at all? (the whole-realm gate)
|   `tenant`  — binds their ONE masjid from `masjid_user`, never from the URL,
|               so there is no id here for them to change.
|
| Deliberately NO `permission:` (they hold none — Permission::count() stays 8)
| and NO `crm`: the lunch module was built to run without the member directory,
| and a masjid that has never switched the CRM on must still be able to sell
| lunch. Both are the same choices routes/admin.php makes for the admin-side
| lunch group.
|
| THE CONTROLLERS ARE THE ADMIN ONES, REUSED. There is no second implementation
| of pricing, tenancy or the mark-paid rule to drift out of step — the board a
| volunteer sees is the board an admin sees, resolved against the same bound
| tenant. What differs is only which door they came through. The `$masjid_id`
| first argument every one of those actions takes is route-shape only; the
| authoritative masjid is the bound tenant, so the placeholder below satisfies
| the signature without ever being trusted (BelongsToMasjid scopes the queries).
|
| NOT here on purpose: MealMenusController::destroy. Deleting a menu removes the
| orders customers placed against it; that is an owner's decision, not a
| volunteer's, and it is one click away from a Friday's takings. Nothing else in
| the module is withheld.
*/

Route::middleware(['auth:sanctum', 'lunch'])
    ->prefix('lunch')
    ->group(function () {
        // No `tenant`: signing out is not about a masjid, and a principal whose
        // membership was just removed must still be able to end their session
        // rather than be met with a 403 by the tenant binding.
        Route::post('/logout', [\App\Http\Controllers\AdminDashboard\AuthController::class, 'logout']);
    });

Route::middleware(['auth:sanctum', 'lunch', 'tenant'])
    ->prefix('lunch')
    ->group(function () {
        // `{masjid_id}` is NOT in these URLs. The tenant is the principal's, and
        // a route parameter would be an invitation to type someone else's id.
        // The controllers' first argument is filled with a placeholder.
        Route::controller(MealMenusController::class)->group(function () {
            Route::get('/menus', fn (\Illuminate\Http\Request $r) => app(MealMenusController::class)->index($r, 0));
            Route::post('/menus', fn (\App\Http\Requests\Admin\MealMenus\StoreMealMenuRequest $r) => app(MealMenusController::class)->store($r, 0));
            Route::get('/menus/{menu_id}', fn ($menu_id) => app(MealMenusController::class)->show(0, $menu_id));
            Route::put('/menus/{menu_id}', fn (\App\Http\Requests\Admin\MealMenus\UpdateMealMenuRequest $r, $menu_id) => app(MealMenusController::class)->update($r, 0, $menu_id));
            Route::post('/flyer', fn (\Illuminate\Http\Request $r) => app(MealMenusController::class)->uploadFlyer($r, 0));
        });

        Route::controller(MealMenuItemsController::class)->group(function () {
            Route::post('/menus/{menu_id}/items', fn (\App\Http\Requests\Admin\MealMenus\StoreMealMenuItemRequest $r, $menu_id) => app(MealMenuItemsController::class)->store($r, 0, $menu_id));
            Route::put('/menus/{menu_id}/items/{item_id}', fn (\App\Http\Requests\Admin\MealMenus\UpdateMealMenuItemRequest $r, $menu_id, $item_id) => app(MealMenuItemsController::class)->update($r, 0, $menu_id, $item_id));
            Route::delete('/menus/{menu_id}/items/{item_id}', fn ($menu_id, $item_id) => app(MealMenuItemsController::class)->destroy(0, $menu_id, $item_id));
        });

        Route::controller(MealOrdersController::class)->group(function () {
            Route::get('/menus/{menu_id}/orders', fn (\Illuminate\Http\Request $r, $menu_id) => app(MealOrdersController::class)->index($r, 0, $menu_id));
            Route::get('/menus/{menu_id}/orders/{order_id}', fn ($menu_id, $order_id) => app(MealOrdersController::class)->show(0, $menu_id, $order_id));
            Route::post('/menus/{menu_id}/orders/{order_id}/mark-paid', fn ($menu_id, $order_id) => app(MealOrdersController::class)->markPaid(0, $menu_id, $order_id));
            Route::put('/menus/{menu_id}/orders/{order_id}/status', fn (\App\Http\Requests\Admin\MealMenus\UpdateMealOrderStatusRequest $r, $menu_id, $order_id) => app(MealOrdersController::class)->updateStatus($r, 0, $menu_id, $order_id));
        });
    });
