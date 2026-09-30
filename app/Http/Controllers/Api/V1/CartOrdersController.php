<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\PublicTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the page Stripe returns the payer to reads after a basket payment (brief 5). The
 * design is the form status read's, copied (FormResponsePaymentsController::show):
 *
 *  - the uuid in the path is a BEARER handle. It travels in Stripe's return URL and the
 *    browser's history, so the answer is the PAYMENT STATE and nothing else: the status,
 *    the order number, the total and the currency. No name, no e-mail, no phone, no lines and
 *    no answers, however the order was made.
 *  - the route is unauthenticated and unbound, like the rest of the basket: the organisation
 *    is the `masjid-id` header and must still exist (PublicTenant), and the order is found
 *    within it, by hand. A uuid that is unknown, another organisation's, or belongs to an
 *    offboarded one is ONE 404, byte for byte.
 *  - its limiter is keyed by the uuid, not the connection (`cart-order-status`,
 *    AppServiceProvider): every phone at a venue shares one address, and the return page
 *    polls while the webhook lands. A made-up uuid meets a per-connection guard instead.
 *
 * Nothing here marks an order paid. Only the signed webhook does, and only it ever moves
 * `pending` to `paid` or `expired`.
 */
class CartOrdersController extends Controller
{
    private const NOT_FOUND = 'This order was not found.';

    /**
     * GET /api/v1/cart-orders/{uuid}
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $masjidId = (int) $request->header('masjid-id');

        if ($masjidId <= 0) {
            return response()->api(400, 'A masjid must be specified.', null);
        }

        $order = PublicTenant::exists($masjidId)
            ? Order::findByUuidForMasjid(strtolower($uuid), $masjidId)
            : null;

        if ($order === null) {
            return response()->api(404, self::NOT_FOUND, null);
        }

        // Built field by field, so that nothing added to the order later reaches the page.
        return response()->api(200, 'ok', [
            'status' => (string) $order->status,
            'order_number' => (string) $order->order_number,
            'total_minor' => (int) $order->total_minor,
            'currency' => (string) $order->currency,
        ]);
    }
}
