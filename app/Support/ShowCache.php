<?php

namespace App\Support;

use Closure;
use App\Models\Masjid;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Cache;

/** Keep the database-backed SHOW read at one cache lookup, fencing publication atomically. */
final class ShowCache
{
    public static function remember(int $orgId, Closure $load): Masjid
    {
        $repository=Cache::store();
        $store=$repository->getStore();
        $key=MobileCache::masjidKey($orgId,MobileCache::SHOW);
        if (!$store instanceof DatabaseStore) return $repository->remember($key,MobileCache::TTL_MEDIUM,$load);

        $expected=$repository->get($key);
        if ($expected instanceof Masjid) return $expected;
        $value=$load();
        $publisher=new ShowCacheDatabaseStore($store->getConnection(),config('cache.stores.'.Cache::getDefaultDriver().'.table','cache'),$store->getPrefix());
        // A concurrent invalidation replaces the token; an older fill cannot replace that row.
        $publisher->publish($key,$expected,$value,MobileCache::TTL_MEDIUM);
        return $value;
    }
}
