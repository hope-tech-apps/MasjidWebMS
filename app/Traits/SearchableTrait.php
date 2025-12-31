<?php

namespace App\Traits;

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

    public function scopeFilterByMasjid($query)
    {
        $resourceId = request()->header('masjid-id');
        if ($resourceId) $query->where('masjid_id', request()->header('masjid-id'));
    }
}
