<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared boundary rules for the behaviour-skill write requests (T-013).
 *
 * Extends BaseFormRequest so a rejection leaves as the legacy {status:'failed'}
 * 422 instead of a raw ValidationException, which this app's JSON renderer
 * would turn into a 500.
 *
 * masjid_id is NOT accepted by either subclass and never will be — the
 * BelongsToMasjid creating hook stamps it from the bound tenant.
 */
abstract class BehaviorSkillFormRequest extends BaseFormRequest
{
    /**
     * Per-masjid label uniqueness, matching the DB index.
     *
     * The tenant is server-derived from TenantContext, which ResolveMasjidTenant
     * has already bound for this route; the route parameter is only a fallback
     * for the unbound case and cannot widen access, because reaching another
     * masjid's route is already a 403. Same arrangement as
     * GroupFormRequest::uniqueSlugRule().
     *
     * Nothing soft-deletes on this table, so unlike the groups rule this one
     * never has to reason about a retired row still holding its name.
     */
    protected function uniqueLabelRule(?int $ignoreId = null): Unique
    {
        $masjidId = app(TenantContext::class)->get() ?? (int) $this->route('masjid_id');

        $rule = Rule::unique('behavior_skills', 'label')->where('masjid_id', $masjidId);

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }

    /**
     * The point-value bound, applied in both directions: a school may run
     * "Disruption, -1" or "Disruption, 1, marked negative", and both are
     * legitimate. The ceiling guards against a fat-fingered value dominating
     * every summary, not against a pedagogy.
     *
     * @return array<int,mixed>
     */
    protected function pointsRules(bool $required): array
    {
        $max = max(1, (int) config('groups.behavior.max_points', 100));

        return array_values(array_filter([
            $required ? 'required' : 'sometimes',
            'integer',
            'min:' . (-$max),
            'max:' . $max,
        ]));
    }

    public function messages(): array
    {
        return [
            'label.unique' => 'Another skill in this organization already uses that label.',
        ];
    }
}
