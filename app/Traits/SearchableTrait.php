<?php

namespace App\Traits;

use App\Support\PublicTenant;
use Illuminate\Http\Exceptions\HttpResponseException;

trait SearchableTrait
{
    /**
     * Search records where any of the specified fields match the search term
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $like Search term
     * @param array|null $fields Fields to search (falls back to model's searchableFields)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearchLike($query, ?string $like = null, ?array $fields = null)
    {

        $fields = $fields ?? ($this->searchableFields ?? []);

        if (empty($like) || empty($fields)) {
            return $query;
        }

        return $query->where(function ($q) use ($like, $fields) {
            foreach ($fields as $field) {
                $q->orWhere($field, 'LIKE', "%{$like}%");
            }
        });

    }

    /**
     * Constrain a public /api/v1 query to the tenant named by the `masjid-id`
     * header — and REFUSE the request when no tenant is named.
     *
     * ## Why this throws instead of returning the query untouched
     *
     * It used to read `if ($resourceId) $query->where(...)`, so a request with
     * no header — or a falsy one like `masjid-id: 0` — added no WHERE clause at
     * all. `Announcement`, `Service`, `Page` and `Section` do NOT use
     * BelongsToMasjid, so nothing else scoped them: the query then returned
     * every tenant's rows. Verified against production on 2026-08-11 before the
     * fix — `/api/v1/announcements` returned 11 rows for `masjid-id: 1`, 3 for
     * `masjid-id: 13`, and 14 with the header omitted or set to `0`.
     *
     * The same reasoning as TenantResolver applies here, for the same reason:
     * MySQL has no row-level security (.claude/rules/tenant-scoping.md), so an
     * unbound query is not "slightly wrong", it is every organisation at once.
     * A scope whose whole job is isolation must not have a branch that skips
     * isolating.
     *
     * Throwing rather than returning an empty result is deliberate: an empty
     * list would read as "this masjid has no announcements" and hide the
     * caller's bug, and every legitimate client already sends the header. The
     * 400 shape matches the contract ZakatCalculatorController and
     * FormSubmissionsController already give for a missing header, so the
     * public API answers a tenant-less request one way everywhere.
     *
     * ## And why NAMING a tenant is not the same as VERIFYING one
     *
     * The fix above made the WHERE unconditional. It did not ask whether the id
     * in the header still names an organisation — and `masjids` SOFT-deletes, so
     * an offboarded organisation's id goes on matching its own announcements,
     * services, pages and sections forever. An organisation that has been
     * offboarded kept publishing: `/api/v1/pages/{slug}`, `/api/v1/announcements`
     * and `/api/v1/services` all answered 200 with its content.
     *
     * That is the SAME omission — tenant from a header, existence never checked
     * — that let a soft-deleted organisation take registrations and open Stripe
     * Checkout Sessions on the offering endpoints (App\Support\PublicTenant,
     * PublicTenantLifecycleTest). It is fixed in one place here because these
     * four models have one public entry point.
     *
     * The refusal is a 404 rather than a 400: the caller named an organisation
     * perfectly well, there is simply no longer one there. Same words as any
     * other public miss, so this is not a way to enumerate which tenant ids are
     * live. Note that being UNLISTED is not being gone — `listed_at` gates the
     * mobile directory only, and an unlisted organisation's pages must keep
     * serving (Masjid::scopeListed).
     */
    public function scopeFilterByMasjid($query)
    {
        $resourceId = (int) request()->header('masjid-id');

        if ($resourceId <= 0) {
            throw new HttpResponseException(
                response()->api(400, 'A masjid must be specified.', null)
            );
        }

        if (! PublicTenant::exists($resourceId)) {
            throw new HttpResponseException(
                response()->api(404, 'That organization was not found.', null)
            );
        }

        return $query->where('masjid_id', $resourceId);
    }
}
