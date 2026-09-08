<?php

namespace App\Http\Middleware;

use App\Models\Group;
use App\Support\GroupAudience;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-class gate for the teacher realm: the signed-in teacher must LEAD the
 * `{group_id}` in the route (`group_staff`), or the request is refused.
 *
 * This is the "only my classes" boundary, and the reason the reused admin
 * controllers (ArabicLetters/BehaviorAwards/HifzEntries/GroupPosts/GroupThreads)
 * need NO teacher-specific branch: the ones that authorize through GroupAudience
 * would grant a group_staff teacher leader standing anyway, and the one that does
 * NOT (ArabicLetters relies on the tenant scope alone) is fenced here instead —
 * so a teacher cannot mark letters for a class in their school that they do not
 * teach. Runs AFTER `tenant`, so Group::findOrFail is scoped to the teacher's
 * bound masjid (a foreign group id is a 404, not a 403).
 */
class EnsureTeacherLeadsGroup
{
    public function __construct(private GroupAudience $audience)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Tenant-scoped: a group in another masjid resolves to 404 rather than
        // revealing that it exists.
        $group = Group::findOrFail($request->route('group_id'));

        if (! $this->audience->isLeaderOf($request->user(), $group)) {
            abort(403, 'You do not lead this class.');
        }

        return $next($request);
    }
}
