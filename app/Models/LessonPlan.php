<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a class is going to cover on one day, in one subject, for one grade. One
 * per class, per day, per subject, per grade
 * (`lesson_plan_class_day_subject_grade_unique`): a combined-grade homeroom
 * teaches several subjects a day, and the same subject to each of its grades,
 * and plans each. A plan with no subject is the day's general plan, for a
 * school with no pacing guide. A plan with no grade is its own key in the
 * index; whether it is the same lesson as a graded plan of its subject depends
 * on the class's roster (LessonPlanController::ofGrade).
 *
 * Carries the school's own lesson-plan template (see the
 * add_lesson_plan_template_to_lesson_plans_table migration for what it
 * deliberately does NOT store, and why).
 *
 * `body` is the template's ACTIVITIES field and is the one required section.
 *
 * Files under Activities are links to `group_resources` (see `attachments()`); the
 * plan itself owns no bytes and this table is never altered for them.
 *
 * DELIBERATELY NO `membership()` RELATION. GroupAudience reaches for that name
 * to decide who may read a record ABOUT A CHILD; a lesson plan is about a room,
 * and giving this model the relation would make "a plan about a child"
 * expressible where today it is refusable. That is also why the template's
 * "students needing follow-up" field is absent: it would be exactly such a
 * record, hidden in a note about a room.
 */
class LessonPlan extends Model
{
    use \App\Models\Concerns\HasClassSubjectWork;

    use HasFactory, BelongsToMasjid;

    /**
     * The template's Teaching Methods checkboxes.
     *
     * PHP constants, not a DB enum: adding a method must be a write, never an
     * ALTER TABLE on a live table. Stored as a json array, validated with
     * Rule::in on `teaching_methods.*` — the shape this codebase already uses
     * for a fixed set of which many may be chosen.
     */
    public const METHOD_MODELING = 'modeling';
    public const METHOD_GUIDED_PRACTICE = 'guided_practice';
    public const METHOD_COOPERATIVE = 'cooperative_learning';
    public const METHOD_INQUIRY = 'inquiry_discussion';
    public const METHOD_HANDS_ON = 'hands_on_activity';
    public const METHOD_STORYTELLING = 'storytelling';
    public const METHOD_OTHER = 'other';

    public const TEACHING_METHODS = [
        self::METHOD_MODELING,
        self::METHOD_GUIDED_PRACTICE,
        self::METHOD_COOPERATIVE,
        self::METHOD_INQUIRY,
        self::METHOD_HANDS_ON,
        self::METHOD_STORYTELLING,
        self::METHOD_OTHER,
    ];

    /** Every template field the client may write, in the template's own order. */
    public const TEMPLATE_FIELDS = [
        'subject',
        'grade_label',
        'curriculum_week_no',
        'standard_code',
        'standard_description',
        'objective',
        'learning_outcomes',
        'differentiation_support',
        'differentiation_extension',
        'differentiation_learning_styles',
        'differentiation_ell_aal',
        'differentiation_sen',
        'cross_integration_subject',
        'cross_integration_islamic',
        'cross_integration_stem',
        'teaching_methods',
        'teaching_methods_other',
        'teaching_aids',
        'assessment_formative',
        'assessment_exit_ticket',
        'reflection_worked',
        'reflection_improve',
    ];

    protected $fillable = [
        'masjid_id',
        'class_subject_id',
        'group_id',
        'author_user_id',
        'session_date',
        'title',
        'body',
        'prefill_source',
        ...self::TEMPLATE_FIELDS,
    ];

    /**
     * `subject_key` and `grade_key` are plumbing for the unique index — derived
     * from `subject` and `grade_label` on every save — and not something a
     * screen should read or a client send.
     */
    protected $hidden = [
        'subject_key',
        'grade_key',
        'class_subject_id',
        'class_subject_link_checked_at',
    ];

    protected static function booted(): void
    {
        // Here, not in the controller, so every writer — the teacher API, a
        // console session, a seeder — lands on the same key. A subject that is
        // only whitespace is no subject: it becomes NULL and keys as the day's
        // general plan rather than as a subject nobody can see.
        static::saving(function (LessonPlan $plan): void {
            $plan->subject = self::cleanSubject($plan->subject);
            $plan->subject_key = self::subjectKeyFor($plan->subject);
            $plan->grade_key = self::gradeKeyFor($plan->grade_label);
        });
    }

    /** Trimmed, runs of whitespace collapsed to one space; NULL when nothing is left. */
    public static function cleanSubject(?string $subject): ?string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $subject));

        return $clean === '' ? null : $clean;
    }

    /**
     * The comparison key: two plans on one day whose subjects share a key are the
     * same subject's plan, and the unique index refuses the second. '' is "no
     * subject" — NOT NULL, because a NULL never collides in a MySQL unique index
     * and two general plans for one day would both be admitted.
     *
     * SIMPLE lower-casing, one code point for one code point, not mb_strtolower's
     * full mapping: the full mapping can LENGTHEN a string ('İ' becomes 'i' plus
     * a combining dot), so a 64-character subject — the most the request allows —
     * could produce a 65-character key for a 64-character column, which MySQL
     * refuses with a 500 and SQLite (the suite) silently stores.
     */
    public static function subjectKeyFor(?string $subject): string
    {
        return mb_convert_case((string) self::cleanSubject($subject), MB_CASE_LOWER_SIMPLE, 'UTF-8');
    }

    /**
     * The grade's comparison key: two plans of one subject on one day whose
     * grades share a key are the same plan, and the unique index refuses the
     * second. '' is "no grade", NOT NULL for the reason subjectKeyFor gives.
     *
     * The rules App\Support\GradeLevel::key had on 2026-10-15, so "Pre-K" and
     * "Pre-Kindergarten" are one grade here as on the screens that compare
     * grades. COPIED, not called, for the reason SubjectKey's docblock gives for
     * subjectKeyFor: this key is stored under a unique index, and a mapping
     * added to GradeLevel for a roster would leave every stored plan under its
     * old key, so the next save of the same grade's plan would miss it and add a
     * second. Changing this fold needs a migration that re-keys the rows.
     *
     * A label with no Latin letter or digit (written only in Arabic, say) keys
     * as itself, lower-cased one code point at a time, so it is still a grade of
     * its own and never the ungraded slot. Neither form is longer than the
     * label, so the key fits the column grade_label fits.
     */
    public static function gradeKeyFor(?string $grade): string
    {
        $clean = self::cleanSubject($grade);

        if ($clean === null) {
            return '';
        }

        $flat = (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($clean));

        return match (true) {
            $flat === '' => mb_convert_case($clean, MB_CASE_LOWER_SIMPLE, 'UTF-8'),
            in_array($flat, ['prek', 'prekindergarten', 'pk'], true) => 'prek',
            in_array($flat, ['kg', 'k', 'kindergarten'], true) => 'kg',
            // "1st", "1st grade", "grade 1", "grade1st", "1".
            (bool) preg_match('/^(?:grade)?(\d{1,2})(?:st|nd|rd|th)?(?:grade)?$/', $flat, $m) => (string) (int) $m[1],
            // "first", "first grade", "grade first".
            preg_match('/^(?:grade)?([a-z]+?)(?:grade)?$/', $flat, $m) && isset(self::GRADE_WORDS[$m[1]]) => (string) self::GRADE_WORDS[$m[1]],
            default => $flat,
        };
    }

    /** gradeKeyFor's ordinal words, frozen with it. */
    private const GRADE_WORDS = [
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6,
        'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10, 'eleventh' => 11, 'twelfth' => 12,
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'learning_outcomes' => 'array',
            'teaching_methods' => 'array',
            'curriculum_week_no' => 'integer',
            'class_subject_id' => 'integer',
            'class_subject_link_checked_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * The files listed under this plan's Activities, in the teacher's order.
     * Links to the class's Files, never bytes of their own: see
     * LessonPlanResource. Staff payloads only.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(LessonPlanResource::class)->orderBy('position')->orderBy('id');
    }
}
