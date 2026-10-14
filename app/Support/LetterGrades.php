<?php

namespace App\Support;

use App\Models\{AssignmentScore, ClassAssignment, ClassSubject, Group, GroupMembership, Masjid, ReportCard, ReportCardMark, SchoolTerm, SchoolYear, SubjectPieceMark, TermGrade};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** School policy, staff evidence and final publication snapshots. Never converts a level into points. */
final class LetterGrades
{
    // Owner's scale, 2026-10-14. The last row's lower bound closes every percentage interval.
    public const DEFAULT_SCALE = [
        ['letter'=>'A+','minimum'=>97], ['letter'=>'A','minimum'=>93], ['letter'=>'A−','minimum'=>90],
        ['letter'=>'B+','minimum'=>87], ['letter'=>'B','minimum'=>83], ['letter'=>'B−','minimum'=>80],
        ['letter'=>'C+','minimum'=>77], ['letter'=>'C','minimum'=>73], ['letter'=>'C−','minimum'=>70],
        ['letter'=>'D+','minimum'=>67], ['letter'=>'D','minimum'=>63], ['letter'=>'D−','minimum'=>60],
        ['letter'=>'F','minimum'=>0],
    ];

    /** Validate the complete scale without sorting away an office mistake. */
    public static function validateScale(array $scale): array
    {
        $letters = []; $previous = 101.0;
        if ($scale === [] || count($scale) > 40 || ! array_is_list($scale)) self::invalidScale();
        foreach ($scale as $row) {
            if (! is_array($row) || count($row) !== 2 || ! array_key_exists('letter', $row) || ! array_key_exists('minimum', $row) || ! is_string($row['letter'] ?? null) || trim($row['letter']) !== $row['letter']
                || $row['letter'] === '' || mb_strlen($row['letter']) > 20 || ! is_numeric($row['minimum'] ?? null)) self::invalidScale();
            $minimum = (float) $row['minimum'];
            $key = mb_strtolower($row['letter']);
            if (! is_finite($minimum) || $minimum < 0 || $minimum > 100 || $minimum >= $previous || isset($letters[$key])) self::invalidScale();
            $letters[$key] = true; $previous = $minimum;
        }
        if ($previous !== 0.0) self::invalidScale();
        return $scale;
    }

    private static function invalidScale(): never
    {
        throw ValidationException::withMessages(['scale'=>['Save a complete descending scale with unique letters and cut-offs from 0 to 100. The lowest row must start at 0.']]);
    }

    public static function letter(?float $percentage, array $scale): ?string
    {
        if ($percentage === null) return null;
        foreach ($scale as $row) if ($percentage >= (float) $row['minimum']) return $row['letter'];
        return null;
    }

    /** Reuse the organisation already read by the realm; OFF adds no SQL to existing readers. */
    public static function school(int $id): ?Masjid
    {
        $loaded = ClassSubjectMode::loadedOrganisation($id);
        if ($loaded !== null && (! $loaded->hasCapability('letter_grades') || array_key_exists('letter_grade_settings', $loaded->getAttributes()))) return $loaded;
        $school = Masjid::find($id);
        if ($school && $loaded !== null) { ClassSubjectMode::forget($id); ClassSubjectMode::rememberLoaded($school); }
        return $school;
    }

    public static function enabledFor(int $id): bool
    {
        return self::enabled(self::school($id));
    }

    /** The school-only rollout grant is offered even before the office enables its policy. */
    public static function offered(?Masjid $school): bool
    {
        return $school !== null && $school->deleted_at === null && $school->orgType() === 'school' && $school->hasCapability('letter_grades');
    }

    /** Rollout grant and the office's policy must both be enabled. */
    public static function enabled(?Masjid $school): bool
    {
        return self::offered($school)
            && SchoolSettings::classSubjects($school) && SchoolSettings::calendarTerms($school)
            && ($school->letter_grade_settings['enabled'] ?? false) === true;
    }

    public static function eligible(?Masjid $school, ?string $grade): bool
    {
        $position = GradeLevel::position($grade);
        $from = GradeLevel::position($school?->letter_grade_settings['from_grade'] ?? null);
        return self::enabled($school) && $position !== null && $from !== null && $position >= $from;
    }

    /** A class containing only younger grades retains its existing Reports surface. */
    public static function forGroup(Group $group): bool
    {
        $school = self::school((int) $group->masjid_id);
        if ($group->kind !== Group::KIND_CLASS || ! self::enabled($school)) return false;
        return $group->memberships()->participants()->get(['grade_label'])->contains(fn ($row) => self::eligible($school, $row->grade_label));
    }

    /** Match any dated term system; the legacy quarter matcher remains untouched. */
    public static function term(ReportCard $card): ?SchoolTerm
    {
        if ($card->school_term_id) return SchoolTerm::where('masjid_id', $card->masjid_id)->find($card->school_term_id);
        $years = SchoolYear::where('masjid_id', $card->masjid_id)->get();
        $matches = $years->where('label', $card->school_year);
        if ($matches->isEmpty()) {
            [$first, $last] = explode('-', $card->school_year);
            $matches = $years->filter(fn ($year) => $year->first_day->format('Y') === $first && $year->last_day->format('Y') === $last);
        }
        if ($matches->count() !== 1) return null;
        return SchoolTerm::where('masjid_id', $card->masjid_id)->where('school_year_id', $matches->first()->id)->where('position', $card->term)->first();
    }

    /** Ensure one existing mark-table row per class subject, preserving overrides and comments. */
    public static function prepare(ReportCard $card): void
    {
        $school = Masjid::find($card->masjid_id);
        $membership = GroupMembership::findOrFail($card->group_membership_id);
        if ($card->isPublished() || $card->type !== ReportCard::TYPE_REPORT_CARD || ! self::eligible($school, $membership->grade_label)) return;
        $term = self::term($card);
        if (! $term) throw ValidationException::withMessages(['marks'=>['Add a dated term for this report period in school settings before using letters.']]);
        $card->forceFill(['school_term_id'=>$term->id, 'grade_label'=>$membership->grade_label])->save();
        foreach (ClassSubject::where('masjid_id', $card->masjid_id)->where('group_id', $card->group_id)->whereNull('hidden_at')->orderBy('position')->orderBy('id')->get() as $subject) {
            // Match by subject ID, never an academic criterion that happens to have the same wording.
            $mark = $card->marks()->where('kind', ReportCardMark::KIND_TERM_GRADE)->where('class_subject_id', $subject->id)->first()
                ?? $card->marks()->make(['subject'=>$subject->name, 'criterion'=>'Term grade']);
            $mark->forceFill(['masjid_id'=>$card->masjid_id, 'kind'=>ReportCardMark::KIND_TERM_GRADE, 'class_subject_id'=>$subject->id, 'subject'=>$subject->name, 'position'=>1000 + $subject->position])->save();
        }
    }

    /** Letters were switched on here at some point: either the grant is on now or the office has saved settings. */
    private static function everUsed(?Masjid $school): bool
    {
        return $school !== null && (self::enabled($school) || ! empty($school->letter_grade_settings));
    }

    private static function readable(ReportCard $card, ?Masjid $school = null): bool
    {
        if ($card->type !== ReportCard::TYPE_REPORT_CARD) return false;
        $school ??= self::school((int) $card->masjid_id);
        // A sent card shows what it was sent with until it is taken back, whatever the switch says today.
        // A school that never set letters up cannot have sent any, so it reads nothing extra.
        if ($card->isPublished()) return self::everUsed($school) && $card->marks()->where('kind', ReportCardMark::KIND_TERM_GRADE)->whereNotNull('letter_snapshot_at')->exists();
        if (! self::enabled($school)) return false;
        return self::eligible(self::school((int) $card->masjid_id), GroupMembership::find($card->group_membership_id)?->grade_label);
    }

    /** The term-grade criterion carries a frozen dated heading; skill criteria keep their original meaning. */
    public static function periodLabel(ReportCard $card, ?Collection $marks = null, ?Masjid $school = null): ?string
    {
        if ($card->type !== ReportCard::TYPE_REPORT_CARD) return null;
        $school ??= self::school((int) $card->masjid_id);
        // A sent card keeps its frozen dated heading even after letters are switched off.
        if ($card->isPublished()) {
            if (! self::everUsed($school)) return null;
            $mark = $marks?->first(fn ($row) => $row->kind === ReportCardMark::KIND_TERM_GRADE && $row->letter_snapshot_at !== null)
                ?? ($marks === null ? $card->marks()->where('kind', ReportCardMark::KIND_TERM_GRADE)->whereNotNull('letter_snapshot_at')->first() : null);
            return $mark?->criterion;
        }
        if (! self::enabled($school)) return null;
        if (! self::eligible($school ?? self::school((int) $card->masjid_id), GroupMembership::find($card->group_membership_id)?->grade_label)) return null;
        $term = self::term($card);
        return $term ? self::datedLabel($card, $term) : null;
    }

    private static function datedLabel(ReportCard $card, SchoolTerm $term): string
    {
        return $term->name.' ('.$term->starts_on->format('M j, Y').' – '.$term->ends_on->format('M j, Y').'), '.$card->school_year;
    }

    /** Staff see calculated and set grades; subject fences apply to evidence and editing. */
    public static function staff(ReportCard $card): array
    {
        if (! self::readable($card)) return [];
        $school = self::school((int) $card->masjid_id);
        $group = Group::findOrFail($card->group_id);
        $limits = SubjectFence::limitsForWithClassSubjects(Auth::user(), (int) $group->id);
        $marks = ReportCardCriteria::marks($card)->where('kind', ReportCardMark::KIND_TERM_GRADE);
        $subjects = ClassSubject::where('masjid_id', $card->masjid_id)->where('group_id', $card->group_id)->get()->keyBy('id');
        $term = self::term($card);
        $summary = ! $card->isPublished() && $term
            ? GradeRecord::forTerm($card->group_membership_id, $marks->pluck('class_subject_id')->all(), $term->starts_on, $term->ends_on) : null;
        return $marks->map(function ($mark) use ($card, $school, $limits, $subjects, $term, $summary) {
            $canFill = SubjectFence::allowsWork($limits, (int) $mark->class_subject_id);
            $subject = $subjects->get($mark->class_subject_id);
            if (! $card->isPublished() && $subject?->hidden_at !== null) return null;
            $percent = $mark->calculated_percentage;
            $letter = $mark->calculated_letter;
            if ($summary !== null && $canFill) {
                $block = collect($summary['by_subject'])->firstWhere('subject_identity', json_encode(['id', (int) $mark->class_subject_id]));
                $percent = $summary['weighting']['enabled'] ? ($block['weighted_percent'] ?? null) : ($block['percent'] ?? null);
                $letter = self::letter($percent, $school->letter_grade_settings['scale'] ?? self::DEFAULT_SCALE);
            }
            return [
                'id'=>(int) $mark->id, 'class_subject_id'=>(int) $mark->class_subject_id, 'subject'=>$mark->subject,
                'can_fill'=>$canFill, 'calculated_percentage'=>$canFill ? $percent : null, 'calculated_letter'=>$canFill ? $letter : null,
                'override_letter'=>$canFill ? $mark->override_letter : null, 'override_reason'=>$canFill ? $mark->override_reason : null,
                'override_by_user_id'=>$canFill ? $mark->override_by_user_id : null,
                'override_by_name'=>$canFill && $mark->override_by_user_id ? \App\Models\User::withTrashed()->whereKey($mark->override_by_user_id)->value('name') : null, 'override_at'=>$canFill && $mark->override_at ? $mark->override_at->copy()->setTimezone(\App\Support\SchoolCalendarReaders::for((int) $card->masjid_id)->timezone())->format('M j, Y, g:i A') : null,
                'comment'=>$canFill ? $mark->comment : null,
                'final_letter'=>$canFill ? ($card->isPublished() ? $mark->final_letter : ($mark->override_letter ?? $letter)) : null,
                'excluded_work'=>! $card->isPublished() && $term && $canFill ? self::excluded($card, (int) $mark->class_subject_id, $term) : [],
            ];
        })->filter()->values()->all();
    }

    /** List level/simple assignments and level-only subject pieces, never average them. */
    private static function excluded(ReportCard $card, int $subjectId, SchoolTerm $term): array
    {
        $assignments = ClassAssignment::where('masjid_id', $card->masjid_id)->where('group_id', $card->group_id)->where('class_subject_id', $subjectId)
            ->whereDate('assigned_on', '>=', $term->starts_on)->whereDate('assigned_on', '<=', $term->ends_on)
            ->where('scale', '<>', ClassAssignment::SCALE_POINTS)
            ->whereHas('scores', fn ($q) => $q->where('group_membership_id', $card->group_membership_id))->get()
            ->map(fn ($piece) => ['source'=>'assignment', 'id'=>(int) $piece->id, 'title'=>$piece->title, 'reason'=>$piece->scale === ClassAssignment::SCALE_LEVELS ? 'Marked on 4-to-1 levels; no points percentage.' : 'Marked with words; no points percentage.'])->all();
        $pieces = SubjectPieceMark::where('subject_piece_marks.masjid_id', $card->masjid_id)->where('group_membership_id', $card->group_membership_id)
            ->join('subject_pieces', 'subject_pieces.id', '=', 'subject_piece_marks.subject_piece_id')
            ->leftJoin('lesson_plans', 'lesson_plans.id', '=', 'subject_pieces.lesson_plan_id')
            ->where('subject_pieces.class_subject_id', $subjectId)->where('subject_pieces.masjid_id', $card->masjid_id)
            ->whereDate(DB::raw('COALESCE(lesson_plans.session_date, subject_pieces.created_at)'), '>=', $term->starts_on)
            ->whereDate(DB::raw('COALESCE(lesson_plans.session_date, subject_pieces.created_at)'), '<=', $term->ends_on)
            ->get(['subject_pieces.id','subject_pieces.title'])->map(fn ($piece) => ['source'=>'subject_piece', 'id'=>(int) $piece->id, 'title'=>$piece->title, 'reason'=>'Marked on 4-to-1 levels; no points percentage.'])->all();
        return array_merge($assignments, $pieces);
    }

    /** Overrides require a reason; attribution is server-owned and only changes with the override. */
    public static function save(ReportCard $card, array $rows): void
    {
        if ($rows === []) return;
        DB::transaction(function () use ($card, $rows) {
            $school = Masjid::whereKey($card->masjid_id)->lockForUpdate()->firstOrFail();
            ClassSubjectMode::forget((int) $school->id);
            ClassSubjectMode::rememberLoaded($school);
            Group::whereKey($card->group_id)->lockForUpdate()->firstOrFail();
            $current = ReportCard::whereKey($card->id)->lockForUpdate()->firstOrFail();
            self::saveLocked($current, $rows);
        });
    }

    private static function saveLocked(ReportCard $card, array $rows): void
    {
        if ($card->isPublished() || ! self::readable($card)) throw ValidationException::withMessages(['letter_grades'=>['This card cannot be changed. Take back a sent card first.']]);
        $marks = $card->marks()->where('kind', ReportCardMark::KIND_TERM_GRADE)->get()->keyBy('id');
        $scale = Masjid::findOrFail($card->masjid_id)->letter_grade_settings['scale'] ?? self::DEFAULT_SCALE;
        $limits = SubjectFence::limitsForWithClassSubjects(Auth::user(), (int) $card->group_id);
        foreach ($rows as $i => $row) {
            $mark = $marks->get((int) ($row['id'] ?? 0));
            if (! $mark) throw ValidationException::withMessages(["letter_grades.$i.id"=>['Choose a subject on this card.']]);
            abort_unless(SubjectFence::allowsWork($limits, (int) $mark->class_subject_id), 403, 'This subject is filled in by its own teacher.');
            $letter = $row['override_letter'] ?? null;
            $reason = trim((string) ($row['override_reason'] ?? ''));
            if ($letter !== null && (! in_array($letter, array_column($scale, 'letter'), true) || $reason === '')) throw ValidationException::withMessages(["letter_grades.$i.override_reason"=>['Choose a letter from the scale and give a reason for setting it.']]);
            if ($letter !== null) abort_unless(Auth::user() instanceof \App\Models\User, 403);
            $changed = $mark->override_letter !== $letter || ($mark->override_reason ?? '') !== ($letter === null ? '' : $reason);
            $mark->forceFill(['override_letter'=>$letter,'override_reason'=>$letter === null ? null : $reason,'comment'=>$row['comment'] ?? null]);
            if ($changed) $mark->forceFill(['override_by_user_id'=>$letter === null ? null : Auth::id(), 'override_at'=>$letter === null ? null : now()]);
            $mark->save();
        }
    }

    /** Under the report-card publication mutex, persist both snapshots and the future transcript record. */
    public static function snapshot(ReportCard $card): void
    {
        if ($card->isPublished()) return;
        if (! self::readable($card)) return;
        self::prepare($card);
        $term = self::term($card);
        $member = GroupMembership::findOrFail($card->group_membership_id);
        // Publication covers the whole card, even for a teacher limited to some subjects.
        $marks = $card->marks()->where('kind', ReportCardMark::KIND_TERM_GRADE)->get();
        $card->marks()->where('kind', ReportCardMark::KIND_TERM_GRADE)->update(['letter_snapshot_at'=>null]);
        $summary = GradeRecord::forTerm($member->id, $marks->pluck('class_subject_id')->all(), $term->starts_on, $term->ends_on);
        $scale = Masjid::findOrFail($card->masjid_id)->letter_grade_settings['scale'] ?? self::DEFAULT_SCALE;
        foreach ($marks as $mark) {
            $subject = ClassSubject::where('masjid_id', $card->masjid_id)->where('group_id', $card->group_id)->findOrFail($mark->class_subject_id);
            if ($subject->hidden_at !== null) continue;
            $block = collect($summary['by_subject'])->firstWhere('subject_identity', json_encode(['id', (int) $mark->class_subject_id]));
            $percent = $summary['weighting']['enabled'] ? ($block['weighted_percent'] ?? null) : ($block['percent'] ?? null);
            $letter = self::letter($percent, $scale);
            if ($mark->override_letter !== null && ! in_array($mark->override_letter, array_column($scale, 'letter'), true)) throw ValidationException::withMessages(['letter_grades'=>['A set letter is no longer in the school scale. Choose a current letter before sending.']]);
            $mark->forceFill(['criterion'=>self::datedLabel($card, $term),'calculated_percentage'=>$percent,'calculated_letter'=>$letter,'final_letter'=>$mark->override_letter ?? $letter,'final_percentage'=>$mark->override_letter === null || $mark->override_letter === $letter ? $percent : null,'final_comment'=>$mark->comment,'letter_snapshot_at'=>now()])->save();
            $previous = TermGrade::where('masjid_id', $card->masjid_id)->where('contact_id', $member->contact_id)->where('class_subject_id', $mark->class_subject_id)->where('school_term_id', $term->id)->first();
            if ($previous && (int) $previous->report_card_id !== (int) $card->id && ReportCard::whereKey($previous->report_card_id)->published()->exists()) throw ValidationException::withMessages(['letter_grades'=>['This student already has a sent grade for this subject and term. Take back the earlier card before sending another.']]);
            TermGrade::updateOrCreate(['masjid_id'=>$card->masjid_id, 'contact_id'=>$member->contact_id, 'class_subject_id'=>$mark->class_subject_id, 'school_term_id'=>$term->id],
                ['report_card_id'=>$card->id, 'subject'=>$mark->subject, 'final_letter'=>$mark->final_letter, 'percentage'=>$percent, 'comment'=>$mark->final_comment, 'published_at'=>now()]);
        }
    }

    /** Allowlist used by both family JSON and PDF; no staff provenance keys can escape. */
    /** Owner 2026-10-14: a subject with no letter and no comment is left off the family's card. */
    public static function family(ReportCard $card, ?Masjid $school = null): array
    {
        return array_values(array_filter(self::familyRows($card, $school),
            fn ($row) => $row['final_letter'] !== null || trim((string) $row['comment']) !== ''));
    }

    private static function familyRows(ReportCard $card, ?Masjid $school = null): array
    {
        if (! self::readable($card, $school)) return [];
        if (! $card->isPublished()) return array_map(fn ($row) => ['subject'=>$row['subject'], 'final_letter'=>$row['final_letter'], 'percentage'=>$row['override_letter'] === null || $row['override_letter'] === $row['calculated_letter'] ? $row['calculated_percentage'] : null, 'comment'=>$row['comment']], self::staff($card));
        return ReportCardCriteria::marks($card)->where('kind', ReportCardMark::KIND_TERM_GRADE)->whereNotNull('letter_snapshot_at')->values()->map(fn ($mark) => [
            'subject'=>$mark->subject, 'final_letter'=>$mark->final_letter, 'percentage'=>$mark->final_percentage, 'comment'=>$mark->final_comment,
        ])->all();
    }
}
