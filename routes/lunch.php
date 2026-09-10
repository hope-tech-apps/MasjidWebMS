<?php

use App\Http\Controllers\AdminDashboard\AuthController;
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
| and NOTHING else — the volunteer who takes the orders but has no business in
| the donation ledger or the member directory.
|
| In its OWN file, never a sibling inside routes/admin.php, for the reason
| teacher.php and family.php are: admin.php has exactly one `auth:sanctum` group
| and it always carries `admin`, which REJECTS this type. Widening `admin` to
| admit LunchStaff would open EVERY admin endpoint at once — the failure mode
| this whole realm exists to prevent.
|
| The stack is `auth:sanctum` + `lunch` + `tenant`:
|   `lunch`  — is the caller a LunchStaff at all? (the whole-realm gate)
|   `tenant` — ResolveMasjidTenant's LunchStaff branch resolves the id in the URL
|              against their `masjid_user` membership and 403s anything else, so
|              the id below is checked, never trusted.
|
| Deliberately NO `permission:` (they hold none — Permission::count() stays 8)
| and NO `crm`: the lunch module was built to run without the member directory,
| and a masjid that has never switched the CRM on must still be able to sell
| lunch. Both are the same choices routes/admin.php makes for its lunch group.
|
| THE URL SHAPE MIRRORS routes/teacher.php ON PURPOSE — `{masjid_id}` in the
| path, plain `Route::controller()`, no closures. An earlier draft dropped the id
| and adapted the controller signatures with closure routes; that read as tighter
| but is NOT, because the resolver checks the id either way, and it made every
| route in this file UNCACHEABLE. Laravel refuses to serialise a closure route,
| so `route:cache` would have thrown — and on a host that already had a cache,
| these routes were simply invisible (404) until it was rebuilt.
|
| THE CONTROLLERS ARE THE ADMIN ONES, REUSED. There is no second implementation
| of pricing, tenancy or the mark-paid rule to drift out of step — the board a
| volunteer sees is the board an admin sees, resolved against the same bound
| tenant. What differs is only which door they came through.
|
| NOT here on purpose: MealMenusController::destroy. Deleting a menu removes the
| orders customers placed against it; that is an owner's decision, not a
| volunteer's, and it is one click away from a Friday's takings.
*/

Route::middleware(['auth:sanctum', 'lunch'])->prefix('lunch')->group(function () {
    // Session endpoints — no `tenant`, and reusing AuthController's LunchStaff
    // branch (which attaches their masjid from the membership).
    //
    // `/user` is not a convenience: the SPA re-fetches the principal on every
    // page load, and the admin `/user` route is `admin`-gated and rejects this
    // type. Without this route a reload 401s, the boot sequence treats that as
    // a failed session and signs them out — so the login worked and refreshing
    // the page logged them straight back out. The teacher realm carries its own
    // for exactly the same reason.
    Route::get('/user', [AuthController::class, 'user']);

    // A principal whose membership was just removed must still be able to end
    // their session rather than be met with a 403 by the tenant binding.
    Route::post('/logout', [AuthController::class, 'logout']);
});

// `capability:jummah_lunch` — a lunch login at an organisation whose lunch
// capability is off reaches nothing (config/capabilities.php).
Route::middleware(['auth:sanctum', 'lunch', 'tenant', 'capability:jummah_lunch'])
    ->prefix('lunch/masjids/{masjid_id}/jummah-lunch')
    ->group(function () {
        Route::controller(MealMenusController::class)->group(function () {
            Route::get('/menus', 'index');
            Route::post('/menus', 'store');
            Route::get('/menus/{menu_id}', 'show');
            Route::put('/menus/{menu_id}', 'update');
            Route::post('/flyer', 'uploadFlyer');
        });

        Route::controller(MealMenuItemsController::class)->group(function () {
            Route::post('/menus/{menu_id}/items', 'store');
            Route::put('/menus/{menu_id}/items/{item_id}', 'update');
            Route::delete('/menus/{menu_id}/items/{item_id}', 'destroy');
        });

        Route::controller(MealOrdersController::class)->group(function () {
            Route::get('/menus/{menu_id}/orders', 'index');
            Route::get('/menus/{menu_id}/orders/{order_id}', 'show');
            Route::post('/menus/{menu_id}/orders/{order_id}/mark-paid', 'markPaid');
            Route::put('/menus/{menu_id}/orders/{order_id}/status', 'updateStatus');
        });
    });
