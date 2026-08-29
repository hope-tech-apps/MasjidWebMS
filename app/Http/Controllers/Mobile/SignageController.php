<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Broadcast;
use App\Models\Masjid;
use App\Support\MobileCache;
use Illuminate\Support\Carbon;
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

                $broadcasts = Broadcast::query()
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

                if ($broadcasts->isNotEmpty()) {
                    return $broadcasts;
                }

                // FALLBACK: the masjid's own live announcements.
                //
                // Measured on 2026-08-21 from a photograph of the board in
                // Burlington's lobby: the screen read "No announcements" while the
                // masjid had TEN current announcements. Nothing was broken — the
                // board reads BROADCASTS, and Burlington has never created one.
                // Staff had been posting Announcements, which is the noun the admin
                // dashboard puts in front of them, and reasonably expected the
                // screen on their own wall to show them.
                //
                // The broadcast gate above is kept exactly as it was and still wins
                // when it has anything to say: a notice sent to push alone must not
                // reach the board, and that distinction is deliberate. This only
                // decides what an EMPTY board falls back to, and the honest answer
                // is "the announcements this masjid is already publishing" rather
                // than a blank screen.
                //
                // No disclosure change: these are already public on the website and
                // in the app. The date window is the announcement's own, so an
                // expired notice stays off the board.
                $today = now()->toDateString();

                return Announcement::query()
                    ->where('masjid_id', $masjid->id)
                    ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn (Announcement $a) => [
                        'id' => $a->id,
                        'title' => $a->title,
                        // `summary` is the short line the board has room for;
                        // `details`/`text` are the long body the app renders.
                        'body' => $a->summary ?: ($a->details ?: $a->text),
                        'link' => $a->link,
                        // Deliberately NOT filtered on the presence of an image,
                        // unlike the mobile announcements feed: that filter exists
                        // because a shipped iOS build declares `image`
                        // non-optional. This payload's `image_url` is nullable by
                        // contract and the board renders text without a picture.
                        'image_url' => $a->getFirstMediaUrl('announcements') ?: null,
                        'starts_on' => $a->start_date ? Carbon::parse($a->start_date)->toDateString() : null,
                        'ends_on' => $a->end_date ? Carbon::parse($a->end_date)->toDateString() : null,
                        'published_at' => $a->created_at?->toIso8601String(),
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
