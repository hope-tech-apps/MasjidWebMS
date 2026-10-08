<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Group — the second scoping level of the core: org -> group -> member.
 *
 * ONE primitive for every vertical (DECISIONS.md 2026-08-10): a School's
 * classroom, a Masjid's halaqa or weekend-school circle, a Community org's
 * volunteer team. A group belongs to exactly one organization.
 *
 * Tenant-scoped: BelongsToMasjid supplies the masjid_id global scope, the
 * server-derived creating hook, and the masjid() relationship. masjid_id stays
 * fillable so system/super code can set it while UNBOUND; a bound tenant always
 * overrides it. See .claude/rules/tenant-scoping.md.
 *
 * Admin-facing naming NEVER comes from this class. What a group is CALLED is the
 * tenant's vocabulary — $masjid->term('groups') yields "Halaqat" / "Classrooms" /
 * "Teams" — so nothing here or in the controllers may hardcode "Classroom".
 * See .claude/rules/verticals.md.
 */
class Group extends Model
{
    use HasFactory, SoftDeletes, BelongsToMasjid;

    /**
     * What KIND of group this is. A discriminator for UI and for later
     * vertical-specific behaviour, never an authorization check.
     *
     * Deliberately PHP constants rather than a DB enum, exactly like
     * Masjid::ORG_TYPES: adding a kind must not mean ALTER TABLE on a live
     * table. See the create_groups_table migration and
     * .claude/rules/migrations.md.
     */
    public const KIND_GENERAL = 'general';
    public const KIND_CLASS = 'class';
    public const KIND_HALAQA = 'halaqa';
    public const KIND_TEAM = 'team';

    public const KINDS = [
        self::KIND_GENERAL,
        self::KIND_CLASS,
        self::KIND_HALAQA,
        self::KIND_TEAM,
    ];

    /**
     * How a class's points read (T-003.2). PHP constants, never a DB enum
     * (.claude/rules/migrations.md). `running` is the stored default AND what
     * NULL reads as, so every class that existed before the column does exactly
     * what it always did.
     */
    public const POINTS_PERIOD_RUNNING = 'running';
    public const POINTS_PERIOD_WEEKLY = 'weekly';

    public const POINTS_PERIODS = [
        self::POINTS_PERIOD_RUNNING,
        self::POINTS_PERIOD_WEEKLY,
    ];

    protected $fillable = [
        'masjid_id',
        'name',
        'slug',
        'kind',
        // Office-chosen display order; NULL sorts last, then by name. See
        // scopeInDisplayOrder and the 2026-09-21 migration.
        'position',
        'description',
        'is_active',
        'starts_on',
        'ends_on',
        // How far through the qāʿidah this CLASS is working. The stage is a
        // property of the room, not of thirty children who would each have
        // to carry a number that agrees with it. Null = the first stage.
        'arabic_stage',
        // How this class's points are SHOWN: null/'running' = one running total,
        // 'weekly' = each week on its own with the running history kept
        // (T-003.2). A view choice: no award row is ever changed by it.
        'points_period',
    ];

    protected $hidden = ['subject_seed_grades', 'class_subjects_initialized_at'];

    protected $attributes = [
        'kind' => self::KIND_GENERAL,
    ];

    protected function casts(): array
    {
        return [
            'subject_seed_grades' => 'array',
            'class_subjects_initialized_at' => 'datetime',
            'is_active' => 'boolean',
            'position' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * The stored kind, degraded to `general` when unrecognized — the same
     * defensive read as Masjid::orgType(). An unknown value must never let a
     * group behave as a kind nobody granted it.
     */
    public function kind(): string
    {
        $kind = $this->attributes['kind'] ?? null;

        return in_array($kind, self::KINDS, true) ? $kind : self::KIND_GENERAL;
    }

    /**
     * Is this a CLASS: a group whose members are students a school keeps
     * records about? Today, kind `class` only.
     *
     * The one answer to that question for the features that are about students
     * and nobody else: moving a student to another class (App\Support\RosterMove),
     * a date of birth and the age on a roster (App\Support\StudentAge), and,
     * with them, what the office roster offers. Read through `kind()`, so an
     * unrecognised stored kind is not a class.
     *
     * WIDENING THIS to another kind (a ḥalaqa, say) is this line ON THE SERVER,
     * and the office roster follows by itself: it reads `meta.teaches_students`.
     * Three places in the browser still compare the kind `class` themselves and
     * must change with it, or the server would allow what the screens do not
     * offer: the Move dialog's list of classes (`classOptions` in
     * core/helpers/rosterMove.ts, and the `kind=class` query in
     * groupsStore.fetchClassesForMove), and the teacher's student sheet
     * (`:is-class` in TeacherClass.vue), which would show no age line beside a
     * row that shows an age.
     */
    public function teachesStudents(): bool
    {
        return $this->kind() === self::KIND_CLASS;
    }

    /**
     * Force-deleting a group must reach the disk (T-005b).
     *
     * `group_posts`, `group_post_attachments`, `group_threads` and the photos
     * sent in them cascade off `groups` at the DB level, and a DB cascade fires
     * NO model events — so without this hook a
     * hard-deleted group would leave every classroom photograph it ever carried
     * on disk forever, unreferenced and unpurgeable. See
     * .claude/rules/private-uploads.md.
     *
     * Only on a FORCE delete. The ordinary destroy path soft-deletes on purpose:
     * a mis-click must not destroy a roster, and it must not destroy the class
     * story either. Bytes go when the retention purge says they go.
     */
    /**
     * The order an office chose (`position`, lowest first), then everything it
     * has not placed, alphabetically. Every screen that lists a school's classes
     * uses this, so a parent, a teacher and the office see one order.
     * `position IS NULL` first sorts unplaced groups last on MySQL and SQLite
     * alike, whose NULL ordering otherwise disagrees.
     */
    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query->orderByRaw('position IS NULL')
            ->orderBy('position')
            ->orderBy('name')
            ->orderBy('id');
    }

    protected static function booted(): void
    {
        static::created(function (self $group): void {
            if ($group->teachesStudents() && \App\Support\SchoolSettings::classSubjects(\App\Support\SchoolSettings::org($group->masjid_id))) {
                \Illuminate\Support\Facades\DB::transaction(function () use ($group): void {
                    Masjid::whereKey($group->masjid_id)->lockForUpdate()->firstOrFail();
                    \App\Support\ClassSubjectInitializer::initializeGroup($group);
                });
            }
        });

        static::deleting(function (self $group): void {
            if (! $group->isForceDeleting()) {
                return;
            }

            $group->posts()->withTrashed()->get()->each->purge();

            // Resource files go the same way, and MUST go through the model: a
            // database cascade off `groups` fires no model events, so without
            // this line every uploaded worksheet stays on disk forever,
            // unreferenced and unpurgeable — the exact failure the paragraph
            // above describes for post images.
            $group->resources()->get()->each->delete();

            // Photos sent in conversations, likewise: purge() on each thread
            // runs GroupThread's hook, which removes them through the model
            // before the cascade off `groups` takes the rows.
            $group->threads()->withTrashed()->get()->each->purge();
        });
    }

    /** Every membership row in this group, guardian edges included. */
    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMembership::class);
    }

    /**
     * This group's PRIVATE activity feed (T-005b). Never a public surface: who
     * may read it is decided per request by App\Support\GroupAudience.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(GroupPost::class);
    }

    /**
     * Files kept for this class. Private bytes: see GroupResource, and note that
     * booted() above deletes these THROUGH THE MODEL so the byte-removal hook
     * fires.
     */
    public function resources(): HasMany
    {
        return $this->hasMany(GroupResource::class);
    }

    /** Work set for this class — the gradebook's parent rows. */
    public function assignments(): HasMany
    {
        return $this->hasMany(ClassAssignment::class);
    }

    /** What this class is planned to cover, one row per day. */
    public function lessonPlans(): HasMany
    {
        return $this->hasMany(LessonPlan::class);
    }

    /**
     * This group's messaging threads (T-005c) — the teacher <-> parent channel.
     * Like the feed, never a public surface: who may read each thread is
     * decided per request by App\Support\GroupAudience, which is also why there
     * is no eager "conversations" payload on the group itself.
     */
    public function threads(): HasMany
    {
        return $this->hasMany(GroupThread::class);
    }

    /**
     * New conversations written and waiting for their time (T-002.4). Not threads:
     * see GroupMessageSchedule. Rows only, no bytes, so the DB cascade on
     * `group_message_schedules.group_id` is the whole teardown.
     */
    public function messageSchedules(): HasMany
    {
        return $this->hasMany(GroupMessageSchedule::class);
    }

    /**
     * This group's behaviour/recognition records (T-013).
     *
     * Deliberately NOT a surface anyone reads whole: an award is private to the
     * group's leaders, the student, and that student's own guardians, and there
     * is no class-wide tally by design. Every read goes through
     * App\Support\GroupAudience::readableAwardsQuery(), which constrains this
     * relation per caller — this accessor exists for leaders' listings and for
     * the retention sweep, not as a payload. See .claude/rules/groups.md.
     */
    public function behaviorAwards(): HasMany
    {
        return $this->hasMany(BehaviorAward::class);
    }

    /**
     * This ḥalaqa's ḥifẓ recitation records (T-014).
     *
     * Private on exactly the same terms as the behaviour awards above: an entry
     * reaches the ḥalaqa's leaders, the student, and that student's own
     * guardians, and every read goes through
     * App\Support\GroupAudience::readableHifzQuery(), which constrains this
     * relation per caller. Not a payload — this accessor exists for leaders'
     * listings and for the per-student derivation, never to be serialized with
     * a group. See .claude/rules/groups.md.
     */
    public function hifzEntries(): HasMany
    {
        return $this->hasMany(HifzEntry::class);
    }

    /**
     * The people in this group. Guardians appear here too (they hold a
     * membership); read the pivot's `role` to tell them apart.
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'group_memberships')
            ->withPivot(['role', 'guardian_of_contact_id', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * The staff Users who lead this class (`group_staff`).
     *
     * Distinct from contacts()/memberships(): those are Contacts (students,
     * guardians, legacy Contact "leaders"); this is the LOGIN that teaches the
     * class. `->using(GroupStaff::class)` so the pivot carries BelongsToMasjid —
     * but attach() must still pass masjid_id explicitly (see GroupStaff).
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\User::class, 'group_staff')
            ->using(GroupStaff::class)
            ->withPivot(['role', 'assigned_by_user_id', 'assigned_at'])
            ->withTimestamps();
    }

    /**
     * The "only my classes" filter: groups this staff user leads.
     *
     * Drives every teacher read (Group::query()->ledBy($teacherId)->get()). The
     * whereHas subquery stays inside the parent's bound tenant, and group_staff
     * itself is tenant-scoped, so a leader row in another masjid cannot widen it.
     */
    public function scopeLedBy($query, int $userId)
    {
        return $query->whereHas('staff', fn ($q) => $q->where('users.id', $userId));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }

    public function arabicLetterProgress(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ArabicLetterProgress::class);
    }

    /**
     * The stored points period, degraded to `running` when null or unrecognised,
     * the same defensive read as kind(). An unknown value must never make a class
     * hide its running total.
     */
    public function pointsPeriod(): string
    {
        $period = $this->attributes['points_period'] ?? null;

        return in_array($period, self::POINTS_PERIODS, true) ? $period : self::POINTS_PERIOD_RUNNING;
    }

    /** Does this class read its points one week at a time? */
    public function usesWeeklyPoints(): bool
    {
        return $this->pointsPeriod() === self::POINTS_PERIOD_WEEKLY;
    }

    /** Never null: a group with no stage set is on the first one. */
    public function arabicStage(): string
    {
        return \App\Support\Arabic\ArabicCurriculum::normaliseStage($this->arabic_stage);
    }

}
