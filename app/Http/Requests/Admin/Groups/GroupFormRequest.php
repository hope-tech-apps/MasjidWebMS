<?php

namespace App\Http\Requests\Admin\Groups;

use App\Http\Requests\BaseFormRequest;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared boundary rules for the group write requests.
 *
 * Extends BaseFormRequest so a rejection leaves as the legacy {status:'failed'}
 * 422 instead of a raw ValidationException, which this app's JSON renderer would
 * turn into a 500.
 *
 * masjid_id is NOT accepted by either subclass and never will be — the
 * BelongsToMasjid creating hook stamps it from the bound tenant.
 */
abstract class GroupFormRequest extends BaseFormRequest
{
    /**
     * Per-masjid slug uniqueness.
     *
     * The tenant is server-derived from TenantContext, which ResolveMasjidTenant
     * has already bound for this route (a SuperAdmin on /masjids/{id}/... is
     * bound to the masjid the URL names, per .claude/rules/tenant-scoping.md).
     * The route parameter is only a fallback for the unbound case; it cannot
     * widen access, because reaching another masjid's route is already a 403.
     *
     * The check deliberately spans SOFT-DELETED groups, matching the DB index: a
     * deleted group keeps its slug because its roster is retained, so reusing the
     * handle must fail as a clean 422 rather than a duplicate-key 500.
     */
    protected function uniqueSlugRule(?int $ignoreId = null): Unique
    {
        $masjidId = app(TenantContext::class)->get() ?? (int) $this->route('masjid_id');

        $rule = Rule::unique('groups', 'slug')->where('masjid_id', $masjidId);

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may contain only lowercase letters, numbers and single hyphens.',
            'slug.unique' => 'Another group in this organization already uses that slug.',
        ];
    }
}
