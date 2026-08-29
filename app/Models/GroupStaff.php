<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A staff User's assignment to a class (`group_staff`).
 *
 * The sole authority for teacher standing on a login. It is a Pivot so it can back
 * `Group::staff()` / `User::groupsLed()`, but it is also queried directly (by
 * `GroupAudience::leaderGroupIdsFor()`), so it carries `BelongsToMasjid` — the tenant
 * global scope hides another masjid's staff rows from every such query, and the
 * `masjid()` relation and the tenant creating-hook come with it.
 *
 * ## The attach() footgun
 *
 * The `BelongsToMasjid` creating hook stamps `masjid_id` from the bound tenant — but
 * ONLY on a model `create()`. `attach()` / `sync()` insert through the query builder
 * and never instantiate the model, so the hook does not fire even with
 * `->using(self::class)`. A row attached without an explicit `masjid_id` therefore
 * lands with `masjid_id = NULL/0` and the tenant scope silently hides it — the teacher
 * would see zero classes. Every attach MUST pass masjid_id explicitly:
 *
 *   $group->staff()->attach($userId, [
 *       'masjid_id' => $group->masjid_id,
 *       'role' => GroupStaff::ROLE_TEACHER,
 *       'assigned_by_user_id' => Auth::id(),
 *       'assigned_at' => now(),
 *   ]);
 *
 * `GroupStaffTenantIsolationTest` pins this.
 */
class GroupStaff extends Pivot
{
    use BelongsToMasjid;

    protected $table = 'group_staff';

    /** The table has an auto-increment id, unlike a bare many-to-many pivot. */
    public $incrementing = true;

    public const ROLE_TEACHER = 'teacher';

    /** The kinds of staff a class can have. Teachers are the only kind today. */
    public const ROLES = [
        self::ROLE_TEACHER,
    ];

    /**
     * `assigned_by_user_id` is deliberately NOT fillable — it is a server-derived
     * audit field set from Auth::id() at the assignment call site, never from a
     * client payload.
     */
    protected $fillable = [
        'masjid_id',
        'group_id',
        'user_id',
        'role',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }
}
