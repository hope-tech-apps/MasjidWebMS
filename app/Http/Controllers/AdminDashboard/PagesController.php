<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pages\ReorderPagesRequest;
use App\Http\Requests\Admin\Pages\StorePageRequest;
use App\Http\Requests\Admin\Pages\UpdatePageRequest;
use App\Http\Resources\Api\V1\PageResource;
use App\Models\Masjid;
use App\Support\MobileCache;
use Symfony\Component\HttpFoundation\Response;

class PagesController extends Controller
{
    /**
     * Display a listing of pages for a masjid
     */
    public function index($masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $pages = $masjid->pages()
                ->with(['sections' => function ($query) {
                    $query->orderBy('order');
                }, 'pageTitleBackgroundImage'])
                ->withCount('sections')
                ->orderBy('order')
                ->paginate(15);

            // Transform the paginated data
            $transformedPages = $pages->through(function ($page) {
                return (new PageResource($page))->resolve();
            });

            return response()->json([
                'status' => 'success',
                'data' => $transformedPages
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Store a newly created page
     */
    public function store(StorePageRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            // prepareForValidation has already coerced booleans; slug uniqueness is
            // enforced by a per-masjid Rule in the FormRequest.
            $data = $request->safe()->except('page_title_background_image');

            $page = $masjid->pages()->create($data);

            if ($request->hasFile('page_title_background_image')) {
                $page->addMediaFromRequest('page_title_background_image')
                    ->toMediaCollection('page_title_backgrounds');
            }

            $page->load('pageTitleBackgroundImage');

            MobileCache::flushPages((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'data' => new PageResource($page)
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified page
     */
    public function show($masjid_id, $page_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $page = $masjid->pages()->with(['sections', 'pageTitleBackgroundImage'])->findOrFail($page_id);

            return response()->json([
                'status' => 'success',
                'data' => new PageResource($page)
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the specified page
     */
    public function update(UpdatePageRequest $request, $masjid_id, $page_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $page = $masjid->pages()->findOrFail($page_id);

            $data = $request->safe()->except('page_title_background_image');
            $page->update($data);

            if ($request->hasFile('page_title_background_image')) {
                $page->clearMediaCollection('page_title_backgrounds');
                $page->addMediaFromRequest('page_title_background_image')
                    ->toMediaCollection('page_title_backgrounds');
            }

            $page->load('pageTitleBackgroundImage');

            MobileCache::flushPages((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'data' => new PageResource($page)
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reorder pages
     */
    public function reorder(ReorderPagesRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            foreach ($request->input('pages', []) as $pageData) {
                $page = $masjid->pages()->find($pageData['id']);
                if ($page) {
                    $page->update(['order' => $pageData['order']]);
                }
            }

            MobileCache::flushPages((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'message' => 'Pages reordered successfully'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified page (soft delete)
     */
    public function destroy($masjid_id, $page_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $page = $masjid->pages()->findOrFail($page_id);

            $page->delete();

            MobileCache::flushPages((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'message' => 'Page deleted successfully'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
