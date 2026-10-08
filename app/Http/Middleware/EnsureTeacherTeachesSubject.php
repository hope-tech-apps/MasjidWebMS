<?php

namespace App\Http\Middleware;

use App\Models\GroupStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `teacher.teaches:{subject}` — the signed-in teacher's assignment to THIS class
 * must cover the subject that owns the route (owner, 2026-09-21).
 *
 * Runs after `teacher.leads`, which has already proven they lead the class; this
 * only narrows what they may do in it. An Arabic teacher asking for the class's
 * hifdh is refused here, rather than merely not being shown the tab — a hidden
 * tab is not a boundary, and the promise made to the teachers was about access.
 *
 * An assignment with no subjects recorded (NULL) teaches everything, which is
 * every assignment that existed before this and every full-time teacher.
 */
class EnsureTeacherTeachesSubject
{
    public function handle(Request $request, Closure $next, string $subject): Response
    {
        $assignment = GroupStaff::query()
            ->where('group_id', (int) $request->route('group_id'))
            ->where('user_id', $request->user()?->getAuthIdentifier())
            ->first();

        $group = \App\Models\Group::find((int) $request->route('group_id'));
        if ($group?->teachesStudents() && \App\Support\SchoolSettings::classSubjects(\App\Support\SchoolSettings::org($group->masjid_id))) {
            $tool = $subject === 'quran' ? 'hifdh' : 'arabic_letters';
            $uri = $request->route()->uri();
            if ($subject === 'arabic' && str_contains($uri, '/letters') && ! str_ends_with($uri, '/letters/stage')) {
                $input = $request->isMethod('get') ? $request->query('alphabet') : $request->input('alphabet');
                $alphabet = \App\Support\Letters\CurriculumRegistry::fromInput($input)->alphabetId();
                $tool = $alphabet === 'english' ? 'english_letters' : 'arabic_letters';
            }
            $holder = \App\Models\ClassSubject::where('group_id', $group->id)->where('tool', $tool)->first();
            $ids = \App\Support\SubjectFence::assignedIds((int) $group->id, (int) $request->user()->id);
            if ($holder === null || ($ids !== null && ! in_array((int) $holder->id, $ids, true))) {
                return response()->json(['status' => 'error', 'message' => 'You do not teach the subject holding this tool in this class.'], 403);
            }
            return $next($request);
        }

        // No row is impossible after `teacher.leads`; refuse rather than assume.
        if ($assignment === null || ! $assignment->teaches($subject)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not teach '
                .(GroupStaff::SUBJECT_LABELS[$subject] ?? $subject).' in this class.');
        }

        return $next($request);
    }
}
