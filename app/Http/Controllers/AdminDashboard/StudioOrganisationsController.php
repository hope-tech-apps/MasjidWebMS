<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Studio\SetWebsiteLocaleRequest;
use App\Http\Requests\Admin\Studio\StudioOrganisationPreviewRequest;
use App\Models\Masjid;
use App\Support\MobileCache;
use App\Support\Studio\OrganisationSnapshot;
use App\Support\Studio\PreviewInput;
use App\Support\Renderer\RendererPurgeScheduler;
use App\Support\Studio\StudioPreview;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Studio opening an organisation that already exists (Studio W2 S9, R14).
 * SuperAdmin-only through the `super` middleware on the studio route group.
 *
 * Read-only: the snapshot, and a preview that applies the changes being
 * considered to an in-memory copy. The writers Studio uses for a live
 * organisation are the bulk capability PATCH (S7), the theme screen's save,
 * brand-asset regeneration (S8), and the one writer here: the website
 * language (S12), which has no other screen.
 */
class StudioOrganisationsController extends Controller
{
    public function show(int $organisation_id)
    {
        return response()->json([
            'status' => 'success',
            'data' => OrganisationSnapshot::of(Masjid::findOrFail($organisation_id)),
        ], Response::HTTP_OK);
    }

    /**
     * A POST because it carries unsaved choices, not because it changes
     * anything: it writes nothing (StudioOrganisationPreviewTest reads the
     * query log).
     */
    public function preview(StudioOrganisationPreviewRequest $request, int $organisation_id)
    {
        $masjid = Masjid::findOrFail($organisation_id);

        return response()->json([
            'status' => 'success',
            'data' => StudioPreview::forInput(PreviewInput::fromMasjid($masjid, $request->overrides())),
        ], Response::HTTP_OK);
    }

    /**
     * The organisation's website language (Studio W2 S12). A per-organisation
     * decision on a LIVE site: once the renderer keeps the lookup's locale (S13),
     * `ar` on a lookup-resolved organisation turns its whole site right-to-left,
     * so Studio's confirm dialog says so and it needs the owner's go.
     *
     * Arabic is not gated on the starter-label table here: that table words
     * starter pages, and this changes the renderer's chrome, whose Arabic
     * catalogue exists. Empty clears the choice (null renders `en`).
     */
    public function setWebsiteLocale(SetWebsiteLocaleRequest $request, int $organisation_id)
    {
        $masjid = Masjid::findOrFail($organisation_id);
        $before = $masjid->website_locale;
        $after = $request->validated('locale');

        $masjid->update(['website_locale' => $after, 'updated_by' => Auth::id()]);

        // Committed (no transaction is open here). The renderer's cached pages
        // carry the old lang and dir; the lookup's KV record rewrites itself
        // when its value changes (W1 R5).
        RendererPurgeScheduler::afterSave((int) $masjid->id);

        // The TV config is cached per organisation and follows the website
        // language (the TV track reads it there), so drop the cached copy.
        MobileCache::flushMasjid((int) $masjid->id, MobileCache::TV_CONFIG);

        // Warning, not info: production runs LOG_LEVEL=warning, and this can
        // change a live site's language and direction.
        Log::warning('Organisation website language changed', [
            'masjid_id' => (int) $masjid->id,
            'before' => $before,
            'after' => $after,
            'actor_user_id' => Auth::id(),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => ['website_locale' => $after],
        ], Response::HTTP_OK);
    }
}
