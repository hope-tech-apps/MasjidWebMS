<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMasjid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a class is going to cover on one day, in one subject. One per class, per
 * day, per subject (`lesson_plan_class_day_subject_unique`): a combined-grade
 * homeroom teaches several subjects a day and plans each. A plan with no subject
 * is the day's single general plan, for a school with no pacing guide.
 *
 * Carries the school's own lesson-plan template (see the
 * add_lesson_plan_template_to_lesson_plans_table migration for what it
 * deliberately does NOT store, and why).
 *
 * `body` is the template's ACTIVITIES field and is the one required section.
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
        'group_id',
        'author_user_id',
        'session_date',
        'title',
        'body',
        'prefill_source',
        ...self::TEMPLATE_FIELDS,
    ];

    /**
     * `subject_key` is plumbing for the unique index — derived from `subject` on
     * every save — and not something a screen should read or a client send.
     */
    protected $hidden = [
        'subject_key',
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

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'learning_outcomes' => 'array',
            'teaching_methods' => 'array',
            'curriculum_week_no' => 'integer',
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
}
