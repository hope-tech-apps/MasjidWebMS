<?php

namespace App\Support\Guides;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GuideAskLimits
{
    /** Held through the paid call; no database transaction spans that call. */
    public function inFlight(int $person): ?Lock
    {
        $lock = Cache::store(config('cache.limiter'))->lock('guide-ask:in-flight:'.$person, (int) ceil((float) config('guide_ask.timeout')) + 10);
        return $lock->get() ? $lock : null;
    }

    /** The caller holds the per-person lock while checking the rolling minute. */
    public function reserve(int $person, int $organisation): ?string
    {
        $limiter = new RateLimiter(Cache::store(config('cache.limiter')));
        $key = 'guide-ask:person:'.$person;
        $maximum = (int) config('guide_ask.person_per_minute');
        if ($maximum <= 0 || $limiter->tooManyAttempts($key, $maximum)) return 'person';
        $now = now()->utc();
        if (! $this->reserveBucket('org:'.$organisation, $now->format('Y-m-d'), (int) config('guide_ask.organisation_per_day'))) return 'organisation';
        if (! $this->reserveBucket('platform', $now->startOfMonth()->format('Y-m-d'), (int) config('guide_ask.platform_per_month'))) return 'platform';
        $limiter->hit($key, 60);
        return null;
    }

    /** One conditional statement, including first insertion; never a read/check/write. */
    public function reserveBucket(string $scope, string $period, int $maximum): bool
    {
        if ($maximum <= 0) return false;
        $connection = DB::connection();
        $sql = match ($connection->getDriverName()) {
            'sqlite' => 'insert into guide_ask_counters (scope_key, period, count) values (?, ?, 1) on conflict (scope_key, period) do update set count = count + 1 where count < ?',
            // Default PDO MySQL affected rows: insert=1, increment=2, unchanged=0.
            'mysql' => 'insert into guide_ask_counters (scope_key, period, count) values (?, ?, 1) on duplicate key update count = IF(count < ?, count + 1, count)',
            default => throw new RuntimeException('Guide spending database unsupported'),
        };
        return $connection->affectingStatement($sql, [$scope, $period, $maximum]) > 0;
    }
}
