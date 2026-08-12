<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PhotoGalleryResource;
use App\Models\Masjid;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Public photo gallery.
 *
 * ## Why the tenant is resolved explicitly here
 *
 * Both methods used to call `Masjid::findOrFail(request()->header('masjid-id'))`
 * inside a blanket `catch (\Exception)` that answered **500**. A request with no
 * `masjid-id` header therefore raised ModelNotFoundException and was reported as
 * a server fault — this endpoint was the single largest source of genuine
 * application errors in production's log (49 ModelNotFoundException entries in
 * the sampled window, every one of them an HTTP 500 for what is a client
 * mistake).
 *
 * `resolveTenant()` below gives the same two answers as
 * ZakatCalculatorController and FormSubmissionsController, in the same order —
 * 400 when no organisation is named, 404 when the named one does not exist — so
 * the public API answers a tenant-less request identically everywhere, and a
 * caller cannot tell an unknown id from one that exists but is unreachable.
 */
class PhotoGalleryController extends Controller
{
    /**
     * Display a paginated listing of gallery photos
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->query('per_page', 10);

            $masjid = $this->resolveTenant($request, ['gallery']);

            $gallery = $masjid->gallery()->paginate($perPage);

            return response()->api(200, __('api.success'), [
                'items' =>PhotoGalleryResource::collection($gallery->items()),
                'pagination' => [
                    'current_page' => $gallery->currentPage(),
                    'last_page' => $gallery->lastPage(),
                    'per_page' => $gallery->perPage(),
                    'total' => $gallery->total(),
                    'from' => $gallery->firstItem(),
                    'to' => $gallery->lastItem(),
                ]
            ]);

        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->api(500, \App\Support\Errors::publicMessage($e), null);
        }
    }

    /**
     * Display a single gallery photo
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $masjid = $this->resolveTenant(request());

            // Scoped through the relation, so a photo id belonging to another
            // organisation is a 404 here rather than a readable row.
            $photo = $masjid->gallery()->findOrFail($id);

            return response()->api(200, __('api.success'), $photo);

        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->api(404, 'The gallery photo was not found.', null);
        } catch (\Exception $e) {
            return response()->api(500, \App\Support\Errors::publicMessage($e), null);
        }
    }

    /**
     * The `masjid-id` header contract, matching ZakatCalculatorController.
     *
     * Throws rather than returns so both call sites above cannot forget it; the
     * HttpResponseException carries its own final response and is re-thrown past
     * the blanket catches.
     *
     * @param  array<int,string>  $with  eager loads to apply
     */
    private function resolveTenant(Request $request, array $with = []): Masjid
    {
        $masjidId = (int) $request->header('masjid-id');

        if ($masjidId <= 0) {
            throw new HttpResponseException(
                response()->api(400, 'A masjid must be specified.', null)
            );
        }

        $masjid = Masjid::with($with)->find($masjidId);

        if ($masjid === null) {
            throw new HttpResponseException(
                response()->api(404, 'The gallery is not available.', null)
            );
        }

        return $masjid;
    }
}

