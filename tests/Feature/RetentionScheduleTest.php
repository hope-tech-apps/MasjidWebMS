<?php

namespace Tests\Feature;

use App\Models\BehaviorAward;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupPost;
use App\Models\GroupThread;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Retention was CONFIGURED AND UNENFORCED.
 *
 * config/groups.php sets a `retention_days` window for the feed (~:29), the
 * messaging threads (~:65) and the behaviour awards (~:112); every row those
 * windows govern is stamped with a `retained_until` on create; and
 * `groups:purge-feed` sweeps all three tables. What did not exist was anything
 * that ever RAN it — routes/console.php registered only the Sanctum prune and
 * the two prayer tasks. `registrations:reap-expired` was in the same position.
 *
 * Both commands' docblocks called the cadence "an operator decision", which
 * reads as deference and functioned as a promise nothing kept. These delete
 * MINORS' records on a timer; a retention policy no cron invokes is not a
 * policy.
 *
 * ## What this file pins, and what it deliberately does not
 *
 * The per-table sweep behaviour — window honoured, soft-deleted rows included,
 * `--masjid=` narrowing, `--dry-run` writing nothing, images reaching the disk —
 * is already covered per model in GroupFeedTenantIsolationTest,
 * GroupMessagingTenantIsolationTest and BehaviorTenantIsolationTest, and the
 * reaper's seat accounting in RegistrationReaperTest. None of that is repeated.
 *
 * What was untested is the part that was actually missing: that the commands
 * are ON THE SCHEDULE, and that running a sweep twice — which a daily cron will
 * do the moment a run overlaps a retry or an operator triggers one by hand — is
 * harmless.
 */
class RetentionScheduleTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;

    private Masjid $masjidB;

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

        // A console sweep runs UNBOUND, exactly as the real command does.
        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid '.uniqid(),
            'email' => 'masjid-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    // ------------------------------------------------------------ scheduling

    /** @return array<int, Event> */
    private function scheduledEvents(): array
    {
        // Resolving the Schedule requires the console kernel to have been
        // bootstrapped, which is what loads routes/console.php. Calling any
        // command does that.
        Artisan::call('list', ['--raw' => true]);

        return app(Schedule::class)->events();
    }

    private function scheduledEventFor(string $command): ?Event
    {
        foreach ($this->scheduledEvents() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        return null;
    }

    #[Test]
    public function the_group_retention_sweep_is_actually_scheduled(): void
    {
        $event = $this->scheduledEventFor('groups:purge-feed');

        $this->assertNotNull(
            $event,
            'groups:purge-feed is not on the schedule, so no retention window in config/groups.php is ever enforced.'
        );

        // Daily: five fields, minute and hour both fixed, day/month/weekday all
        // wildcards. Asserted structurally rather than as a literal string so
        // moving the hour off 03:10 does not fail the test for no reason.
        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = explode(' ', $event->expression);

        $this->assertNotSame('*', $minute);
        $this->assertNotSame('*', $hour);
        $this->assertSame(['*', '*', '*'], [$dayOfMonth, $month, $dayOfWeek]);
    }

    #[Test]
    public function the_expired_checkout_reaper_is_actually_scheduled(): void
    {
        $this->assertNotNull(
            $this->scheduledEventFor('registrations:reap-expired'),
            'registrations:reap-expired is not on the schedule, so an abandoned checkout holds its seat forever.'
        );
    }

    #[Test]
    public function both_sweeps_refuse_to_stack_up_on_a_slow_run(): void
    {
        // A second process force-deleting the same rows, or contending on the
        // same lockForUpdate registrations, buys nothing and risks plenty.
        foreach (['groups:purge-feed', 'registrations:reap-expired'] as $command) {
            $event = $this->scheduledEventFor($command);

            $this->assertNotNull($event);
            $this->assertTrue($event->withoutOverlapping, "{$command} may overlap itself.");
        }
    }

    #[Test]
    public function the_tasks_that_were_already_scheduled_still_are(): void
    {
        // Adding to routes/console.php must not disturb what was there; the
        // prayer backstop going quiet is a silent failure by construction.
        foreach (['sanctum:prune-expired', 'prayers:send-due', 'prayers:daily-resync'] as $command) {
            $this->assertNotNull(
                $this->scheduledEventFor($command),
                "{$command} fell off the schedule."
            );
        }
    }

    // ------------------------------------------------- safe to run repeatedly

    #[Test]
    public function a_second_sweep_in_the_same_window_deletes_nothing_and_still_succeeds(): void
    {
        $due = $this->makeDueRows($this->masjidA);

        $this->assertSame(0, Artisan::call('groups:purge-feed'));

        foreach ($due as $table => $id) {
            $this->assertDatabaseMissing($table, ['id' => $id]);
        }

        // The second run is the one a daily cron makes inevitable — a retry, a
        // manual run, two schedulers. It must be a no-op, not an error and not
        // a second pass at rows that are already gone.
        $this->assertSame(0, Artisan::call('groups:purge-feed'));

        $output = Artisan::output();
        $this->assertStringContainsString('Purged 0 post(s)', $output);
        $this->assertStringContainsString('0 thread(s)', $output);
        $this->assertStringContainsString('0 behaviour award(s)', $output);
    }

    #[Test]
    public function a_sweep_with_nothing_to_do_is_a_success_not_a_failure(): void
    {
        // The state on the day this was scheduled: zero rows anywhere near a
        // retention window. A cron that starts erroring on an empty table would
        // be discovered by an alert storm, not by a test.
        $this->assertSame(0, Artisan::call('groups:purge-feed'));
        $this->assertSame(0, Artisan::call('registrations:reap-expired'));
    }

    #[Test]
    public function a_narrowed_sweep_run_twice_still_never_reaches_the_other_organization(): void
    {
        $mine = $this->makeDueRows($this->masjidA);
        $theirs = $this->makeDueRows($this->masjidB);

        Artisan::call('groups:purge-feed', ['--masjid' => $this->masjidA->id]);
        Artisan::call('groups:purge-feed', ['--masjid' => $this->masjidA->id]);

        foreach ($mine as $table => $id) {
            $this->assertDatabaseMissing($table, ['id' => $id]);
        }

        // Idempotence must not be bought by widening the query on a re-run.
        foreach ($theirs as $table => $id) {
            $this->assertDatabaseHas($table, ['id' => $id]);
        }
    }

    // ------------------------------------------------------------- visibility

    #[Test]
    public function the_sweep_logs_its_counts_because_cron_throws_stdout_away(): void
    {
        $this->makeDueRows($this->masjidA);

        Log::spy();

        Artisan::call('groups:purge-feed');

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                return $message === 'Group retention sweep completed.'
                    && $context['dry_run'] === false
                    && $context['posts'] === 1
                    && $context['threads'] === 1
                    && $context['behavior_awards'] === 1;
            })
            ->once();
    }

    #[Test]
    public function a_dry_run_says_so_in_the_log_rather_than_looking_like_a_real_sweep(): void
    {
        $this->makeDueRows($this->masjidA);

        Log::spy();

        Artisan::call('groups:purge-feed', ['--dry-run' => true]);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => $message === 'Group retention sweep completed.'
                && $context['dry_run'] === true
                && $context['posts'] === 1)
            ->once();
    }

    #[Test]
    public function the_reaper_logs_its_counts_too(): void
    {
        Log::spy();

        Artisan::call('registrations:reap-expired');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => $message === 'Expired-checkout reap completed.'
                && $context['swept'] === 0)
            ->once();
    }

    // -------------------------------------------------------------- fixtures

    /**
     * One row past its window in each of the three swept tables.
     *
     * @return array<string, int> table => id
     */
    private function makeDueRows(Masjid $masjid): array
    {
        // Built UNBOUND with an explicit masjid_id, the way the other group
        // suites build theirs and the way a console sweep will find them.
        $group = Group::factory()->create([
            'masjid_id' => $masjid->id,
            'name' => 'Grade 3',
            'slug' => 'grade-3-'.uniqid(),
        ]);

        $yesterday = now()->subDay()->toDateString();

        $post = GroupPost::factory()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'retained_until' => $yesterday,
        ]);

        $thread = GroupThread::factory()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'retained_until' => $yesterday,
        ]);

        $contact = Contact::factory()->create(['masjid_id' => $masjid->id, 'email' => null]);

        $membership = GroupMembership::create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'contact_id' => $contact->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $award = BehaviorAward::factory()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'group_membership_id' => $membership->id,
            'retained_until' => $yesterday,
        ]);

        return [
            'group_posts' => $post->id,
            'group_threads' => $thread->id,
            'behavior_awards' => $award->id,
        ];
    }
}
