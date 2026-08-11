<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Broadcast;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Support\Facades\Cache;

/**
 * The signage board payload the tvOS app fetches (T-008).
 *
 * docs/recon-2026-08-11.md records `MasjidTV/Data/SignageStore.swift` asking for
 * a board endpoint that had never been built, and silently keeping
 * `TVConfig.defaults` on every failed fetch. This is that endpoint, in the shape
 * the rest of the mobile API uses: unauthenticated, masjid_id in the URL, the
 * legacy `{status, data}` envelope, and a short cache like every other read here.
 *
 * ## Scoping
 *
 * routes/api.php never runs the tenant middleware, so TenantContext is UNBOUND
 * and the BelongsToMasjid global scope adds nothing — exactly as intended for a
 * public route (.claude/rules/tenant-scoping.md). Isolation therefore comes from
 * the explicit `masjid_id` filter below, the same contract every other mobile
 * controller honours.
 *
 * ## What it returns
 *
 * Only broadcasts whose SIGNAGE channel was selected AND succeeded, and whose
 * display window is open right now — `Broadcast::scopeLiveOnSignage`. A notice
 * an admin sent to push alone never reaches the board, and one whose signage
 * delivery failed does not appear as though it had worked.
 */
class SignageController extends Controller
{
    public function index($masjid_id)
    {
        $payload = Cache::remember(
            MobileCache::masjidKey((int) $masjid_id, MobileCache::SIGNAGE),
            MobileCache::TTL_SHORT,
            function () use ($masjid_id) {
                $masjid = Masjid::findOrFail($masjid_id);

                return Broadcast::query()
                    ->where('masjid_id', $masjid->id)
                    ->liveOnSignage()
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn (Broadcast $b) => [
                        'id' => $b->id,
                        'title' => $b->title,
                        'body' => $b->body,
                        'link' => $b->link,
                        // Nullable rather than an empty string: the client
                        // decides whether to render an image slot at all.
                        'image_url' => $b->imageUrl(),
                        'starts_on' => $b->starts_on?->toDateString(),
                        'ends_on' => $b->ends_on?->toDateString(),
                        'published_at' => $b->dispatched_at?->toIso8601String(),
                    ])
                    ->values();
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $payload,
        ]);
    }
}
