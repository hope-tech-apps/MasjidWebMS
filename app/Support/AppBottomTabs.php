<?php

namespace App\Support;

use App\Models\{AppBottomTab, Masjid, MobileAppFeature};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Organisation-only preferences. Home belongs to the native app, never to this stored list. */
final class AppBottomTabs
{
    /** Read the grant from an existing organisation row without another query. */
    public static function enabled(?Masjid $org): bool
    {
        return $org !== null && ! $org->trashed() && $org->hasCapability('app_bottom_tabs');
    }

    /** No Home row exists in today's catalogue; reserve its explicit identity if one is added. */
    public static function isHome(object $feature): bool
    {
        return $feature->key === 'home' || $feature->name === 'Home';
    }

    /**
     * One authoritative joined query even with a warm legacy cache. This checks the current grant,
     * live organisation, catalogue and THIS organisation's pivot without inheriting parent settings.
     * Call only after an already-loaded/cached organisation has opted in; OFF performs no SQL.
     *
     * @return list<array{id: int, key: string, name: string}>|null
     */
    public static function mobile(int $orgId): ?array
    {
        $rows = DB::table('masjids as org')
            ->leftJoin('app_bottom_tabs as tabs', 'tabs.masjid_id', '=', 'org.id')
            ->leftJoin('mobile_app_features as feature', 'feature.id', '=', 'tabs.feature_id')
            ->leftJoin('masjid_mobile_app_features as pivot', function ($join) {
                $join->on('pivot.masjid_id', '=', 'org.id')->on('pivot.feature_id', '=', 'feature.id');
            })
            ->where('org.id', $orgId)->whereNull('org.deleted_at')->orderBy('tabs.position')
            ->get(['org.id as org_id', 'org.org_type', 'org.capability_overrides', 'feature.id', 'feature.key', 'feature.name', 'pivot.is_available']);
        if ($rows->isEmpty()) return null;
        $org = (new Masjid)->newFromBuilder(['id' => $orgId, 'org_type' => $rows[0]->org_type, 'capability_overrides' => $rows[0]->capability_overrides]);
        if (! self::enabled($org)) return null;
        $out = [];
        foreach ($rows as $row) {
            if ($row->id === null || ! $row->is_available || self::isHome($row)) continue;
            if (isset($out[(int) $row->id])) continue;
            $out[(int) $row->id] = ['id' => (int) $row->id, 'key' => $row->key, 'name' => $row->name];
            if (count($out) === 4) break;
        }
        return $out === [] ? null : array_values($out);
    }

    /** Read the tenant-scoped editor, including retained unavailable choices in their saved order. */
    public static function editor(): array
    {
        $orgId = app(TenantContext::class)->get();
        abort_unless($orgId !== null, 403);
        $features = MobileAppFeature::query()
            ->join('masjid_mobile_app_features as pivot', 'pivot.feature_id', '=', 'mobile_app_features.id')
            ->where('pivot.masjid_id', $orgId)->where('pivot.is_available', true)
            ->orderBy('mobile_app_features.id')->get(['mobile_app_features.id', 'key', 'name'])
            ->reject(fn ($feature) => self::isHome($feature))->values();
        $byId = $features->keyBy('id');
        $chosen = AppBottomTab::orderBy('position')->get()->map(function ($tab) use ($byId) {
            $live = $byId->get($tab->feature_id);
            return ['id' => $tab->id, 'feature_id' => $tab->feature_id, 'key' => $live?->key ?? $tab->saved_key,
                'name' => $live?->name ?? $tab->saved_title, 'available' => $live !== null];
        });
        return ['features' => $features, 'chosen' => $chosen];
    }

    /**
     * Replace choices atomically. The organisation PK mutex is the FIRST transaction statement;
     * validation then reads a fresh InnoDB view. Feature toggles use the same mutex while opted in.
     */
    public static function save(int $orgId, array $ids): void
    {
        abort_unless(app(TenantContext::class)->get() === $orgId, 403);
        try {
            DB::transaction(function () use ($orgId, $ids) {
                $locked = Masjid::whereKey($orgId)->lockForUpdate()->firstOrFail();
                abort_unless(self::enabled($locked), 404);
                $ids = array_map('intval', $ids);
                if (count($ids) > 4 || count(array_unique($ids)) !== count($ids)) {
                    throw ValidationException::withMessages(['feature_ids' => ['Choose up to four different features.']]);
                }
                $features = MobileAppFeature::query()
                    ->join('masjid_mobile_app_features as pivot', 'pivot.feature_id', '=', 'mobile_app_features.id')
                    ->where('pivot.masjid_id', $orgId)->where('pivot.is_available', true)
                    ->whereIn('mobile_app_features.id', $ids)->get(['mobile_app_features.id', 'key', 'name'])->keyBy('id');
                foreach ($ids as $id) {
                    if (! isset($features[$id]) || self::isHome($features[$id])) {
                        throw ValidationException::withMessages(['feature_ids' => ['That feature is no longer available. Choose from this organisation’s switched-on app features. Home is always first.']]);
                    }
                }
                AppBottomTab::query()->delete();
                foreach ($ids as $position => $id) {
                    AppBottomTab::create(['masjid_id' => $orgId, 'feature_id' => $id, 'position' => $position + 1,
                        'saved_key' => $features[$id]->key, 'saved_title' => $features[$id]->name]);
                }
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1452 || str_contains($e->getMessage(), 'FOREIGN KEY constraint failed')) {
                throw ValidationException::withMessages(['feature_ids' => ['That feature is no longer available. Reload and choose again.']]);
            }
            throw $e;
        }
        // Choices themselves are always read fresh. Flush only legacy caches already opted in/used.
        MobileCache::flushMasjid($orgId, MobileCache::FEATURES);
        MobileCache::flushMasjid($orgId, MobileCache::SHOW);
    }
}
