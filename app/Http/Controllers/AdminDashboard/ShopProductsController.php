<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Shop\StoreProductRequest;
use App\Http\Requests\Admin\Shop\UpdateProductRequest;
use App\Http\Requests\Admin\Shop\UploadProductImagesRequest;
use App\Models\Product;
use App\Services\Shop\ProductChangedElsewhere;
use App\Services\Shop\ProductPayload;
use App\Services\Shop\ProductWriter;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: the shop's catalogue, a product and its sizes (shop slice B2; DECISIONS.md 2026-10-01).
 *
 * Behind `capability:shop` (OFF for every organisation until a SuperAdmin grants it) and the
 * donations permissions, `view donations` to read and `manage donations` to write: the pair the
 * fee plans use for what somebody is charged (routes/admin.php). OUTSIDE `crm`, as the Jummah-lunch
 * board is, so a masjid selling uniforms need not first switch on the member directory.
 *
 * `{masjid_id}` stays in the path by convention and isolation comes from the `tenant` middleware
 * and BelongsToMasjid, never a hand-written filter: another organisation's product is a 404, and so
 * is another organisation's size named inside an update's list. The bound tenant is read only for
 * the numbers that need the organisation by name (the held-units sum, the slug check).
 *
 * A product is retired with `active = false` or deleted; a delete is a soft delete, and a paid sale
 * keeps reading from its own snapshot (the pickup list, the order). The sizes are edited as a
 * list inside the product's update, and a stock set BELOW what is sold and held is allowed and
 * means "stop selling": the response reads `available` 0 and `sold_count` is never touched.
 */
class ShopProductsController extends Controller
{
    /** Products a page of the admin list holds, and the most it may be asked for. */
    private const PER_PAGE = 25;

    private const PER_PAGE_MAX = 100;

    public function __construct(private ProductWriter $writer)
    {
    }

    /**
     * GET .../shop/products: every product of this organisation that is not deleted, active or
     * not, with its sizes, its pictures and, per size, how many are sold, held and still available.
     */
    public function index(Request $request, $masjid_id): JsonResponse
    {
        $masjidId = $this->tenantId();

        $perPage = (int) $request->query('per_page', self::PER_PAGE);
        $perPage = $perPage < 1 ? self::PER_PAGE : min($perPage, self::PER_PAGE_MAX);

        $page = Product::query()
            ->with(ProductPayload::relations())
            ->orderBy('sort')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);

        // One grouped query for every size on the page, not one per size.
        $held = ProductPayload::heldFor($masjidId, $page->getCollection());

        $page->through(static fn (Product $product): array => ProductPayload::product($product, $held));

        return response()->json([
            'status' => 'success',
            'data' => $page,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /** GET .../shop/products/{product_id} */
    public function show($masjid_id, $product_id): JsonResponse
    {
        $product = Product::query()->with(ProductPayload::relations())->findOrFail($product_id);

        return $this->respond($product, Response::HTTP_OK);
    }

    /**
     * POST .../shop/products
     *
     * `masjid_id` is never read from the body: the creating hook stamps the bound tenant. The slug
     * is generated from the name; the currency is the platform's.
     */
    public function store(StoreProductRequest $request, $masjid_id): JsonResponse
    {
        $product = $this->writer->create($this->tenantId(), $request->productAttributes(), $request->variantRows());

        return $this->respond($this->fresh($product), Response::HTTP_CREATED);
    }

    /**
     * PUT .../shop/products/{product_id}
     *
     * The product is found through the tenant scope before anything is written, so another
     * organisation's id is a 404 and nothing of it is touched; a size id inside the list that is not
     * one of THIS product's live sizes is a 404 too (ProductWriter), and saves nothing.
     *
     * `lock_version` (required) is the version the editor read; under the product's lock a different one
     * is a 409 `{status: 'failed', message}` and nothing is written. Every success moves it on.
     */
    public function update(UpdateProductRequest $request, $masjid_id, $product_id): JsonResponse
    {
        $product = Product::query()->findOrFail($product_id);

        try {
            $this->writer->update($product, $request->productAttributes(), $request->variantRows(), $request->lockVersion());
        } catch (ProductChangedElsewhere $e) {
            // Nothing was written. The editor reloads the product and makes its change again.
            return response()->json(['status' => 'failed', 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->respond($this->fresh($product), Response::HTTP_OK);
    }

    /**
     * DELETE .../shop/products/{product_id}: a soft delete of the product and its sizes. Its paid
     * sales stay readable (their own snapshot), and its slug is free for a product made later.
     */
    public function destroy($masjid_id, $product_id): JsonResponse
    {
        $product = Product::query()->findOrFail($product_id);

        $this->writer->delete($product);

        return response()->json(['status' => 'success', 'message' => 'The product was deleted.'], Response::HTTP_OK);
    }

    // ------------------------------------------------------------------------- helpers

    /** The product as the screen reads it, re-read so a size just made carries its column defaults. */
    private function fresh(Product $product): Product
    {
        return Product::query()->with(ProductPayload::relations())->findOrFail($product->id);
    }

    private function respond(Product $product, int $status): JsonResponse
    {
        $held = ProductPayload::heldFor((int) $product->masjid_id, [$product]);

        return response()->json([
            'status' => 'success',
            'data' => ProductPayload::product($product, $held),
            'meta' => $this->meta(),
        ], $status);
    }

    /** What the editor needs to know about the shop itself, beside the rows. */
    private function meta(): array
    {
        return [
            'currency' => ProductWriter::currency(),
            'max_images' => Product::MAX_IMAGES,
            'max_image_mb' => UploadProductImagesRequest::MAX_MB,
        ];
    }

    /** The organisation the SERVER bound for this request (never the URL's claim). */
    private function tenantId(): int
    {
        $masjidId = app(TenantContext::class)->get();

        if ($masjidId === null) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return (int) $masjidId;
    }
}
