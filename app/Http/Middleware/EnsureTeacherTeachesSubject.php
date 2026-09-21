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

        // No row is impossible after `teacher.leads`; refuse rather than assume.
        if ($assignment === null || ! $assignment->teaches($subject)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not teach '
                .(GroupStaff::SUBJECT_LABELS[$subject] ?? $subject).' in this class.');
        }

        return $next($request);
    }
}
