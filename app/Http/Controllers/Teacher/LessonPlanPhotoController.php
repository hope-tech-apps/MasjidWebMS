<?php

namespace App\Http\Controllers\Teacher;

use App\Models\{CurriculumWeek, Group, LessonPlan};
use App\Services\LessonPlanPhotoReader;
use App\Support\{LessonPlanPhotoAccess, SchoolSettings, SubjectFence, SubjectKey};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Log, RateLimiter};
use Throwable;

class LessonPlanPhotoController extends TeacherController
{
    public function read(Request $request, $masjid_id, $group_id, LessonPlanPhotoReader $reader): JsonResponse
    {
        abort_unless(LessonPlanPhotoAccess::allowed(SchoolSettings::org($masjid_id), (int) $request->user()->id), 403);
        $data = $request->validate([
            'session_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->addYear()->toDateString()],
            'subject' => ['nullable', 'string', 'max:64'], 'grade_label' => ['nullable', 'string', 'max:32'],
            'class_subject_id' => ['nullable', 'integer', 'min:1'], 'plan_id' => ['nullable', 'integer', 'min:1'],
            'photos' => ['required', 'array', 'min:1', 'max:4'], 'photos.*' => LessonPlanPhotoAccess::imageRules(),
        ]);
        $group = Group::findOrFail($group_id);
        $limits = SubjectFence::limitsFor($request->user(), (int) $group->id);
        $plan = isset($data['plan_id']) ? LessonPlan::where('group_id', $group->id)->whereDate('session_date', $data['session_date'])->findOrFail($data['plan_id']) : null;
        $subject = $data['subject'] ?? $plan?->subject;
        if (\App\Support\ClassSubjectMode::forGroup((int) $group->id)) {
            if ($plan && ! SubjectFence::allowsWork($limits, $plan->class_subject_id, SubjectKey::clean($plan->subject) === null)) abort(404);
            if ($subject || isset($data['class_subject_id'])) {
                $choice = SubjectFence::resolveChoice($group, $subject, $data['class_subject_id'] ?? $plan?->class_subject_id,
                    isset($data['class_subject_id']) || $plan?->class_subject_id !== null, $limits);
                $subject = $choice->name;
            }
        } else {
            if ($plan && $plan->subject && ! SubjectFence::allows($limits, SubjectKey::for($plan->subject))) abort(404);
            if ($subject && ! SubjectFence::allows($limits, SubjectKey::for($subject))) SubjectFence::refuse($subject);
        }
        $key = 'lesson-plan-photo:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, min(20, (int) config('lesson_plan_photo.reads_per_hour', 20)))) {
            return response()->json(['status' => 'failed', 'message' => 'You have reached the photo reading limit; please try again later.'], 429);
        }
        RateLimiter::hit($key, 3600);
        try {
            $days = $reader->read($request->file('photos'), $data['session_date']);
        } catch (Throwable $e) {
            Log::warning('Lesson-plan photos could not be read.', ['class' => $e::class,
                'status' => $e instanceof \Anthropic\Core\Exceptions\APIException ? $e->status : null]);
            return response()->json(['status' => 'failed', 'message' => 'The photos could not be read; please try again.'], 503);
        }
        $hidden = SchoolSettings::hiddenLessonPlanFields(SchoolSettings::org($masjid_id));
        foreach ($days as &$day) {
            $code = $day['fields']['standard_code'];
            $row = $code === '' ? null : CurriculumWeek::where('masjid_id', (int) $masjid_id)->where('standard_code', $code)
                ->where('grade_label', $data['grade_label'] ?? $plan?->grade_label ?? '')
                ->where('subject', $subject ?? '')->orderBy('id')->first();
            $day['fields']['standard_description'] = LessonPlanPhotoReader::clean((string) $row?->standard_description, (int) config('groups.lessons.max_section_length', 2000));
            $day['fields'] = array_diff_key($day['fields'], array_flip($hidden));
            $day['suggested'] = array_values(array_filter(LessonPlanPhotoReader::SUGGESTED, fn ($f) => ! empty($day['fields'][$f])));
        }
        unset($day);
        $index = array_search($data['session_date'], array_column($days, 'date'), true);
        $selected = $index !== false ? ($days[$index] ?? null) : null;
        $otherDates = array_values(array_map(fn ($d) => $d['date_written'], array_filter($days, fn ($d, $i) => $i !== $index, ARRAY_FILTER_USE_BOTH)));
        $fields = $selected['fields'] ?? [];
        return response()->json(['status' => 'success', 'data' => [
            'fields' => $fields, 'days' => $days, 'other_dates' => $otherDates,
            'suggested' => $selected['suggested'] ?? [],
            'message' => $selected ? implode(' ', array_map(fn ($d) => 'This photo also has '.$d.'; it was left out.', $otherDates))
                : 'No date matched this plan. Dates found: '.implode(', ', array_column($days, 'date_written')).'.',
        ]]);
    }
}
