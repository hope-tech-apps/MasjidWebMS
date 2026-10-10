<?php

namespace App\Support;

use App\Models\{ClassSubject, Group, ReportCard, SchoolTerm};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Staff-only reference data; never attached to a card model or used by family/PDF readers. */
final class ReportCardSubjectWork
{
    /** Current names only, including the same two fixed aliases as class initialization. */
    public static function match(string $name, Collection $subjects): ?ClassSubject
    {
        $key = SubjectKey::for($name);
        return $subjects->first(fn ($subject) => in_array($key, $subject->matchingKeys(), true));
    }

    /** One grouped mark read for the entire card, including a departed student's retained marks. */
    public static function forCard(ReportCard $card, Collection $rows, ?\App\Models\User $user): array
    {
        $group = Group::findOrFail($card->group_id);
        $subjects = ClassSubject::where('masjid_id', $card->masjid_id)->where('group_id', $group->id)->orderBy('position')->orderBy('id')->get();
        $limits = SubjectFence::limitsForWithClassSubjects($user, (int) $group->id);
        $term = SchoolCalendarRequestMode::enabled((int) $card->masjid_id) && $card->school_term_id
            ? SchoolTerm::where('masjid_id', $card->masjid_id)->find($card->school_term_id) : null;
        $query = DB::table('subject_piece_marks as marks')
            ->join('subject_pieces as pieces', 'pieces.id', '=', 'marks.subject_piece_id')
            ->leftJoin('lesson_plans as plans', function ($join) use ($card) {
                $join->on('plans.id', '=', 'pieces.lesson_plan_id')->where('plans.masjid_id', $card->masjid_id)->where('plans.group_id', $card->group_id);
            })
            ->where('marks.masjid_id', $card->masjid_id)->where('pieces.masjid_id', $card->masjid_id)
            ->where('marks.group_membership_id', $card->group_membership_id)
            ->whereIn('pieces.class_subject_id', $subjects->whereNull('hidden_at')->pluck('id'))
            ->whereIn('marks.level', PerformanceLevel::ALL);
        if ($term !== null) {
            $timezone = \App\Http\Controllers\AdminDashboard\FormStaffCodesController::timezoneFor(SchoolSettings::org($card->masjid_id))['name'];
            $from = \Carbon\CarbonImmutable::parse($term->starts_on->toDateString(), $timezone)->startOfDay()->utc();
            $until = \Carbon\CarbonImmutable::parse($term->ends_on->toDateString(), $timezone)->addDay()->startOfDay()->utc();
            $query->where(function ($q) use ($term, $from, $until) {
                $q->where(function ($q) use ($term) {
                    $q->where('pieces.source', 'plan')
                        ->whereRaw('COALESCE(SUBSTR(plans.session_date, 1, 10), SUBSTR(pieces.title, 1, 10)) >= ?', [$term->starts_on->toDateString()])
                        ->whereRaw('COALESCE(SUBSTR(plans.session_date, 1, 10), SUBSTR(pieces.title, 1, 10)) <= ?', [$term->ends_on->toDateString()]);
                })->orWhere(function ($q) use ($from, $until) {
                    $q->whereIn('pieces.source', ['guide', 'own'])->where('marks.updated_at', '>=', $from)->where('marks.updated_at', '<', $until);
                });
            });
        }
        $counts = $query->groupBy('pieces.class_subject_id', 'marks.level')
            ->select(['pieces.class_subject_id', 'marks.level'])->selectRaw('COUNT(*) AS n')->get()->groupBy('class_subject_id');
        $out = [];
        foreach ($rows->unique('subject') as $row) {
            // Behaviours and other non-subject lines retain class-wide authority.
            if ($row->kind !== \App\Models\ReportCardMark::KIND_ACADEMIC) continue;
            $subject = self::match($row->subject, $subjects);
            $entry = ['can_fill' => SubjectFence::allowsReportSubject($limits, $subject)];
            if ($subject !== null && $subject->hidden_at === null) {
                $levels = [];
                foreach (PerformanceLevel::ALL as $level) $levels[$level] = (int) ($counts->get($subject->id, collect())->firstWhere('level', $level)?->n ?? 0);
                $entry['work_summary'] = [
                    'class_subject_id' => (int) $subject->id, 'name' => $subject->name,
                    'heading' => ($term ? 'This term in ' : 'So far this year in ').$subject->name,
                    'counts' => $levels, 'can_open' => SubjectFence::allowsWork($limits, (int) $subject->id),
                ];
            }
            $out[$row->subject] = $entry;
        }
        return $out;
    }
}
