<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\SaveGradeWeightsRequest;
use App\Models\Group;
use App\Services\Schools\ClassGradeWeightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The OFFICE setting, or clearing, how much each type of work counts in a class.
 *
 * This is the one write the office has on the gradebook (the rest stays a teacher's:
 * routes/admin.php). It exists because the weights are one policy for the whole class,
 * so a teacher limited to some subjects may not change them (SubjectFence::mayWeighClass,
 * review F5), and a class whose every teacher is limited, the common case at Al-Razi,
 * would otherwise have nobody who could ever set them.
 *
 * Same request and same write as the teacher's verb: SaveGradeWeightsRequest (all five
 * types or none, or a clear) and ClassGradeWeightsService, which also removes every
 * per-work override when it clears. What differs is who may call it. The teacher route
 * is fenced by subject; this one is not, because the office is not subject-limited, and
 * it is gated by `permission:manage contacts` in routes/admin.php instead.
 *
 * Tenant isolation is the guardrail, not hand-filtering: Group is BelongsToMasjid, so
 * another organization's group id is a 404 miss (.claude/rules/tenant-scoping.md).
 */
class GroupGradeWeightsController extends Controller
{
    /** PUT .../groups/{group_id}/grade-weights */
    public function update(SaveGradeWeightsRequest $request, $masjid_id, $group_id, ClassGradeWeightsService $weights): JsonResponse
    {
        $group = Group::findOrFail($group_id);

        return response()->json([
            'status' => 'success',
            'data' => $weights->save(
                $group,
                $request->boolean('clear'),
                (array) $request->validated('weights'),
                Auth::id(),
            ),
        ], Response::HTTP_OK);
    }
}
