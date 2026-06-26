<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PageResource;
use App\Models\Page;
use App\Support\MobileCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
            $masjidId = (int) $request->header('masjid-id');

            $pages = Cache::remember(
                MobileCache::masjidKey($masjidId, MobileCache::V1_PAGES_LIST),
                MobileCache::TTL_MEDIUM,
                fn() => PageResource::collection(
                    Page::filterByMasjid()
                        ->active()
                        ->with(['activeSections', 'pageTitleBackgroundImage'])
                        ->orderBy('order')
                        ->get()
                )->resolve()
            );

            return response()->api(200, __('api.success'), $pages);

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
            $masjidId = (int) request()->header('masjid-id');

            // Per-slug variant key; flushPages() bumps the V1_PAGE_SHOW version so
            // every cached slug for this masjid is invalidated on a builder edit.
            $page = Cache::remember(
                MobileCache::masjidVariantKey($masjidId, MobileCache::V1_PAGE_SHOW, md5($slug)),
                MobileCache::TTL_MEDIUM,
                fn() => (new PageResource(
                    Page::filterByMasjid()
                        ->active()
                        ->where('slug', $slug)
                        ->with(['activeSections', 'pageTitleBackgroundImage'])
                        ->firstOrFail()
                ))->resolve()
            );

            return response()->api(200, __('api.success'), $page);

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
            $masjidId = (int) $request->header('masjid-id');

            $payload = Cache::remember(
                MobileCache::masjidKey($masjidId, MobileCache::V1_PAGES_MENU),
                MobileCache::TTL_MEDIUM,
                function () {
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

                    return [
                        'menu_items' => $menuPages->toArray(),
                        'button_items' => $buttonPages->toArray(),
                    ];
                }
            );

            return response()->api(200, __('api.success'), $payload);

        } catch (\Exception $e) {
            return response()->api(500, \App\Support\Errors::publicMessage($e), null);
        }
    }
}
