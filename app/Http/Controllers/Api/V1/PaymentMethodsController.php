<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Support\AcceptedPaymentMethods;
use App\Support\PublicTenant;
use Illuminate\Http\Request;

/**
 * GET /api/v1/payment-methods — the organisation's accepted payment methods and
 * how to pay with each, for any public page that tells people how to pay.
 *
 * The `masjid-id` header names the organisation, as everywhere on /api/v1; a
 * missing or offboarded one is the same 404. The list is exactly what
 * AcceptedPaymentMethods::publicList() says — card only while the organisation's
 * Stripe account can take charges — so no page decides that for itself.
 */
class PaymentMethodsController extends Controller
{
    public function index(Request $request)
    {
        $masjidId = (int) $request->header('masjid-id');

        if ($masjidId <= 0 || ! PublicTenant::exists($masjidId)) {
            return response()->api(404, 'Organization not found.', null);
        }

        return response()->api(200, 'ok', [
            'methods' => AcceptedPaymentMethods::publicList(Masjid::findOrFail($masjidId)),
        ]);
    }
}
