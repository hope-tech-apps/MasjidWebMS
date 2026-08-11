<?php

namespace App\Http\Requests\Admin\Offerings;

use App\Http\Requests\BaseFormRequest;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared boundary rules for the offering write requests (T-006d,
 * docs/t006-registration-billing-design.md).
 *
 * Extends BaseFormRequest so a rejection leaves as the legacy {status:'failed'}
 * 422 instead of a raw ValidationException, which this app's JSON renderer
 * would turn into a 500.
 *
 * masjid_id is NOT accepted by either subclass and never will be — the
 * BelongsToMasjid creating hook stamps it from the bound tenant. Neither is
 * `registration_count`: it is guarded on the model and written only inside the
 * locked registration/seat-release transactions.
 */
abstract class OfferingFormRequest extends BaseFormRequest
{
    /**
     * The tenant this request acts on. Server-derived from TenantContext, which
     * ResolveMasjidTenant has already bound for this route (a SuperAdmin on
     * /masjids/{id}/... is bound to the masjid the URL names, per
     * .claude/rules/tenant-scoping.md). The route parameter is only a fallback
     * for the unbound case; it cannot widen access, because reaching another
     * masjid's route is already a 403.
     */
    protected function tenantId(): int
    {
        return (int) (app(TenantContext::class)->get() ?? $this->route('masjid_id'));
    }

    /**
     * Per-masjid slug uniqueness, matching the (masjid_id, slug) index. The
     * check deliberately spans SOFT-DELETED offerings, exactly as the index
     * does: a deleted offering keeps its slug because its registrations and
     * payments are retained, so reusing the handle must fail as a clean 422
     * rather than a duplicate-key 500.
     */
    protected function uniqueSlugRule(?int $ignoreId = null): Unique
    {
        $rule = Rule::unique('offerings', 'slug')->where('masjid_id', $this->tenantId());

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }

    /**
     * A related row must belong to THIS organization. Validated rather than
     * resolved-and-404'd because these arrive as body fields: a foreign or
     * soft-deleted form/group is a 422 on the field that named it, never a
     * cross-tenant write.
     */
    protected function ownedRule(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('masjid_id', $this->tenantId())
            ->whereNull('deleted_at');
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may contain only lowercase letters, numbers and single hyphens.',
            'slug.unique' => 'Another offering in this organization already uses that slug.',
            'intake_form_id.exists' => 'That intake form does not belong to this organization.',
            'group_id.exists' => 'That roster group does not belong to this organization.',
        ];
    }
}
