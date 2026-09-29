<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Auth;

/**
 * A staff User's assignment to a class (`group_staff`).
 *
 * The sole authority for teacher standing on a login. It is a Pivot so it can back
 * `Group::staff()` / `User::groupsLed()`, but it is also queried directly (by
 * `GroupAudience::leaderGroupIdsFor()`), so it carries `BelongsToMasjid` — the tenant
 * global scope hides another masjid's staff rows from every such query, and the
 * `masjid()` relation and the tenant creating-hook come with it.
 *
 * ## How attach() writes a row (Laravel 12)
 *
 * Because `Group::staff()` / `User::groupsLed()` are `->using(self::class)`, attach()
 * and sync() first pass the extra attributes through `(new GroupStaff)->fill()`
 * (InteractsWithPivotTable::castAttributes), then save a GroupStaff instance, so the
 * model's casts and creating hooks DO run. Two consequences:
 *
 * - An attribute outside `$fillable` is silently dropped by that fill(). This is why
 *   `assigned_by_user_id`, once passed at the call site, was never stored (NULL on
 *   rows written through TeachersController until 2026-09-29). The actor is now set
 *   by the `creating` hook in booted(), never by a caller.
 * - Still pass `masjid_id` explicitly on every attach: the tenant hook only stamps it
 *   when a tenant is bound, and a row with no `masjid_id` is hidden by the tenant
 *   scope (the teacher would see zero classes). `GroupStaffTenantIsolationTest` pins it.
 *
 *   $group->staff()->attach($userId, [
 *       'masjid_id' => $group->masjid_id,
 *       'role' => GroupStaff::ROLE_TEACHER,
 *       'assigned_at' => now(),
 *   ]);
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

    /*
     * What a teacher may teach in one class (`subjects`, owner 2026-09-21). NULL
     * means ALL of them — every assignment made before subjects existed, and a
     * full-time school's teacher who has the whole class.
     *
     * Two subjects own a tab of their own, and that tab is refused to a teacher
     * who does not teach it, on the server (`teacher.teaches:`), not just hidden:
     * Arabic owns the letters tracker and the daily Arabic notes; Qur'an owns
     * hifdh. Islamic Studies owns neither. Everything else in a class — roster,
     * attendance, points, class story, messages, files, lesson plans, grades,
     * reports — belongs to whoever teaches the class at all.
     */
    public const SUBJECT_QURAN = 'quran';

    public const SUBJECT_ARABIC = 'arabic';

    public const SUBJECT_ISLAMIC_STUDIES = 'islamic_studies';

    public const SUBJECTS = [
        self::SUBJECT_QURAN,
        self::SUBJECT_ARABIC,
        self::SUBJECT_ISLAMIC_STUDIES,
    ];

    public const SUBJECT_LABELS = [
        self::SUBJECT_QURAN => "Qur'an",
        self::SUBJECT_ARABIC => 'Arabic',
        self::SUBJECT_ISLAMIC_STUDIES => 'Islamic Studies',
    ];

    /**
     * Whether this assignment covers `$subject`. NULL (or an empty list, which
     * nothing writes, treated the same so it can never mean "nothing") is all.
     */
    public function teaches(string $subject): bool
    {
        $subjects = $this->subjects;

        return $subjects === null || $subjects === [] || in_array($subject, $subjects, true);
    }

    /**
     * `assigned_by_user_id` is deliberately NOT fillable: it is the signed-in user
     * at the moment the row is created, set by the `creating` hook in booted(),
     * never a value from a payload or a caller.
     */
    protected $fillable = [
        'masjid_id',
        'group_id',
        'user_id',
        'role',
        'subjects',
        'assigned_at',
    ];

    /**
     * Who made the assignment: whoever is signed in when the row is created. A row
     * written with nobody signed in (a console command, a seeder) keeps NULL rather
     * than a guess. Set here, not at call sites, because attach() drops it there.
     */
    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            $row->assigned_by_user_id = Auth::id();
        });
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'subjects' => 'array',
        ];
    }
}
