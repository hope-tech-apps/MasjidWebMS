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
     * @return list<array{id: int|null, key: string, name: string}>|null
     */
    public static function mobile(int $orgId, bool $withVolunteer = false, ?array $volunteerSnapshot = null): ?array
    {
        $rows = DB::table('masjids as org')
            ->leftJoin('app_bottom_tabs as tabs', 'tabs.masjid_id', '=', 'org.id')
            ->when($withVolunteer,fn($q)=>$q->leftJoin('app_volunteer_settings as volunteer', 'volunteer.masjid_id', '=', 'org.id'))
            ->leftJoin('mobile_app_features as feature', 'feature.id', '=', 'tabs.feature_id')
            ->leftJoin('masjid_mobile_app_features as pivot', function ($join) {
                $join->on('pivot.masjid_id', '=', 'org.id')->on('pivot.feature_id', '=', 'feature.id');
            })
            ->where('org.id', $orgId)->whereNull('org.deleted_at')->orderBy('tabs.position')
            ->get(['org.id as org_id', 'org.org_type', 'org.capability_overrides', 'feature.id', 'feature.key', 'feature.name', 'pivot.is_available', 'tabs.saved_key', ...($withVolunteer ? ['volunteer.title as volunteer_title', 'volunteer.url as volunteer_url'] : [])]);
        if ($rows->isEmpty()) return null;
        $org = (new Masjid)->newFromBuilder(['id' => $orgId, 'org_type' => $rows[0]->org_type, 'capability_overrides' => $rows[0]->capability_overrides]);
        if (! self::enabled($org)) return null;
        $out = [];
        foreach ($rows as $row) {
            if ($row->saved_key === 'menu') {
                $out['menu'] = ['id'=>null,'key'=>'menu','name'=>'Menu'];
                if (count($out) === 4) break;
                continue;
            }
            if ($row->saved_key === 'volunteer') {
                if (!$withVolunteer || $volunteerSnapshot === null || !AppVolunteer::enabled($org) || !filled($row->volunteer_url)) continue;
                $out['volunteer'] = ['id'=>null, 'key'=>'volunteer', 'name'=>$volunteerSnapshot['title']];
                if (count($out) === 4) break;
                continue;
            }
            if ($row->id === null || ! $row->is_available || (self::isHome($row) || in_array($row->key,['menu','volunteer'],true))) continue;
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
            ->reject(fn ($feature) => self::isHome($feature) || in_array($feature->key,['menu','volunteer'],true))->values();
        $features->push((object)['id'=>'menu','key'=>'menu','name'=>'Menu']);
        $org = ClassSubjectMode::loadedOrganisation($orgId) ?? Masjid::findOrFail($orgId);
        $volunteer = AppVolunteer::payload($org);
        if ($volunteer !== null) $features->push((object)['id'=>'volunteer','key'=>'volunteer','name'=>$volunteer['title']]);
        $byId = $features->keyBy('id');
        $chosen = AppBottomTab::orderBy('position')->get()->map(function ($tab) use ($byId) {
            $live = $byId->get(in_array($tab->saved_key,['menu','volunteer'],true) ? $tab->saved_key : $tab->feature_id);
            return ['id' => $tab->id, 'feature_id' => in_array($tab->saved_key,['menu','volunteer'],true) ? $tab->saved_key : $tab->feature_id, 'key' => $live?->key ?? $tab->saved_key,
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
                $ids = array_map(fn ($id) => in_array($id,['menu','volunteer'],true) ? $id : (int)$id, $ids);
                if (count($ids) > 4 || count(array_unique($ids)) !== count($ids)) {
                    throw ValidationException::withMessages(['feature_ids' => ['Choose up to four different features.']]);
                }
                $features = MobileAppFeature::query()
                    ->join('masjid_mobile_app_features as pivot', 'pivot.feature_id', '=', 'mobile_app_features.id')
                    ->where('pivot.masjid_id', $orgId)->where('pivot.is_available', true)
                    ->whereIn('mobile_app_features.id', array_filter($ids,fn($id)=>!in_array($id,['menu','volunteer'],true)))->get(['mobile_app_features.id', 'key', 'name'])->keyBy('id');
                if (in_array('volunteer',$ids,true)) {
                    $volunteer=AppVolunteer::payload($locked);
                    if ($volunteer !== null) $features->put('volunteer',(object)['id'=>null,'key'=>'volunteer','name'=>$volunteer['title']]);
                }
                $features->put('menu',(object)['id'=>null,'key'=>'menu','name'=>'Menu']);
                foreach ($ids as $id) {
                    if (! isset($features[$id]) || (!in_array($id,['menu','volunteer'],true) && (self::isHome($features[$id]) || in_array($features[$id]->key,['menu','volunteer'],true)))) {
                        throw ValidationException::withMessages(['feature_ids' => ['That feature is no longer available. Choose from this organisation’s switched-on app features. Home is always first.']]);
                    }
                }
                AppBottomTab::query()->delete();
                foreach ($ids as $position => $id) {
                    AppBottomTab::create(['masjid_id' => $orgId, 'feature_id' => in_array($id,['menu','volunteer'],true) ? null : $id, 'position' => $position + 1,
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
        DB::afterCommit(fn () => MobileCache::flushMasjid($orgId, MobileCache::SHOW));
    }
}
