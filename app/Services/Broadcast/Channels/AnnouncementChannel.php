<?php

namespace App\Services\Broadcast\Channels;

use App\Enums\BroadcastChannel;
use App\Models\Announcement;
use App\Models\Broadcast;
use App\Models\Masjid;
use App\Services\Broadcast\BroadcastChannelDriver;
use App\Services\Broadcast\ChannelResult;
use App\Support\MobileCache;

/**
 * The announcements feed channel (T-008).
 *
 * Writes an ORDINARY `announcements` row through the existing model and flushes
 * the same mobile cache key AdminDashboard\AnnouncementsController flushes. The
 * result is indistinguishable from an announcement typed on the announcements
 * screen: same table, same media collection, same public endpoint, same cache.
 * Nothing here reimplements the announcements feature — if it had, the two would
 * drift the first time somebody changed one of them.
 *
 * ## Why body maps onto BOTH details and text
 *
 * `announcements` requires `details` AND `text` (StoreAnnouncementRequest marks
 * both required), a split that predates this slice and that the iOS client
 * reads. The composer asks the admin for ONE body — demanding two nearly
 * identical paragraphs would recreate the retyping this feature exists to
 * abolish — so the one body fills both. `summary` gets a trimmed lead, which is
 * what a list row shows.
 *
 * ## The image is copied, not moved
 *
 * The uploaded file lives on the Broadcast (one upload, four possible
 * consumers). `preservingOriginal()` copies it into the announcement's own
 * `announcements` collection so the announcement owns its media exactly like
 * any other; deleting the broadcast later cannot blank an announcement that is
 * still on the feed.
 */
class AnnouncementChannel implements BroadcastChannelDriver
{
    public function channel(): BroadcastChannel
    {
        return BroadcastChannel::ANNOUNCEMENT;
    }

    public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
    {
        // Announcement predates the CRM and carries no BelongsToMasjid trait, so
        // masjid_id is set by hand here — the same way the announcements
        // controller does it (.claude/rules/tenant-scoping.md: do not retrofit
        // the trait onto pre-CRM models as a side effect).
        $announcement = Announcement::create([
            'masjid_id' => $masjid->id,
            'title' => $broadcast->title,
            'summary' => \Illuminate\Support\Str::limit($broadcast->body, 160),
            'details' => $broadcast->body,
            'text' => $broadcast->body,
            'start_date' => $broadcast->starts_on?->toDateString(),
            'end_date' => $broadcast->ends_on?->toDateString(),
            'link' => $broadcast->link,
        ]);

        if ($path = $broadcast->imagePath()) {
            $announcement->addMedia($path)
                ->preservingOriginal()
                ->toMediaCollection('announcements');
        }

        MobileCache::flushMasjid((int) $masjid->id, MobileCache::ANNOUNCEMENTS);

        return ChannelResult::sent(
            targetCount: 0,
            referenceId: $announcement->id,
            note: 'Published to the announcements feed (announcement #' . $announcement->id . ').',
        );
    }
}
