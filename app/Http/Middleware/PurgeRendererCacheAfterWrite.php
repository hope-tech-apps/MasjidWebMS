<?php

namespace App\Http\Middleware;

use App\Jobs\PurgeRendererCacheAgain;
use App\Support\Renderer\RendererCachePurge;
use App\Support\Renderer\RendererConfig;
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
 * sections, theme and general settings — and a new write in those groups purges
 * without anyone remembering to.
 *
 * The work happens in terminate(): after the response has been sent, so an admin never
 * waits on the renderer, and a failure can never turn a save into an error. A second
 * pass follows on the queue (PurgeRendererCacheAgain) for pages KV had not yet listed.
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

        if ($organisationId < 1 || ! RendererConfig::purgeEnabled()) {
            return;
        }

        app(RendererCachePurge::class)->purge($organisationId);

        // KV lists are eventually consistent: a page warmed elsewhere seconds before this
        // save can be missing from the listing the first pass read. See the job.
        PurgeRendererCacheAgain::dispatch($organisationId)
            ->delay(now()->addSeconds(PurgeRendererCacheAgain::FOLLOW_UP_SECONDS));
    }
}
