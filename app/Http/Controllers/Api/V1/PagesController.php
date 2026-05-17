<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PageResource;
use App\Models\Page;
use Illuminate\Http\Request;

class PagesController extends Controller
{
    /**
     * Get all active pages for the masjid
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $pages = Page::filterByMasjid()
                ->active()
                ->with(['activeSections', 'pageTitleBackgroundImage'])
                ->orderBy('order')
                ->get();

            return response()->api(200, __('api.success'), PageResource::collection($pages));

        } catch (\Exception $e) {
            return response()->api(500, \App\Support\Errors::publicMessage($e), null);
        }
    }

    /**
     * Get a single page by slug with its sections
     *
     * @param string $slug
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($slug)
    {
        try {
            $page = Page::filterByMasjid()
                ->active()
                ->where('slug', $slug)
                ->with(['activeSections', 'pageTitleBackgroundImage'])
                ->firstOrFail();

            return response()->api(200, __('api.success'), new PageResource($page));

        } catch (\Exception $e) {
            return response()->api(404, __('api.page_not_found'), null);
        }
    }

    /**
     * Get menu items (pages that should show in menu)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function menu(Request $request)
    {
        try {
            $menuPages = Page::filterByMasjid()
                ->active()
                ->showInMenu()
                ->orderBy('order')
                ->get(['id', 'slug', 'title', 'order', 'show_as_button']);

            $buttonPages = Page::filterByMasjid()
                ->active()
                ->where('show_as_button', true)
                ->orderBy('order')
                ->get(['id', 'slug', 'title', 'order', 'show_as_button']);

            return response()->api(200, __('api.success'), [
                'menu_items' => $menuPages,
                'button_items' => $buttonPages,
            ]);

        } catch (\Exception $e) {
            return response()->api(500, \App\Support\Errors::publicMessage($e), null);
        }
    }
}
