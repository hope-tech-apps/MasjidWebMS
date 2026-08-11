<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Groups\StoreBehaviorSkillRequest;
use App\Http\Requests\Admin\Groups\UpdateBehaviorSkillRequest;
use App\Models\BehaviorSkill;
use App\Models\Masjid;
use App\Support\Errors;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin CRUD for the behaviour VOCABULARY — the Classroom module's picker
 * (PLAN T-013).
 *
 * "Participation", "Kindness", "Disruption": what a school chooses to notice.
 * Manara ships no fixed list; the list is a pedagogical decision the
 * organization owns, so every tenant writes its own.
 *
 * WHY THIS CONTROLLER HAS NO GroupAudience CHECK, unlike every other surface in
 * this module: a skill holds no data about any child. It is not group-scoped at
 * all — it is per-tenant configuration, like a contact reason or a fund — so
 * reading it discloses nothing, and the ordinary `view contacts` /
 * `manage contacts` gates are the whole story. The disclosure rules start at
 * BehaviorAwardsController, where an actual child appears.
 *
 * NO PAYWALL. There is no plan check here, no `is_premium` on the model, and no
 * cap on how many skills a tenant may define. Gating classroom basics behind a
 * plan is the second of the two complaints this module is built to answer; see
 * .claude/rules/groups.md.
 *
 * Tenant isolation is not hand-rolled: the route keeps /masjids/{masjid_id}/...
 * by convention, but the `tenant` middleware binds TenantContext and
 * BelongsToMasjid auto-scopes BehaviorSkill — so another organization's id is a
 * MISS (404), never a filtered row. findOrFail stays OUTSIDE every try/catch so
 * the JSON renderer turns it into a clean 404 rather than a 500. See
 * .claude/rules/tenant-scoping.md.
 */
class BehaviorSkillsController extends Controller
{
    /**
     * GET .../behavior-skills[?search=&polarity=&active_only=]
     *
     * The whole vocabulary, positives first then by label, so a picker renders
     * in the order a teacher reaches for it rather than by insertion id.
     */
    public function index(Request $request, $masjid_id)
    {
        $search = $request->query('search');

        $skills = BehaviorSkill::query()
            ->when($search, fn ($query, $search) => $query->where('label', 'like', "%{$search}%"))
            ->when(
                in_array($request->query('polarity'), BehaviorSkill::POLARITIES, true),
                fn ($query) => $query->where('polarity', $request->query('polarity'))
            )
            ->when($request->boolean('active_only'), fn ($query) => $query->active())
            ->orderBy('polarity')
            ->orderBy('label')
            ->paginate($request->query('per_page', 50));

        return response()->json([
            'status' => 'success',
            'data' => $skills,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * POST .../behavior-skills
     *
     * masjid_id is intentionally absent from the payload: the BelongsToMasjid
     * creating hook stamps it from the bound tenant, so a client-supplied
     * masjid_id can never plant a skill in another organization.
     */
    public function store(StoreBehaviorSkillRequest $request, $masjid_id)
    {
        try {
            $skill = BehaviorSkill::create($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $skill,
                'meta' => $this->meta(),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** GET .../behavior-skills/{skill_id} */
    public function show($masjid_id, $skill_id)
    {
        $skill = BehaviorSkill::findOrFail($skill_id);

        return response()->json([
            'status' => 'success',
            'data' => $skill,
            'meta' => $this->meta(),
        ], Response::HTTP_OK);
    }

    /**
     * PUT .../behavior-skills/{skill_id}
     *
     * Editing describes what happens NEXT. Awards already given carry their own
     * snapshot of label, polarity and points, so renaming or re-weighting a
     * skill never rewrites a child's record — the same guarantee the fee plans
     * give in-flight registrations.
     */
    public function update(UpdateBehaviorSkillRequest $request, $masjid_id, $skill_id)
    {
        $skill = BehaviorSkill::findOrFail($skill_id);

        try {
            $skill->update($request->validated());

            return response()->json([
                'status' => 'success',
                'data' => $skill->fresh(),
                'meta' => $this->meta(),
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'failed',
                'data' => Errors::publicMessage($e),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * DELETE .../behavior-skills/{skill_id}
     *
     * A real delete, deliberately — the opposite call from groups and posts.
     * The mis-click guard exists to protect records ABOUT CHILDREN, and this row
     * holds none: every award already carries its own snapshot, and
     * `behavior_awards.behavior_skill_id` nulls rather than cascades, so
     * deleting a skill removes a drop-down entry and nothing else. The ordinary
     * way to retire one while keeping the picker tidy is `is_active: false`.
     */
    public function destroy($masjid_id, $skill_id)
    {
        $skill = BehaviorSkill::findOrFail($skill_id);
        $skill->delete();

        return response()->json([
            'status' => 'success',
            'data' => ['id' => $skill->id],
        ], Response::HTTP_OK);
    }

    /**
     * Vertical-aware labelling, same source as the other group controllers:
     * what a group is CALLED comes from the tenant's terminology pack
     * ("Halaqat" / "Classrooms" / "Teams"), never a hardcoded string. See
     * .claude/rules/verticals.md.
     *
     * @return array<string,mixed>
     */
    private function meta(): array
    {
        $masjidId = app(TenantContext::class)->get();
        $masjid = $masjidId ? Masjid::find($masjidId) : null;

        return [
            'group_label' => $masjid?->term('groups') ?? 'Groups',
            'polarities' => BehaviorSkill::POLARITIES,
            'max_points' => (int) config('groups.behavior.max_points', 0),
        ];
    }
}
