<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Shop\PublicCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shop, read by the renderer (shop slice B2; DECISIONS.md 2026-10-01): the product grid and a
 * product page. No auth: the organisation is the `masjid-id` header, as on every `/api/v1` route.
 *
 * ## Dark until it is on
 *
 * Both routes sit behind `shop.enabled` (EnsureShopEnabled): unless the basket is on for the
 * organisation AND it has been granted the `shop` capability, each answers the 404 an unknown route
 * answers, before anything here runs. The gate asks `PublicTenant::exists()` (the resolver every
 * `/api/v1` route asks), so by the time this code runs the header names a live organisation that has
 * the shop, and nothing is asked a second time.
 *
 * ## What leaves
 *
 * Each listed product's name, slug, category, description, cheapest price (and whether the sizes
 * differ), currency, picture URLs in order, and its sizes' id, label, price and `sold_out`. NEVER a
 * stock, sold or held count (PublicCatalogue). Unlisted products (inactive, deleted, or with no
 * enabled size) are not there, and asking for one by slug is the same 404 as a slug that never
 * existed.
 *
 * ## No caching
 *
 * Availability moves with every payment page, so nothing here sets a Cache-Control: the response
 * carries exactly the header the basket's own reads carry (Laravel's default, which a shared cache
 * will not keep), and ShopPublicApiTest pins that it is the same.
 *
 * The envelope is the basket's (`{status, message, data}`), built here rather than with the `api`
 * macro because that macro drops an empty `data`, and an empty shop is `data: []`, not no data.
 */
class ShopProductsController extends Controller
{
    private const NOT_FOUND = 'This product was not found.';

    /** GET /api/v1/shop/products */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'ok',
            'data' => PublicCatalogue::listing((int) $request->header('masjid-id')),
        ]);
    }

    /** GET /api/v1/shop/products/{slug} */
    public function show(Request $request, string $slug): JsonResponse
    {
        $product = PublicCatalogue::product((int) $request->header('masjid-id'), $slug);

        if ($product === null) {
            return response()->api(404, self::NOT_FOUND, null);
        }

        return response()->json(['status' => 'success', 'message' => 'ok', 'data' => $product]);
    }
}
