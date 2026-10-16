<?php

namespace Tests\Feature;

use App\Support\DeadlockRetryingRateLimiter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Parallel requests sharing one throttle key can deadlock on the database cache's
 * `insert ignore`. With the switch on, the limiter retries the deadlock and the
 * request succeeds; with it off, nothing changes.
 */
class RateLimiterDeadlockRetryTest extends TestCase
{
    #[Test]
    public function a_deadlocked_hit_is_retried_and_counted_once(): void
    {
        $store = new FlakyAddStore(failures: 2);
        $limiter = new DeadlockRetryingRateLimiter(new Repository($store));

        $this->assertSame(1, $limiter->hit('family|contact', 60));
        $this->assertSame(1, (int) $limiter->attempts('family|contact'));
    }

    #[Test]
    public function it_gives_up_after_three_attempts(): void
    {
        $store = new FlakyAddStore(failures: 3);
        $limiter = new DeadlockRetryingRateLimiter(new Repository($store));

        $this->expectException(QueryException::class);
        try {
            $limiter->hit('family|contact', 60);
        } finally {
            $this->assertSame(DeadlockRetryingRateLimiter::ATTEMPTS, $store->failedAdds);
        }
    }

    #[Test]
    public function other_database_errors_are_not_retried(): void
    {
        $store = new FlakyAddStore(failures: 1, message: 'SQLSTATE[42S02]: Base table or view not found');
        $limiter = new DeadlockRetryingRateLimiter(new Repository($store));

        $this->expectException(QueryException::class);
        try {
            $limiter->hit('family|contact', 60);
        } finally {
            $this->assertSame(1, $store->failedAdds);
        }
    }

    #[Test]
    public function a_deadlock_inside_an_open_transaction_is_not_retried(): void
    {
        $store = new FlakyAddStore(failures: 1);
        $limiter = new DeadlockRetryingRateLimiter(new Repository($store));

        DB::beginTransaction();
        try {
            $limiter->hit('family|contact', 60);
            $this->fail('The deadlock should have been rethrown.');
        } catch (QueryException) {
            $this->assertSame(1, $store->failedAdds);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function the_switch_decides_which_limiter_the_app_uses(): void
    {
        config(['cache.limiter_deadlock_retry' => false]);
        $this->app->forgetInstance(RateLimiter::class);
        $this->assertNotInstanceOf(DeadlockRetryingRateLimiter::class, $this->app->make(RateLimiter::class));

        config(['cache.limiter_deadlock_retry' => true]);
        $this->app->forgetInstance(RateLimiter::class);
        $this->assertInstanceOf(DeadlockRetryingRateLimiter::class, $this->app->make(RateLimiter::class));
    }

    #[Test]
    public function a_throttled_route_answers_200_through_a_deadlock_when_switched_on(): void
    {
        Route::middleware('throttle:5,1')->get('/api/__limiter-deadlock-probe', fn () => 'ok');

        $store = new FlakyAddStore(failures: 1);
        $this->app->instance(RateLimiter::class, new DeadlockRetryingRateLimiter(new Repository($store)));
        $this->get('/api/__limiter-deadlock-probe')->assertOk()->assertContent('ok');
        $this->assertSame(1, $store->failedAdds);

        // The framework's own limiter, as production runs with the switch off.
        $this->app->instance(RateLimiter::class, new RateLimiter(
            new Repository(new FlakyAddStore(failures: 1))
        ));
        $this->get('/api/__limiter-deadlock-probe')->assertStatus(500);
    }
}

/** An array cache whose first `add` calls fail the way MySQL reports a deadlock victim. */
class FlakyAddStore extends ArrayStore
{
    public int $failedAdds = 0;

    public function __construct(
        private int $failures,
        private string $message = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction',
    ) {
        parent::__construct();
    }

    public function add($key, $value, $seconds)
    {
        if ($this->failures > 0) {
            $this->failures--;
            $this->failedAdds++;
            throw new QueryException('mysql', 'insert ignore into `cache` (`key`, `value`, `expiration`) values (?, ?, ?)', [], new PDOException($this->message));
        }

        if ($this->get($key) !== null) {
            return false;
        }

        return $this->put($key, $value, $seconds);
    }
}
