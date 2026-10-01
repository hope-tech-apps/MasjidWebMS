<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Shop\ReorderProductImagesRequest;
use App\Http\Requests\Admin\Shop\UploadProductImagesRequest;
use App\Models\Product;
use App\Services\Shop\ProductPayload;
use App\Services\Shop\ProductWriter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin: a product's pictures (shop slice B2): upload many, delete one, reorder.
 *
 * They are Spatie media in the `product_images` collection, on the library's default disk, the way
 * the gallery's are. The `media` table has no organisation column, so tenancy here is the PRODUCT's:
 * the product is found through the tenant scope first (another organisation's is a 404), and a
 * picture is only ever read through `Product::images()`, which carries `model_type` as well as
 * `model_id` (the half of the key b5c2f808 added everywhere). A picture id of another product, of
 * another organisation, or of another MODEL that happens to share the product's id is a 404 and is
 * never touched.
 *
 * At most Product::MAX_IMAGES pictures per product. The count is taken under the product's row
 * lock, so two uploads at once cannot both pass it. Every answer is the whole product, so the
 * editor refreshes from one response, carrying the product's NEW `lock_version`: an upload, a
 * delete and a reorder each move it on (they change what the editor shows), so a save from a
 * screen that read the product before them is refused as stale. The endpoints need no
 * `lock_version` of their own; delete and reorder bump it with one atomic UPDATE and do not take the
 * product's lock first (deferred, DECISIONS.md).
 */
class ShopProductImagesController extends Controller
{
    /**
     * POST .../shop/products/{product_id}/images
     *
     * `images[]`: JPEG, PNG, GIF or WebP, by bytes and by name, up to 10 MB each
     * (UploadProductImagesRequest). Refused as a whole, writing nothing, when they would take the
     * product past its eight.
     */
    public function store(UploadProductImagesRequest $request, $masjid_id, $product_id): JsonResponse
    {
        $files = $request->file('images');

        DB::transaction(function () use ($files, $product_id): void {
            $product = Product::query()->lockForUpdate()->findOrFail($product_id);

            // `reorder()`: the relation orders its pictures, and an ORDER BY on a bare COUNT(*) is an
            // error under MySQL's ONLY_FULL_GROUP_BY.
            $have = $product->images()->reorder()->count();

            if ($have + count($files) > Product::MAX_IMAGES) {
                throw ValidationException::withMessages([
                    'images' => [
                        'A product can have at most ' . Product::MAX_IMAGES . ' pictures; this one has ' . $have
                        . ' and you sent ' . count($files) . '.',
                    ],
                ]);
            }

            foreach ($files as $file) {
                $product->addMedia($file)->toMediaCollection(Product::IMAGES);
            }

            // What the editor shows changed: a screen opened before this is stale.
            ProductWriter::bumpVersion((int) $product->id);
        }, 3);

        return $this->respond((int) $product_id, Response::HTTP_CREATED);
    }

    /** DELETE .../shop/products/{product_id}/images/{media_id} */
    public function destroy($masjid_id, $product_id, $media_id): JsonResponse
    {
        $product = Product::query()->findOrFail($product_id);

        // Through the product's own relation: model_type, model_id and the collection.
        $media = $product->images()->findOrFail($media_id);

        DB::transaction(function () use ($media, $product): void {
            $media->delete();

            ProductWriter::bumpVersion((int) $product->id);
        }, 3);

        return $this->respond((int) $product->id, Response::HTTP_OK);
    }

    /**
     * PUT .../shop/products/{product_id}/images/order  {order: [id, id, ...]}
     *
     * The ids must be this product's pictures and all of them. One that is not (another product's,
     * another organisation's, one that is not there) is a 404 and nothing moves; a list that leaves
     * one out is a 422. Spatie's own `order_column` is what is written.
     */
    public function reorder(ReorderProductImagesRequest $request, $masjid_id, $product_id): JsonResponse
    {
        $product = Product::query()->findOrFail($product_id);

        $wanted = $request->ids();
        $mine = $product->images()->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $foreign = array_values(array_diff($wanted, $mine));

        if ($foreign !== []) {
            throw (new ModelNotFoundException)->setModel(Media::class, $foreign);
        }

        if (count($wanted) !== count($mine)) {
            throw ValidationException::withMessages([
                'order' => ['List every picture of this product, once each, in the order you want them.'],
            ]);
        }

        // Safe to hand over: `setNewOrder` loads by id alone, and every id here was just read
        // through this product's own relation.
        DB::transaction(function () use ($wanted, $product): void {
            Media::setNewOrder($wanted);

            ProductWriter::bumpVersion((int) $product->id);
        }, 3);

        return $this->respond((int) $product->id, Response::HTTP_OK);
    }

    private function respond(int $productId, int $status): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ProductPayload::read($productId),
        ], $status);
    }
}
