<?php

namespace App\Support;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The rate limiter, retrying a database deadlock instead of failing the request.
 *
 * With CACHE_STORE=database every throttled request writes its counter with
 * `insert ignore into cache`. A family screen that loads several attachments at
 * once sends parallel requests that share one throttle key (throttle:family is
 * keyed on the contact), and MySQL can pick some of those inserts as deadlock
 * victims: the request then fails with a 500 although nothing is wrong.
 *
 * MySQL rolls the victim statement back, so running the limiter call again is
 * safe and counts the hit once. We only retry when no transaction is open on
 * the cache's connection: inside one, the deadlock has already rolled back the
 * caller's earlier work, and replaying only the limiter would hide that.
 *
 * Off unless config('cache.limiter_deadlock_retry') is true (see config/cache.php).
 */
class DeadlockRetryingRateLimiter extends RateLimiter
{
    use DetectsConcurrencyErrors;

    public const ATTEMPTS = 3;

    public function increment($key, $decaySeconds = 60, $amount = 1)
    {
        return $this->retrying(fn () => parent::increment($key, $decaySeconds, $amount));
    }

    public function tooManyAttempts($key, $maxAttempts)
    {
        return $this->retrying(fn () => parent::tooManyAttempts($key, $maxAttempts));
    }

    public function attempts($key)
    {
        return $this->retrying(fn () => parent::attempts($key));
    }

    private function retrying(Closure $call)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $call();
            } catch (QueryException $e) {
                if ($attempt >= self::ATTEMPTS
                    || ! $this->causedByConcurrencyError($e)
                    || $this->insideTransaction()) {
                    throw $e;
                }
                // A short random pause so the parallel requests that collided
                // do not collide again on the retry.
                usleep(random_int(5_000, 25_000) * $attempt);
            }
        }
    }

    private function insideTransaction(): bool
    {
        $connection = config('cache.stores.database.connection');

        return DB::connection($connection)->transactionLevel() > 0;
    }
}
