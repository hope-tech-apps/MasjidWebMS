<?php

namespace App\Support\Guides;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class GuideAskLimits
{
    /** Reserve all three allowances together; release the lock before the slow call. */
    public function reserve(int $person, int $organisation): ?string
    {
        $cache = Cache::store(config('cache.limiter'));
        $lock = $cache->lock('guide-ask:reserve', 5);
        if (! $lock->get()) throw new RuntimeException('Guide limits busy');
        try {
            $limiter = new RateLimiter($cache);
            $now = now()->utc();
            $windows = [
                ['guide-ask:person:'.$person, (int) config('guide_ask.person_per_minute'), 60, 'person'],
                ['guide-ask:org:'.$organisation.':'.$now->format('Y-m-d'), (int) config('guide_ask.organisation_per_day'), $now->copy()->addDay()->startOfDay()->getTimestamp() - $now->getTimestamp(), 'cap'],
                ['guide-ask:platform:'.$now->format('Y-m'), (int) config('guide_ask.platform_per_month'), $now->copy()->addMonthNoOverflow()->startOfMonth()->getTimestamp() - $now->getTimestamp(), 'cap'],
            ];
            foreach ($windows as [$key, $maximum, $seconds, $kind]) {
                if ($maximum <= 0 || $limiter->tooManyAttempts($key, $maximum)) return $kind;
            }
            foreach ($windows as [$key, $maximum, $seconds]) $limiter->hit($key, max(1, $seconds));
            return null;
        } finally { $lock->release(); }
    }
}
