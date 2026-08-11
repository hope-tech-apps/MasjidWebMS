<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Services\Registrations\RegistrationService;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-006f — THE capacity-under-concurrency test the ratified design names:
 * N parallel last-seat attempts must yield exactly one held seat, and capacity
 * must never be exceeded.
 *
 * BE HONEST ABOUT WHAT EACH ARM PROVES. There are two, because no single test
 * can prove both halves of the invariant in this suite:
 *
 * 1. `n_parallel_last_seat_attempts_…` — REAL parallelism. It forks N OS
 *    processes that each open their OWN connection and race the same last seat
 *    at the same instant. This is the only arm that proves the DATABASE
 *    actually serializes the transactions, because that property lives in the
 *    engine, not in our code. It therefore needs a driver with real row locks
 *    and a scratch database, and it SKIPS CLEANLY otherwise — the suite default
 *    is SQLite in-memory, which has no `SELECT … FOR UPDATE` and no shared
 *    connections, so it cannot express this test at all. The skip message
 *    carries the exact command to run it for real.
 *
 * 2. `the_capacity_re_check_…` / `the_seat_release_…` — DETERMINISTIC, and they
 *    run everywhere. They prove the two properties that DO live in our code and
 *    that arm 1 would only catch by luck:
 *      a) the counter is read WITH a row lock requested and re-checked, and the
 *         write happens in the SAME transaction as that locked read — a lock
 *         taken outside the transaction, or a re-check before it, protects
 *         nothing;
 *      b) the counter moves by a RELATIVE update (`= registration_count ± 1`),
 *         never a read-modify-write of a value fetched earlier, which would
 *         lose updates even under a correct lock.
 *    Because SQLite's grammar compiles the lock clause away (it has no row
 *    locks), these tests temporarily swap in a grammar that renders the clause
 *    as an inert SQL COMMENT — semantically nothing, but observable. That makes
 *    "did this code path ASK for a row lock?" provable on any driver.
 *
 * WHAT ARM 2 DOES NOT PROVE: that the database honours the lock. SQLite does
 * not. Delete `lockForUpdate()` from RegistrationService and arm 2 fails
 * (the marker disappears); keep it but run on a driver that ignores it and arm
 * 2 still passes while real capacity could be exceeded. Only arm 1 closes that,
 * and only when someone runs it. That limitation is stated rather than papered
 * over: sequential calls dressed up as "concurrency" would prove less and claim
 * more.
 */
class RegistrationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** How many registrants pile onto the last seat in the parallel arm. */
    private const RACERS = 8;

    /**
     * The marker the lock-rendering grammar emits. An SQL comment: inert to
     * every engine, visible to DB::listen. PUBLIC because the anonymous
     * grammar below is a separate class and cannot reach a private constant.
     */
    public const LOCK_MARKER = '/* lock:for-update */';

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid();
    }

    // --------------------------------------------- arm 1: real parallelism

    #[Test]
    public function n_parallel_last_seat_attempts_yield_exactly_one_seat_and_never_exceed_capacity(): void
    {
        $connection = $this->parallelConnection();

        $default = config('database.default');

        try {
            config(['database.default' => $connection]);
            Artisan::call('migrate:fresh', ['--database' => $connection, '--force' => true]);

            $masjid = $this->makeMasjid();

            // ONE seat, nobody in it. Every racer below is going for it.
            $offering = Offering::factory()->forMasjid($masjid)->create([
                'capacity' => 1,
                'registration_count' => 0,
            ]);
            $plan = FeePlan::factory()->create([
                'masjid_id' => $masjid->id,
                'offering_id' => $offering->id,
            ]);

            $contacts = [];
            for ($i = 0; $i < self::RACERS; $i++) {
                $contacts[] = Contact::factory()->create(['masjid_id' => $masjid->id]);
            }

            $errorDir = sys_get_temp_dir() . '/manara-concurrency-' . getmypid();
            @mkdir($errorDir, 0777, true);

            // Close the parent's PDO BEFORE forking. A forked child inherits the
            // parent's socket, and the first child to close it would send
            // COM_QUIT down the shared file description and kill the parent's
            // session. With no live handle to inherit, every child opens its own
            // on first query — which is what "parallel" has to mean here.
            DB::disconnect($connection);

            // A wall-clock barrier: children sleep until the same instant, so
            // they contend for real instead of trickling in.
            $startAt = microtime(true) + 0.5;

            $pids = [];

            for ($i = 0; $i < self::RACERS; $i++) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    $this->fail('pcntl_fork() failed; cannot run the parallel arm.');
                }

                if ($pid === 0) {
                    $this->raceForTheSeat($offering, $plan, $contacts[$i], $startAt, "{$errorDir}/{$i}.err");
                }

                $pids[] = $pid;
            }

            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }

            $errors = array_map('file_get_contents', glob($errorDir . '/*.err') ?: []);
            array_map('unlink', glob($errorDir . '/*.err') ?: []);
            @rmdir($errorDir);

            $this->assertSame([], $errors, "A racer failed outright:\n" . implode("\n", $errors));

            $registrations = Registration::withoutMasjidScope()
                ->where('offering_id', $offering->id)
                ->get();

            // Every attempt produced a row: nobody was lost to a deadlock or a
            // dropped connection, so the counts below are over all N racers.
            $this->assertCount(self::RACERS, $registrations);

            // THE invariant: exactly one seat holder, whatever the interleaving.
            $held = $registrations->whereIn('status', [
                Registration::STATUS_PENDING,
                Registration::STATUS_CONFIRMED,
            ]);

            $this->assertCount(1, $held, 'More than one racer holds the last seat.');
            $this->assertCount(self::RACERS - 1, $registrations->where('status', Registration::STATUS_WAITLISTED));

            // Capacity may NEVER be exceeded, and the counter must agree with
            // the rows: one seat sold, one seat counted.
            $fresh = Offering::withoutMasjidScope()->whereKey($offering->id)->first();
            $this->assertSame(1, (int) $fresh->capacity);
            $this->assertSame(1, (int) $fresh->registration_count);

            // Nobody pays for a seat they don't hold.
            foreach ($registrations->where('status', Registration::STATUS_WAITLISTED) as $loser) {
                $this->assertSame(Registration::PAYMENT_NONE, $loser->payment_status);
                $this->assertNull($loser->checkout_expires_at);
            }
        } finally {
            config(['database.default' => $default]);
        }
    }

    /**
     * The child half of the race. Never returns: it terminates with SIGKILL so
     * PHPUnit's shutdown handlers cannot run a second time in the child and
     * corrupt the parent's report. Failures go to a file the parent reads —
     * an exception thrown in a child would be invisible.
     */
    private function raceForTheSeat(
        Offering $offering,
        FeePlan $plan,
        Contact $contact,
        float $startAt,
        string $errorFile
    ): never {
        $sleep = (int) (($startAt - microtime(true)) * 1_000_000);

        if ($sleep > 0) {
            usleep($sleep);
        }

        try {
            // No query has run in this process yet, so this opens a fresh
            // connection of its own.
            app(RegistrationService::class)->register(
                $offering,
                $plan,
                $contact,
                ['full_name' => 'Racer ' . $contact->id]
            );
        } catch (\Throwable $e) {
            // Losing the seat is NOT an error (it waitlists). Anything that
            // throws is a genuine failure — a deadlock, a lost connection — and
            // the parent must see it.
            file_put_contents($errorFile, get_class($e) . ': ' . $e->getMessage());
        }

        posix_kill(posix_getpid(), SIGKILL);

        exit(0);    // unreachable; keeps the `never` return type honest
    }

    // ------------------------------------------ arm 2: the lock path itself

    #[Test]
    public function the_capacity_re_check_and_the_increment_happen_inside_one_locked_transaction(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create([
            'capacity' => 2,
            'registration_count' => 1,
        ]);
        $plan = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);
        $contact = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        $trace = $this->traceWithLocksRendered(function () use ($offering, $plan, $contact) {
            app(RegistrationService::class)->register($offering, $plan, $contact, ['full_name' => 'Racer']);
        });

        $lockedRead = $this->indexOf($trace, fn (array $q) => str_contains($q['sql'], 'select')
            && str_contains($q['sql'], 'offerings')
            && str_contains($q['sql'], self::LOCK_MARKER));

        $this->assertNotNull($lockedRead, 'The offering row is not read FOR UPDATE — the capacity re-check protects nothing.');

        $increment = $this->indexOf($trace, fn (array $q) => $this->isRelativeCounterWrite($q['sql'], '+'));

        $this->assertNotNull($increment, 'registration_count is not incremented by a relative UPDATE; a read-modify-write loses updates.');

        // Order matters as much as presence: the lock must be taken BEFORE the
        // counter is written, or a racer can slip between the two.
        $this->assertLessThan($increment, $lockedRead);

        // And both must sit inside the SAME transaction the lock belongs to —
        // a lock released at the end of its own statement protects nothing.
        $this->assertGreaterThan($trace[$lockedRead]['baseline'], $trace[$lockedRead]['level']);
        $this->assertSame($trace[$lockedRead]['level'], $trace[$increment]['level']);

        $this->assertSame(2, $offering->fresh()->registration_count);
    }

    #[Test]
    public function the_seat_release_locks_the_registration_and_the_offering_and_decrements_relatively(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create([
            'capacity' => 5,
            'registration_count' => 0,
        ]);
        $plan = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);
        $contact = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        $service = app(RegistrationService::class);
        $registration = $service->register($offering, $plan, $contact, ['full_name' => 'Abandoned']);

        // The reaper releases through THIS seam, so the seam's lock discipline
        // is the reaper's lock discipline.
        $trace = $this->traceWithLocksRendered(function () use ($service, $registration) {
            $service->releaseSeat($registration);
        });

        $lockedRegistration = $this->indexOf($trace, fn (array $q) => str_contains($q['sql'], 'select')
            && str_contains($q['sql'], 'registrations')
            && str_contains($q['sql'], self::LOCK_MARKER));
        $lockedOffering = $this->indexOf($trace, fn (array $q) => str_contains($q['sql'], 'select')
            && str_contains($q['sql'], 'offerings')
            && str_contains($q['sql'], self::LOCK_MARKER));
        $decrement = $this->indexOf($trace, fn (array $q) => $this->isRelativeCounterWrite($q['sql'], '-'));

        $this->assertNotNull($lockedRegistration, 'The registration is not locked before its status is re-read.');
        $this->assertNotNull($lockedOffering, 'The offering is not locked before its counter is written.');
        $this->assertNotNull($decrement, 'registration_count is not decremented by a relative UPDATE.');

        // Registration first, then offering, then the write — the same order
        // intake takes them in, which is what keeps the two paths from
        // deadlocking against each other on a live driver.
        $this->assertLessThan($lockedOffering, $lockedRegistration);
        $this->assertLessThan($decrement, $lockedOffering);

        $this->assertGreaterThan($trace[$lockedRegistration]['baseline'], $trace[$lockedRegistration]['level']);
        $this->assertSame($trace[$lockedRegistration]['level'], $trace[$decrement]['level']);

        $this->assertSame(0, $offering->fresh()->registration_count);
    }

    // --------------------------------------------------------------- harness

    /**
     * Run $work with every `lockForUpdate()` rendered as an inert SQL comment,
     * and return the ordered statement trace (sql + transaction depth).
     *
     * SQLite's real grammar compiles the lock clause to an empty string — it
     * has no row locks — which would make an accidental removal of
     * `lockForUpdate()` from the service completely invisible here. Rendering
     * the clause as a comment changes nothing about how the statement executes
     * and makes the request for a lock observable.
     *
     * @param  \Closure():void  $work
     * @return array<int,array{sql:string,level:int,baseline:int}>
     */
    private function traceWithLocksRendered(\Closure $work): array
    {
        $connection = DB::connection();
        $original = $connection->getQueryGrammar();
        // RefreshDatabase already holds a transaction open, so "inside a
        // transaction" means deeper than where we started, not simply > 0.
        $baseline = $connection->transactionLevel();

        $trace = [];

        DB::listen(function ($query) use (&$trace, $connection, $baseline): void {
            $trace[] = [
                'sql' => $query->sql,
                'level' => $connection->transactionLevel(),
                'baseline' => $baseline,
            ];
        });

        $connection->setQueryGrammar(new class ($connection) extends SQLiteGrammar
        {
            protected function compileLock(QueryBuilder $query, $value)
            {
                if ($value === true) {
                    return RegistrationConcurrencyTest::LOCK_MARKER;
                }

                return is_string($value) ? $value : '';
            }
        });

        try {
            $work();
        } finally {
            // The in-memory connection outlives the test method, so the real
            // grammar must go back or every later test sees the marker.
            $connection->setQueryGrammar($original);
        }

        return $trace;
    }

    /**
     * Is this a RELATIVE counter write — `registration_count = registration_count ± 1`
     * — rather than a write of a value read earlier? The distinction is the
     * difference between "two racers each add one" and "the second racer
     * overwrites the first".
     */
    private function isRelativeCounterWrite(string $sql, string $sign): bool
    {
        return (bool) preg_match(
            '/update\s+\S*offerings\S*\s+set\s+.*registration_count\S*\s*=\s*\S*registration_count\S*\s*\\' . $sign . '\s*1/i',
            $sql
        );
    }

    /**
     * @param  array<int,array{sql:string,level:int,baseline:int}>  $trace
     */
    private function indexOf(array $trace, callable $matcher): ?int
    {
        foreach ($trace as $i => $entry) {
            if ($matcher($entry)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The connection to race on, or a clean skip explaining exactly how to run
     * this for real.
     *
     * Two rails, both deliberate: the connection must be named explicitly (the
     * suite default is never raced on), and its database name must look like a
     * scratch one — this test runs `migrate:fresh`, which DESTROYS whatever it
     * is pointed at.
     */
    private function parallelConnection(): string
    {
        $connection = (string) env('REGISTRATION_CONCURRENCY_CONNECTION', '');

        if ($connection === '') {
            $this->markTestSkipped(
                'Real-parallelism arm: needs a driver with row locks and a THROWAWAY database (it runs migrate:fresh). '
                . 'Configure e.g. the `mysql` connection against a scratch schema whose name contains "test", then run: '
                . 'REGISTRATION_CONCURRENCY_CONNECTION=mysql php artisan test --filter=n_parallel_last_seat_attempts. '
                . 'SQLite (the suite default) has no SELECT … FOR UPDATE and no cross-process connections, so it cannot '
                . 'express this test — see the class docblock for what the deterministic arm does and does not prove.'
            );
        }

        if (! config("database.connections.{$connection}")) {
            $this->markTestSkipped("REGISTRATION_CONCURRENCY_CONNECTION={$connection} is not a configured connection.");
        }

        $database = (string) config("database.connections.{$connection}.database");

        if (! str_contains(strtolower($database), 'test')) {
            $this->markTestSkipped(
                "Refusing to run migrate:fresh on `{$database}` — the parallel arm destroys its database, so its name "
                . 'must contain "test". Point the connection at a scratch schema.'
            );
        }

        foreach (['pcntl_fork', 'posix_kill', 'posix_getpid'] as $required) {
            if (! function_exists($required)) {
                $this->markTestSkipped("{$required}() is unavailable; real parallelism cannot be forked in this PHP build.");
            }
        }

        return $connection;
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }
}
