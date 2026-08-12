<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PageResource;
use App\Models\Page;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * NOTE on the `catch (HttpResponseException)` re-throws below.
 *
 * `Page::filterByMasjid()` REFUSES a request that names no tenant, and it does
 * so by throwing an HttpResponseException carrying a 400 (see
 * App\Traits\SearchableTrait). Every method here wraps its query in a blanket
 * `catch (\Exception)`, and HttpResponseException is an \Exception — so without
 * these re-throws the refusal is swallowed and reissued as a 500 by index/menu,
 * and as a **404** by show(), which would read as "no such page" for what is
 * really "you did not say which organisation". Caught by
 * PublicApiTenantScopingTest, which asserts 400 on all three.
 *
 * HttpResponseException carries its own response and is Laravel's way of saying
 * "this answer is final"; it must always reach the handler unaltered.
 */
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

        } catch (HttpResponseException $e) {
            throw $e;
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

        } catch (HttpResponseException $e) {
            throw $e;
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

        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->api(500, \App\Support\Errors::publicMessage($e), null);
        }
    }
}
