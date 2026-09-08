<?php

namespace App\Http\Controllers\Teacher;

use App\Models\CurriculumWeek;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The school's own pacing guide, as the lesson-plan form needs it.
 *
 * Reference data for the bound tenant, so it is not group-scoped and carries no
 * authorization beyond being a signed-in teacher — the same shape as
 * /behavior-skills and /quran-surahs beside it. `CurriculumWeek` is
 * tenant-scoped, so one school can never see another's standards.
 *
 * ONE endpoint, progressively narrowed by query parameters, because the form
 * asks three questions in sequence — which grade, which subject, which week —
 * and a round trip per dropdown would be three endpoints that must agree.
 *
 * The subject list is DISTINCT over the tenant's own imported rows rather than a
 * constant, so the day the school authors an Arabic pacing column it appears in
 * the picker with no code change.
 */
class CurriculumController extends TeacherController
{
    public function index(Request $request, $masjid_id): JsonResponse
    {
        $grade = $request->query('grade');
        $subject = $request->query('subject');
        $week = $request->query('week');

        $grades = CurriculumWeek::query()
            ->distinct()->orderBy('grade_label')->pluck('grade_label');

        $subjects = $grade
            ? CurriculumWeek::query()->where('grade_label', $grade)
                ->distinct()->orderBy('subject')->pluck('subject')
            : collect();

        $weeks = ($grade && $subject)
            ? CurriculumWeek::query()
                ->where('grade_label', $grade)->where('subject', $subject)
                ->orderBy('week_no')
                ->get(['week_no', 'quarter', 'focus', 'standard_code'])
                ->map(fn (CurriculumWeek $w): array => [
                    'week_no' => (int) $w->week_no,
                    'quarter' => $w->quarter !== null ? (int) $w->quarter : null,
                    'focus' => $w->focus,
                    'standard_code' => $w->standard_code,
                ])
            : collect();

        // The cell itself, only when all three are named. This is what the
        // Prefill button writes into the form.
        $cell = null;

        if ($grade && $subject && $week !== null && $week !== '') {
            $row = CurriculumWeek::query()
                ->where('grade_label', $grade)
                ->where('subject', $subject)
                ->where('week_no', (int) $week)
                ->first();

            if ($row) {
                $cell = $row->toPrefillArray();

                // The rest of that week for the SAME grade, so the form can
                // offer real cross-subject integration lines instead of asking
                // a teacher to remember what Science is doing.
                $cell['siblings'] = CurriculumWeek::query()
                    ->where('grade_label', $grade)
                    ->where('week_no', (int) $week)
                    ->where('subject', '!=', $subject)
                    ->orderBy('subject')
                    ->get(['subject', 'focus'])
                    ->map(fn (CurriculumWeek $s): array => [
                        'subject' => $s->subject,
                        'focus' => $s->focus,
                    ])->values();
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'grades' => $grades->values(),
                'subjects' => $subjects->values(),
                'weeks' => $weeks->values(),
                'cell' => $cell,
            ],
        ], Response::HTTP_OK);
    }
}
