<?php

namespace App\Support;

use App\Models\MasjidUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stamps `masjid_user.last_seen_at`: when this person last OPENED this organisation.
 *
 * Owner, 2026-09-29: a shared teacher's school should see "when they last opened the
 * specific school", not the last time they logged in anywhere. See the migration for
 * why the column lives on the membership.
 *
 * The constraints this class exists to hold, each pinned by StaffLastSeenTest:
 *
 *  - ONE conditional statement, not a read then a write:
 *      UPDATE masjid_user SET last_seen_at = :now
 *      WHERE masjid_id = ? AND user_id = ? AND (last_seen_at IS NULL OR last_seen_at < :now - 5 min)
 *    On the QUERY BUILDER, not the model, so no model event, observer, `updated_at`
 *    touch or provenance write can fire on a membership row, and a burst of requests
 *    is throttled by the database rather than by a race between two reads.
 *  - It may NEVER fail or slow a request: any throwable is caught and logged at
 *    WARNING (Log::info is invisible on production, where LOG_LEVEL is warning), so a
 *    missing column or a locked row costs a log line, not a 500.
 *  - Only a membership that EXISTS is touched. The resolver's ownership fallback is an
 *    unsaved MasjidUser (see TenantResolver::membershipFromOwnership) and a read path
 *    must not backfill authorisation rows; an UPDATE that matches nothing is exactly
 *    right for it.
 *  - The caller decides WHEN (ResolveMasjidTenant, inline, on a response below 400).
 *    It is deliberately not run from terminable middleware: the production transport
 *    never calls terminate(), which is how the /features counter was once lost.
 */
final class MembershipSeen
{
    /** A membership is stamped at most this often. */
    public const THROTTLE_MINUTES = 5;

    /**
     * The one way a screen reads it: user_id => when that person last opened THIS
     * organisation, from THIS organisation's membership rows only. A school's admins
     * must never see another school's value for a shared teacher, so the masjid filter
     * lives here, once, instead of in every payload that shows the column (and the
     * column is hidden on the model, so a serialised membership cannot carry it out).
     *
     * @param  list<int>|null  $userIds
     * @return Collection<int, Carbon|null>
     */
    public static function forOrganisation(int $masjidId, ?array $userIds = null): Collection
    {
        return MasjidUser::query()
            ->where('masjid_id', $masjidId)
            ->when($userIds !== null, fn ($query) => $query->whereIn('user_id', $userIds))
            ->pluck('last_seen_at', 'user_id');
    }

    public static function iso(?Carbon $seen): ?string
    {
        return $seen?->toIso8601String();
    }

    public static function touch(MasjidUser $membership): void
    {
        if (! $membership->exists) {
            return;
        }

        try {
            $now = now();

            DB::table('masjid_user')
                ->where('masjid_id', $membership->masjid_id)
                ->where('user_id', $membership->user_id)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<', $now->copy()->subMinutes(self::THROTTLE_MINUTES));
                })
                ->update(['last_seen_at' => $now]);
        } catch (\Throwable $e) {
            Log::warning('masjid_user.last_seen_at could not be stamped; the request was not affected.', [
                'masjid_id' => $membership->masjid_id,
                'user_id' => $membership->user_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
