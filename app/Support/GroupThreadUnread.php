<?php

namespace App\Support;

use App\Models\GroupMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How many messages a STAFF user has not yet seen, per conversation and per class.
 *
 * One definition, one query, used by every number a staff screen shows: the
 * thread list's `unread_count` and `meta.unread_total`, the teacher class
 * payload's `unread_messages` (and My Classes, computed ONCE for all the
 * teacher's classes), and the admin group's `unread_messages`.
 *
 * A message is unread for user U when ALL of these hold:
 *
 *   - somebody else wrote it (a parent, a co-teacher, an account since deleted);
 *     your own words are never news to you;
 *   - its conversation is live: a soft-deleted thread never counts. A closed one
 *     still does. A scheduled conversation that has not been sent is not a
 *     `group_messages` row at all, so it can never count;
 *   - it is newer than U's bookmark on that thread (`group_thread_reads`): by
 *     message id when the bookmark has one, by time for a bookmark written before
 *     that column existed;
 *   - or U has NO bookmark yet, and it was written at or after
 *     `config('groups.messaging.unread_since')`. Without a floor every message
 *     the school ever exchanged would read as unread the first day. A null or
 *     absent floor means "no floor".
 *
 * THIS CLASS NEVER WRITES. Staff bookmarks are shown to families as read receipts
 * (GroupMessageSignals), so a baseline row seeded here would tell every parent
 * "seen by the teacher" about messages nobody opened.
 *
 * The query runs through GroupMessage, so the tenant scope constrains the base
 * table. The joined thread and bookmark rows are tied to the message's own
 * masjid_id as well, so a mismatched row in another organisation can never join.
 */
final class GroupThreadUnread
{
    /**
     * Unread counts for every live thread of the given classes.
     *
     * @param  list<int>  $groupIds
     * @param  Builder|null  $readable  when given, only threads in this query count (the admin
     *                                  screen passes GroupAudience::readableThreadsQuery so the
     *                                  badge equals what the list shows)
     * @param  list<int>|null  $threadIds  narrow to these threads (the page being listed)
     * @return array<int, array<int, int>>  group_id => [thread_id => unread]; absent = 0
     */
    public static function byThread(int $userId, array $groupIds, ?Builder $readable = null, ?array $threadIds = null): array
    {
        if ($groupIds === [] || $threadIds === []) {
            return [];
        }

        $rows = self::unreadQuery($userId)
            ->whereIn('t.group_id', $groupIds)
            ->when($threadIds !== null, fn (Builder $q) => $q->whereIn('t.id', $threadIds))
            ->when($readable !== null, fn (Builder $q) => $q->whereIn('t.id', $readable->clone()->select('group_threads.id')->toBase()))
            ->groupBy('t.group_id', 't.id')
            ->select('t.group_id', 't.id as thread_id', DB::raw('count(*) as unread'))
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->group_id][(int) $row->thread_id] = (int) $row->unread;
        }

        return $counts;
    }

    /**
     * Unread messages per class: group_id => total. One query for all the ids.
     *
     * @param  list<int>  $groupIds
     * @return array<int, int>  every requested id is present, 0 when nothing is unread
     */
    public static function byGroup(int $userId, array $groupIds, ?Builder $readable = null): array
    {
        $totals = array_fill_keys($groupIds, 0);

        foreach (self::byThread($userId, $groupIds, $readable) as $groupId => $threads) {
            $totals[$groupId] = array_sum($threads);
        }

        return $totals;
    }

    /**
     * Has somebody else written something in this thread that U has not seen yet?
     *
     * The write path's question (a reply may only advance U's bookmark when this is
     * false), answered by the SAME predicate the counts use.
     */
    public static function hasUnseenFromOthers(int $userId, int $threadId): bool
    {
        return self::unreadQuery($userId)->where('t.id', $threadId)->exists();
    }

    /** The floor for a user with no bookmark, in the database's own clock; null = none. */
    private static function floor(): ?string
    {
        $floor = config('groups.messaging.unread_since');

        if ($floor === null || $floor === '') {
            return null;
        }

        // The config literal is UTC; rows are stored in the application timezone.
        return Carbon::parse((string) $floor, 'UTC')
            ->setTimezone((string) config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }

    /** Messages U has not seen, joined to their live thread and U's bookmark. */
    private static function unreadQuery(int $userId): Builder
    {
        $floor = self::floor();

        return GroupMessage::query()
            ->join('group_threads as t', function ($join): void {
                $join->on('t.id', '=', 'group_messages.group_thread_id')
                    ->on('t.masjid_id', '=', 'group_messages.masjid_id')
                    ->whereNull('t.deleted_at');
            })
            ->leftJoin('group_thread_reads as r', function ($join) use ($userId): void {
                $join->on('r.group_thread_id', '=', 't.id')
                    ->on('r.masjid_id', '=', 'group_messages.masjid_id')
                    ->where('r.user_id', '=', $userId);
            })
            ->where(function (Builder $q) use ($userId): void {
                $q->whereNull('group_messages.author_user_id')
                    ->orWhere('group_messages.author_user_id', '<>', $userId);
            })
            ->where(function (Builder $q) use ($floor): void {
                // With a bookmark: newer than it.
                $q->where(function (Builder $marked): void {
                    $marked->whereNotNull('r.id')->where(function (Builder $newer): void {
                        $newer->where(function (Builder $byId): void {
                            $byId->whereNotNull('r.last_read_message_id')
                                ->whereColumn('group_messages.id', '>', 'r.last_read_message_id');
                        })->orWhere(function (Builder $byTime): void {
                            $byTime->whereNull('r.last_read_message_id')->where(function (Builder $t): void {
                                $t->whereNull('r.last_read_at')
                                    ->orWhereColumn('group_messages.created_at', '>', 'r.last_read_at');
                            });
                        });
                    });
                })
                // Without one: only what arrived since the floor.
                    ->orWhere(function (Builder $unmarked) use ($floor): void {
                        $unmarked->whereNull('r.id');

                        if ($floor !== null) {
                            $unmarked->where('group_messages.created_at', '>=', $floor);
                        }
                    });
            });
    }
}
