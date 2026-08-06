<?php

namespace App\Models\Concerns;

use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-isolation trait for CRM models keyed by masjid_id.
 *
 * Applying this trait gives a model two guarantees, both driven by the
 * request-scoped App\Support\TenantContext:
 *
 *   1. Global scope — when a tenant is BOUND, every query is constrained to
 *      that masjid. When the context is UNBOUND (system jobs, seeders, the
 *      public/unauthenticated mobile API, and admin routes that name no masjid)
 *      NO constraint is added, so those callers see every masjid's rows.
 *
 *      Note what UNBOUND does NOT mean: it is not "the caller is a SuperAdmin".
 *      On a masjid-scoped admin route, ResolveMasjidTenant binds a SuperAdmin to
 *      the masjid the URL names, so /masjids/5/... is filtered to masjid 5 for
 *      them too. Unbound is about the ROUTE, not the role. See
 *      .claude/rules/tenant-scoping.md and SuperAdminExportScopeTest.
 *
 *   2. Creating hook — when a tenant is BOUND, masjid_id is stamped from the
 *      context and OVERRIDES anything the caller supplied. masjid_id is always
 *      server-derived; client-supplied masjid_id is never trusted, so a
 *      MasjidAdmin cannot create a row in another masjid. When UNBOUND the hook
 *      does nothing and the caller (super/system code) is responsible for
 *      setting masjid_id itself.
 *
 * MySQL has no row-level security, so this app-layer scope plus its Feature
 * tests are the only cross-tenant backstop — see .claude/rules/tenant-scoping.md.
 */
trait BelongsToMasjid
{
    /** Identifier for the tenant global scope; also used to bypass it. */
    public const MASJID_TENANT_SCOPE = 'masjid_tenant';

    public static function bootBelongsToMasjid(): void
    {
        static::addGlobalScope(self::MASJID_TENANT_SCOPE, function (Builder $builder): void {
            $tenant = app(TenantContext::class);

            // Unbound context adds no constraint on purpose (super/public).
            if ($tenant->hasTenant()) {
                $builder->where(
                    $builder->getModel()->qualifyColumn('masjid_id'),
                    $tenant->get()
                );
            }
        });

        static::creating(function (Model $model): void {
            $tenant = app(TenantContext::class);

            // Server-derived: a bound tenant always wins over client input.
            if ($tenant->hasTenant()) {
                $model->setAttribute('masjid_id', $tenant->get());
            }
        });
    }

    /**
     * Query the model WITHOUT the tenant scope. Documented bypass for
     * super-admin / system jobs / reporting that must cross masjids.
     */
    public static function withoutMasjidScope(): Builder
    {
        return static::withoutGlobalScope(self::MASJID_TENANT_SCOPE);
    }

    public function masjid(): BelongsTo
    {
        return $this->belongsTo(Masjid::class);
    }
}
