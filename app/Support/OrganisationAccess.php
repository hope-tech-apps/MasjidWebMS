<?php

namespace App\Support;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which organisation(s) a staff login belongs to, and what it can do there —
 * the read side of the layered access model for screens that list PEOPLE
 * (the SuperAdmin's Users & Access list and user details). The organisation's
 * own Team & Access screen (TeamController) is the per-organisation side.
 *
 * Access comes from users.type — the realm boundary — never from the
 * advisory masjid_user.role:
 *   MasjidAdmin -> admin         (everything the organisation has)
 *   LunchStaff  -> jummah_lunch  (the Friday lunch board only)
 *   Teacher     -> teacher       (their own classes)
 */
final class OrganisationAccess
{
    public const ACCESS_FOR_TYPE = [
        'MasjidAdmin' => 'admin',
        User::TYPE_LUNCH_STAFF => 'jummah_lunch',
        'Teacher' => 'teacher',
    ];

    /** Logins whose type IS their boundary; only the Team access endpoint changes it. */
    public const SCOPED_TYPES = [User::TYPE_LUNCH_STAFF, 'Teacher'];

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, list<array{masjid_id:int, name:string, access:?string, is_owner:bool, capabilities?:list<string>}>>
     *         keyed by user id; a user in no organisation is simply absent
     */
    public static function forUsers(Collection $users, bool $withCapabilities = false): array
    {
        $ids = $users->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $types = $users->pluck('type', 'id');

        // Archived organisations are included and flagged: they grant nothing today
        // (TenantResolver skips them), but a membership there still blocks an
        // access change (TeamController::update), so the list must show them.
        $owned = Masjid::withoutGlobalScopes()->whereIn('user_id', $ids)->get();
        $memberships = MasjidUser::whereIn('user_id', $ids)->get(['masjid_id', 'user_id']);

        $masjids = Masjid::withoutGlobalScopes()
            ->whereIn('id', $memberships->pluck('masjid_id')->merge($owned->pluck('id'))->unique()->all())
            ->get()
            ->keyBy('id');

        $out = [];

        $add = function (int $userId, int $masjidId, bool $isOwner) use (&$out, $masjids, $types, $withCapabilities) {
            $masjid = $masjids->get($masjidId);

            if (! $masjid) {
                return; // a membership pointing at a deleted organisation grants nothing
            }

            $row = $out[$userId][$masjidId] ?? [
                'masjid_id' => (int) $masjidId,
                'name' => $masjid->name,
                'access' => self::ACCESS_FOR_TYPE[$types[$userId] ?? ''] ?? null,
                'is_owner' => false,
                'archived' => $masjid->trashed(),
            ];
            $row['is_owner'] = $row['is_owner'] || $isOwner;

            if ($withCapabilities) {
                $row['capabilities'] = array_values(array_filter(
                    array_keys(config('capabilities', [])),
                    fn (string $key) => $masjid->hasCapability($key)
                ));
            }

            $out[$userId][$masjidId] = $row;
        };

        foreach ($owned as $masjid) {
            $add((int) $masjid->user_id, (int) $masjid->id, true);
        }

        foreach ($memberships as $membership) {
            $add((int) $membership->user_id, (int) $membership->masjid_id, false);
        }

        return array_map('array_values', $out);
    }
}
