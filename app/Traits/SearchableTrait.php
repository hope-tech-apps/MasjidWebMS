<?php

namespace App\Traits;

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
     */
    public function scopeFilterByMasjid($query)
    {
        $resourceId = (int) request()->header('masjid-id');

        if ($resourceId <= 0) {
            throw new HttpResponseException(
                response()->api(400, 'A masjid must be specified.', null)
            );
        }

        return $query->where('masjid_id', $resourceId);
    }
}
