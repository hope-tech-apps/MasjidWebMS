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
     * `assigned_by_user_id` is deliberately NOT fillable — it is a server-derived
     * audit field set from Auth::id() at the assignment call site, never from a
     * client payload.
     */
    protected $fillable = [
        'masjid_id',
        'group_id',
        'user_id',
        'role',
        'subjects',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'subjects' => 'array',
        ];
    }
}
