<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PageResource;
use App\Models\Masjid;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Store a newly created page
     */
    public function store(Request $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $validator = Validator::make($request->all(), [
                'slug' => 'required|string|max:255',
                'title' => 'required|string|max:255',
                'page_title' => 'nullable|string|max:255',
                'page_title_background_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
                'is_active' => 'nullable',
                'order' => 'nullable|integer',
                'show_in_menu' => 'nullable',
                'show_as_button' => 'nullable',
                'meta_description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if slug already exists for this masjid
            $exists = $masjid->pages()->where('slug', $request->slug)->exists();
            if ($exists) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'A page with this slug already exists for this masjid'
                ], Response::HTTP_BAD_REQUEST);
            }
            $data = $request->except('page_title_background_image');
            $data['is_active'] = filter_var($request->input('is_active', false), FILTER_VALIDATE_BOOLEAN);
            $data['show_in_menu'] = filter_var($request->input('show_in_menu', false), FILTER_VALIDATE_BOOLEAN);
            $data['show_as_button'] = filter_var($request->input('show_as_button', false), FILTER_VALIDATE_BOOLEAN);

            $page = $masjid->pages()->create($data);

            // Handle page title background image upload
            if ($request->hasFile('page_title_background_image')) {
                $page->addMediaFromRequest('page_title_background_image')
                    ->toMediaCollection('page_title_backgrounds');
            }

            // Load the relationship to include in response
            $page->load('pageTitleBackgroundImage');

            return response()->json([
                'status' => 'success',
                'data' => new PageResource($page)
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
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
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the specified page
     */
    public function update(Request $request, $masjid_id, $page_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $page = $masjid->pages()->findOrFail($page_id);

            $validator = Validator::make($request->all(), [
                'slug' => 'sometimes|string|max:255',
                'title' => 'sometimes|string|max:255',
                'page_title' => 'nullable|string|max:255',
                'page_title_background_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:25600',
                'is_active' => 'nullable',
                'order' => 'nullable|integer',
                'show_in_menu' => 'nullable',
                'show_as_button' => 'nullable',
                'meta_description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if slug already exists for this masjid (excluding current page)
            if ($request->has('slug')) {
                $exists = $masjid->pages()
                    ->where('slug', $request->slug)
                    ->where('id', '!=', $page_id)
                    ->exists();

                if ($exists) {
                    return response()->json([
                        'status' => 'failed',
                        'message' => 'A page with this slug already exists for this masjid'
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            $data = $request->except('page_title_background_image');
            if ($request->has('is_active')) {
                $data['is_active'] = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
            }
            if ($request->has('show_in_menu')) {
                $data['show_in_menu'] = filter_var($request->input('show_in_menu'), FILTER_VALIDATE_BOOLEAN);
            }
            if ($request->has('show_as_button')) {
                $data['show_as_button'] = filter_var($request->input('show_as_button'), FILTER_VALIDATE_BOOLEAN);
            }

            $page->update($data);

            // Handle page title background image upload
            if ($request->hasFile('page_title_background_image')) {
                // Clear existing background image
                $page->clearMediaCollection('page_title_backgrounds');

                // Add new background image
                $page->addMediaFromRequest('page_title_background_image')
                    ->toMediaCollection('page_title_backgrounds');
            }

            // Load the relationship to include in response
            $page->load('pageTitleBackgroundImage');

            return response()->json([
                'status' => 'success',
                'data' => new PageResource($page)
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reorder pages
     */
    public function reorder(Request $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $validator = Validator::make($request->all(), [
                'pages' => 'required|array',
                'pages.*.id' => 'required|integer|exists:pages,id',
                'pages.*.order' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Update each page's order
            foreach ($request->pages as $pageData) {
                $page = $masjid->pages()->find($pageData['id']);
                if ($page) {
                    $page->update(['order' => $pageData['order']]);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Pages reordered successfully'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
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

            return response()->json([
                'status' => 'success',
                'message' => 'Page deleted successfully'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
