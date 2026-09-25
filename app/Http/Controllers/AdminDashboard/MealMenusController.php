<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MealMenus\StoreMealMenuRequest;
use App\Http\Requests\Admin\MealMenus\UpdateMealMenuRequest;
use App\Models\MealMenu;
use App\Services\Stripe\MealOrderCheckoutService;
use App\Models\MealOrder;
use App\Support\Errors;
use App\Services\Lunch\LunchOpeningNotifier;
use App\Support\MasjidTime;
use App\Support\SiteUrl;
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
            // What the menu card shows: orders the kitchen still has to make.
            // `orders_count` keeps its meaning (every order, cancelled included)
            // for anything already reading it.
            ->withCount(['orders as live_orders_count' => fn ($q) => $q->where('status', '!=', MealOrder::STATUS_CANCELLED)])
            ->orderByDesc('service_date')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $menus->map(fn ($m) => $this->withLocalWindow($m, $masjid_id)),
        ], Response::HTTP_OK);
    }

    public function store(StoreMealMenuRequest $request, $masjid_id)
    {
        try {
            // masjid_id + uuid are set by the model (creating hook / booted).
            $menu = MealMenu::create(self::shapedForKind($request->validated(), $request->validated('kind') ?? MealMenu::KIND_DATED, true));

            // A menu can be created already open. Once-only is enforced inside
            // the notifier, not by the caller.
            app(LunchOpeningNotifier::class)->notifyOpened($menu, $request->user()?->id);

            return response()->json([
                'status' => 'success',
                'data' => $this->withLocalWindow($menu->loadCount('items'), $masjid_id),
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
            'data' => $this->withLocalWindow($menu, $masjid_id),
        ], Response::HTTP_OK);
    }

    public function update(UpdateMealMenuRequest $request, $masjid_id, $menu_id)
    {
        $menu = MealMenu::findOrFail($menu_id);

        try {
            $menu->update(self::shapedForKind($request->validated(), $menu->kind, false));

            app(LunchOpeningNotifier::class)->notifyOpened($menu, $request->user()?->id);

            // Closed early, or the cutoff brought forward: a customer's page to pay
            // for more plates must not outlive ordering (the kitchen counts at the
            // cutoff). Best effort, never failing the menu change; the webhook
            // records a late payment as owed back rather than applying it.
            $this->closeTopUpsOutliving($menu);

            return response()->json([
                'status' => 'success',
                'data' => $this->withLocalWindow($menu->loadCount('items'), $masjid_id),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Keep each kind's fields to its own kind, whatever the body carried.
     *
     * A catalogue has no service date and always has a lead time (the owner's 48
     * hours unless the admin set another); a dated menu has no lead time. Written
     * here rather than trusted to the form, so no client can make a catalogue that
     * the dated-only readers would mistake for a Friday, or a Friday menu that
     * starts enforcing a lead time. On an edit, a lead time the body does not
     * mention is left as it is; one it clears goes back to the default.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function shapedForKind(array $data, string $kind, bool $creating): array
    {
        if ($kind === MealMenu::KIND_CATALOGUE) {
            unset($data['service_date']);

            if (array_key_exists('pickup_lead_hours', $data) && $data['pickup_lead_hours'] === null) {
                $data['pickup_lead_hours'] = MealMenu::DEFAULT_PICKUP_LEAD_HOURS;
            }

            return $creating ? $data + ['pickup_lead_hours' => MealMenu::DEFAULT_PICKUP_LEAD_HOURS] : $data;
        }

        unset($data['pickup_lead_hours']);

        return $data;
    }

    private function closeTopUpsOutliving(MealMenu $menu): void
    {
        try {
            app(MealOrderCheckoutService::class)->closeTopUpsOutliving($menu);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Upload a flyer image and return its URL. Menu-agnostic on purpose: a NEW
     * menu has no id yet, so the flyer is uploaded first and its URL saved with
     * the menu (new or existing). Stored on the public disk under lunch-flyers/;
     * the returned absolute URL is what goes into meal_menus.flyer_image_url.
     *
     * THE URL IS BUILT ON THE CONFIGURED HOST, not the request's, and this is
     * the worst instance of that class in the application rather than the
     * showiest: `url()` here resolves against whatever Host the ADMIN's browser
     * sent, and the string is then WRITTEN TO A COLUMN and served from it
     * forever — `meal_menus.flyer_image_url`, read by the public ordering page
     * (Api\V1\JummahLunchOrdersController). Everywhere else a request-shaped URL
     * expires with a ten-minute cache entry; here it is durable. This deploy
     * answers to three hostnames, so an admin who opened the SPA on
     * manara.hopetechapps.com instead of masjid.hopetechapps.com permanently
     * pinned a customer-facing image to the other one. See App\Support\SiteUrl.
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
                'data' => ['url' => SiteUrl::to('storage/' . $path)],
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

            $this->closeTopUpsOutliving($menu);

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

    /**
     * Adds the ordering window as the masjid's wall clock, for the admin form.
     *
     * The stored columns stay UTC in the payload — nothing downstream has to
     * change — but an <input type="datetime-local"> cannot read a UTC instant,
     * so the form binds to these *_local twins instead. `timezone` is included
     * so the UI can label which clock the admin is actually setting.
     */
    private function withLocalWindow(MealMenu $menu, $masjid_id): array
    {
        $tz = MasjidTime::zoneFor($masjid_id);

        return $menu->toArray() + [
            'ordering_opens_at_local' => MasjidTime::toLocalInput($menu->ordering_opens_at, $tz),
            'ordering_closes_at_local' => MasjidTime::toLocalInput($menu->ordering_closes_at, $tz),
            'timezone' => $tz,
            // So the board can say "announced" rather than leaving an admin
            // guessing whether the text went out.
            'opening_notified_at' => optional($menu->opening_notified_at)->toIso8601String(),
            // What the board's add-order form needs to price the optional extra
            // and the covered fee exactly as the server will (the public menu
            // payload carries the same three).
            'max_donation_minor' => \App\Models\MealOrder::MAX_DONATION_MINOR,
            'stripe_fee_percentage' => \App\Support\StripeFees::percentage(),
            'stripe_fee_fixed_minor' => \App\Support\StripeFees::fixed(),
        ];
    }
}
