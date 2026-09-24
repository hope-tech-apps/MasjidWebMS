<?php

namespace App\Support\Renderer;

use App\Jobs\PurgeRendererCache;
use App\Jobs\PurgeRendererCacheAgain;
use Illuminate\Support\Facades\Cache;

/**
 * Turns a burst of saves into two purges of the renderer's page cache, off the request
 * path (docs/live-preview.md §4.6).
 *
 *   first pass   PurgeRendererCache, FIRST_PASS_DELAY after the first save of a burst,
 *                unique until it starts: a section reorder is one request per section,
 *                and they share one purge instead of each listing and deleting the same
 *                keys (the KV list/delete budget is account-wide).
 *   second pass  PurgeRendererCacheAgain, which TRAILS THE LAST SAVE: it waits until
 *                FOLLOW_UP_SECONDS after the most recent save before it purges, however
 *                many saves arrive meanwhile. KV's list is eventually consistent, so a
 *                page a visitor warmed seconds before a save can be missing from the first
 *                pass; by then it is listable.
 *
 * Nothing here calls the renderer. With purging not configured it does nothing at all.
 */
final class RendererPurgeScheduler
{
    public const FIRST_PASS_DELAY = 3;

    public static function afterSave(int $organisationId): void
    {
        if ($organisationId < 1 || ! RendererConfig::purgeEnabled()) {
            return;
        }

        Cache::put(self::lastSaveKey($organisationId), now()->getTimestamp(), now()->addHour());

        PurgeRendererCache::dispatch($organisationId)
            ->delay(now()->addSeconds(self::FIRST_PASS_DELAY));
        PurgeRendererCacheAgain::dispatch($organisationId)
            ->delay(now()->addSeconds(PurgeRendererCacheAgain::FOLLOW_UP_SECONDS));
    }

    /** Unix time of the organisation's most recent save, or null when none is recorded. */
    public static function lastSaveAt(int $organisationId): ?int
    {
        $at = Cache::get(self::lastSaveKey($organisationId));

        return is_int($at) ? $at : null;
    }

    private static function lastSaveKey(int $organisationId): string
    {
        return "renderer-purge-last-save:{$organisationId}";
    }
}
