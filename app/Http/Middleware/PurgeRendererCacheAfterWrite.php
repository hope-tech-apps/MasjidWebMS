<?php

namespace App\Http\Middleware;

use App\Support\Renderer\RendererPurgeScheduler;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `renderer.purge`: after a successful write that changes what an organisation's
 * public site shows, purge that organisation's pages from the renderer's cache, so the
 * save is live at once (docs/live-preview.md §4.6).
 *
 * Route middleware rather than a line in each controller so the list of writes that
 * purge is the list of route groups that carry it — pages, the section library, page
 * sections, theme, general settings, and what SectionContentBinder reads into pages
 * (details, about, donation link, contact reasons, forms, offerings, fee plans) — and a
 * new write in those groups purges without anyone remembering to.
 *
 * terminate() only QUEUES the purge (RendererPurgeScheduler): a burst of writes becomes
 * one first pass a few seconds later and one second pass after the last write. No PHP
 * worker waits on the renderer, and a failure can never turn a save into an error.
 * Reads (GET, HEAD, OPTIONS) and failed writes purge nothing.
 */
class PurgeRendererCacheAfterWrite
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        if (! $response->isSuccessful()) {
            return;
        }

        // The tenant this request was bound to (ResolveMasjidTenant ran before the
        // controller); the route id is the same value for a SuperAdmin.
        $organisationId = (int) (app(TenantContext::class)->get()
            ?? $request->route('masjid_id'));

        // Queue only: nothing here calls the renderer, so no PHP worker is held on it.
        RendererPurgeScheduler::afterSave($organisationId);
    }
}
