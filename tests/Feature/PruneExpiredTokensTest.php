<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Masjid;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\Support\ForeignTokenable;
use Tests\Support\LogsLikeProduction;
use Tests\TestCase;

/**
 * `tokens:prune-expired` replaced `sanctum:prune-expired --hours=24`, which
 * deleted every parent token about 32 hours after sign-in because it aged all
 * kinds against the STAFF lifetime (480 minutes). Parents live 30 days on the
 * `family` guard's own driver.
 *
 * Tokens are minted through the real Contact/User methods and then AGED by
 * rewriting `created_at`, so the assertions run against what the application
 * really stores, and "would a guard still accept it" is asked of the real guard.
 */
class PruneExpiredTokensTest extends TestCase
{
    use LogsLikeProduction;
    use RefreshDatabase;

    private const STAFF_MINUTES = 480;

    private const FAMILY_MINUTES = 43200;

    private const GRACE_MINUTES = 1440;

    private Masjid $masjid;

    private Contact $contact;

    private User $staff;

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

        Carbon::setTestNow('2026-09-29 12:00:00');

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid '.uniqid(),
            'email' => 'masjid-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        $this->contact = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->contact->forceFill([
            'login_email' => 'parent-'.uniqid().'@test.local',
            'login_enabled_at' => now(),
        ])->save();

        $this->staff = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** Age a minted token: rewrite created_at to `$minutes` ago. Returns its id. */
    private function aged(object $newToken, int $minutes): int
    {
        $id = (int) $newToken->accessToken->id;

        DB::table('personal_access_tokens')
            ->where('id', $id)
            ->update(['created_at' => now()->subMinutes($minutes)]);

        return $id;
    }

    private function exists(int $id): bool
    {
        return DB::table('personal_access_tokens')->where('id', $id)->exists();
    }

    private function prune(array $options = []): string
    {
        Artisan::call('tokens:prune-expired', $options);

        return Artisan::output();
    }

    private function spyMonitorsChannel(): MockInterface
    {
        Log::spy();
        $channel = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('monitors')->andReturn($channel);

        return $channel;
    }

    private function familyToken(int $ageMinutes): int
    {
        return $this->aged($this->contact->createFamilyToken(), $ageMinutes);
    }

    private function memberToken(int $ageMinutes): int
    {
        return $this->aged($this->contact->createMemberToken(), $ageMinutes);
    }

    private function staffToken(int $ageMinutes): int
    {
        return $this->aged($this->staff->createToken('login-token', ['staff']), $ageMinutes);
    }

    /** Does the REAL guard still accept this bearer token right now? */
    private function guardAccepts(string $guard, string $plainText): bool
    {
        Auth::forgetGuards();

        $request = Request::create('/probe', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainText,
        ]);

        return Auth::guard($guard)->setRequest($request)->user() !== null;
    }

    // ------------------------------------------------ the bug, as it was

    #[Test]
    public function the_stock_sanctum_command_deletes_a_two_day_old_parent_token_which_is_the_bug(): void
    {
        // CONTROL: reproduces the vulnerable behaviour so the tests below prove
        // something. A 2-day-old parent token is well inside the family guard's
        // 30 days and Sanctum's own sweep deletes it anyway.
        $id = $this->familyToken(2 * 24 * 60);

        Artisan::call('sanctum:prune-expired', ['--hours' => 24]);

        $this->assertFalse($this->exists($id), 'control: the stock command must reproduce the bug');
    }

    // ------------------------------------------------------------- family

    #[Test]
    public function a_family_token_aged_two_days_survives(): void
    {
        $id = $this->familyToken(2 * 24 * 60);

        $this->prune();

        $this->assertTrue($this->exists($id));
    }

    #[Test]
    public function a_family_token_aged_thirty_one_days_is_kept_for_its_grace_and_one_aged_32_days_is_pruned(): void
    {
        // 30 days of life + 24h grace = 31 days exactly: the cutoff is strict
        // (created_at BEFORE it), so a token 31 days and a minute old goes.
        $atBoundary = $this->familyToken(31 * 24 * 60);
        $pastBoundary = $this->familyToken(31 * 24 * 60 + 1);
        $wellPast = $this->familyToken(32 * 24 * 60);

        $this->prune();

        $this->assertTrue($this->exists($atBoundary), 'not yet older than lifetime + grace');
        $this->assertFalse($this->exists($pastBoundary));
        $this->assertFalse($this->exists($wellPast));
    }

    // -------------------------------------------------------------- staff

    #[Test]
    public function a_staff_token_aged_33_hours_is_pruned_and_one_aged_7_hours_survives(): void
    {
        $old = $this->staffToken(33 * 60);
        $young = $this->staffToken(7 * 60);

        $this->prune();

        $this->assertFalse($this->exists($old));
        $this->assertTrue($this->exists($young));
    }

    #[Test]
    public function a_staff_token_is_kept_through_its_grace_period_and_pruned_after(): void
    {
        // Guard refuses at 480 minutes; the row is kept for 24 more hours.
        $refusedButInGrace = $this->staffToken(self::STAFF_MINUTES + self::GRACE_MINUTES - 1);
        $pastGrace = $this->staffToken(self::STAFF_MINUTES + self::GRACE_MINUTES + 1);

        $this->prune();

        $this->assertTrue($this->exists($refusedButInGrace));
        $this->assertFalse($this->exists($pastGrace));
    }

    // ------------------------------------------------------------- member

    #[Test]
    public function a_member_token_is_judged_by_the_family_guards_lifetime_not_staffs(): void
    {
        // Member tokens authenticate through `auth:family` (routes/api.php), so
        // their life is the family guard's 30 days.
        $twoDays = $this->memberToken(2 * 24 * 60);
        $justInsideLife = $this->memberToken(self::FAMILY_MINUTES - 1);
        $refusedButInGrace = $this->memberToken(self::FAMILY_MINUTES + self::GRACE_MINUTES - 1);
        $pastGrace = $this->memberToken(self::FAMILY_MINUTES + self::GRACE_MINUTES + 1);

        $this->prune();

        $this->assertTrue($this->exists($twoDays));
        $this->assertTrue($this->exists($justInsideLife));
        $this->assertTrue($this->exists($refusedButInGrace));
        $this->assertFalse($this->exists($pastGrace));
    }

    // ---------------------------------------------------------- hand-off

    #[Test]
    public function a_handoff_token_is_pruned_24_hours_after_its_expires_at_and_kept_within_it(): void
    {
        // expires_at = now + 60 minutes at mint time (createStudentHandoffToken).
        $expiredLongAgo = $this->contact->createStudentHandoffToken(7);
        $expiredInGrace = $this->contact->createStudentHandoffToken(8);
        $stillValid = $this->contact->createStudentHandoffToken(9);

        // created_at stays inside the family guard's 30 days for all three:
        // it is the expires_at rule that must act.
        $idOld = $this->aged($expiredLongAgo, 3 * 24 * 60);
        $idGrace = $this->aged($expiredInGrace, 60);
        $idValid = $this->aged($stillValid, 0);

        DB::table('personal_access_tokens')->where('id', $idOld)
            ->update(['expires_at' => now()->subHours(24)->subMinute()]);
        DB::table('personal_access_tokens')->where('id', $idGrace)
            ->update(['expires_at' => now()->subHours(23)]);

        $this->prune();

        $this->assertFalse($this->exists($idOld), 'past expires_at + 24h');
        $this->assertTrue($this->exists($idGrace), 'expired but inside the grace');
        $this->assertTrue($this->exists($idValid), 'not expired');
    }

    // ------------------------------------------------------- unrecognised

    #[Test]
    public function an_unrecognised_token_kind_uses_the_longest_lifetime_of_any_guard(): void
    {
        ForeignTokenable::createTable();
        $foreign = ForeignTokenable::create(['name' => 'x', 'type' => 'SuperAdmin']);

        // Aged past staff's 33h window, but inside the longest lifetime (30d).
        $inLongest = $this->aged($foreign->createToken('mystery', ['*']), 5 * 24 * 60);
        $pastLongest = $this->aged($foreign->createToken('mystery', ['*']), 32 * 24 * 60);

        $this->prune();

        $this->assertTrue($this->exists($inLongest), 'the staff window must not apply to an unknown kind');
        $this->assertFalse($this->exists($pastLongest));
    }

    #[Test]
    public function an_unbounded_guard_lifetime_switches_age_pruning_off_for_the_kinds_it_governs(): void
    {
        // Sanctum treats a null/0 expiration as "never expires by age". Deleting
        // by age a token the guard would still accept is the one thing this
        // command must not do.
        config(['sanctum.expiration' => null]);

        $staffOld = $this->staffToken(400 * 24 * 60);
        $familyOld = $this->familyToken(400 * 24 * 60);
        $familyExpiresAt = $this->contact->createStudentHandoffToken(3);
        $expired = $this->aged($familyExpiresAt, 0);
        DB::table('personal_access_tokens')->where('id', $expired)
            ->update(['expires_at' => now()->subDays(3)]);

        $this->prune();

        $this->assertTrue($this->exists($staffOld), 'staff guard has no age limit now');
        $this->assertFalse($this->exists($familyOld), 'family guard still has its 30 days');
        $this->assertFalse($this->exists($expired), 'expires_at applies to every kind');
    }

    #[Test]
    public function the_kind_is_decided_by_the_tokenable_not_by_the_abilities_label(): void
    {
        // A Contact token whose abilities were rewritten to ['staff'] is still
        // read by the family guard, so it still lives 30 days.
        $token = $this->contact->createToken('odd', ['staff']);
        $id = $this->aged($token, 5 * 24 * 60);

        $this->prune();

        $this->assertTrue($this->exists($id));
    }

    // ------------------------------------------------- property-style loop

    #[Test]
    public function no_token_a_real_guard_would_still_accept_is_ever_deleted(): void
    {
        $agesInMinutes = [
            0, 1, 59, 60, 479, 480, 481, 1440, 1919, 1920, 1980, 2879, 2880, 2881,
            10_000, 43_199, 43_200, 43_201, 43_200 + 1_439, 43_200 + 1_440,
            43_200 + 1_441, 60_000, 100_000,
        ];

        $cases = []; // [id, guard, plainText]

        foreach ($agesInMinutes as $age) {
            $family = $this->contact->createFamilyToken();
            $cases[] = [$this->aged($family, $age), 'family', $family->plainTextToken];

            $member = $this->contact->createMemberToken();
            $cases[] = [$this->aged($member, $age), 'family', $member->plainTextToken];

            // A hand-off token that is still inside its own 60 minutes.
            $handoff = $this->contact->createStudentHandoffToken(11);
            $cases[] = [$this->aged($handoff, $age), 'family', $handoff->plainTextToken];

            $staff = $this->staff->createToken('login-token', ['staff']);
            $cases[] = [$this->aged($staff, $age), 'sanctum', $staff->plainTextToken];
        }

        // Which ones the guards accept is decided BEFORE the sweep runs.
        $accepted = [];
        foreach ($cases as [$id, $guard, $plain]) {
            $accepted[$id] = $this->guardAccepts($guard, $plain);
        }

        $this->assertContains(true, $accepted, 'the loop must include tokens the guards accept');
        $this->assertContains(false, $accepted, 'and tokens they refuse');

        $this->prune();

        foreach ($accepted as $id => $wasAccepted) {
            if ($wasAccepted) {
                $this->assertTrue($this->exists($id), "token {$id} was still accepted by its guard but was deleted");
            }
        }
    }

    // ------------------------------------------------ chunking and report

    #[Test]
    public function it_deletes_in_bounded_chunks_and_reports_counts_per_kind(): void
    {
        $channel = $this->spyMonitorsChannel();

        for ($i = 0; $i < 7; $i++) {
            $this->familyToken(40 * 24 * 60);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->staffToken(3 * 24 * 60);
        }
        $keepFamily = $this->familyToken(24 * 60);
        $keepMember = $this->memberToken(24 * 60);

        $output = $this->prune(['--chunk' => 2]);

        $this->assertSame(2, DB::table('personal_access_tokens')->count());
        $this->assertTrue($this->exists($keepFamily));
        $this->assertTrue($this->exists($keepMember));
        $this->assertMatchesRegularExpression('/staff\s+\|?\s*3/', $output);
        $this->assertMatchesRegularExpression('/family\s+\|?\s*7/', $output);

        $channel->shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
            return $message === 'tokens:prune-expired'
                && $context['total'] === 10
                && $context['deleted_by_kind']['family'] === 7
                && $context['deleted_by_kind']['staff'] === 3
                && $context['deleted_by_kind']['member'] === 0;
        })->once();
    }

    #[Test]
    public function a_zero_deletion_run_is_normal_and_still_reports(): void
    {
        $channel = $this->spyMonitorsChannel();
        $id = $this->familyToken(60);

        $output = $this->prune();

        $this->assertTrue($this->exists($id));
        $this->assertStringContainsString('pruned 0 token(s)', $output);
        $channel->shouldHaveReceived('info')->withArgs(
            fn ($message, $context = []) => $message === 'tokens:prune-expired' && $context['total'] === 0
        )->once();
    }

    #[Test]
    public function the_run_is_logged_on_the_monitors_channel_not_the_default_one(): void
    {
        // Production runs LOG_LEVEL=warning: an info line on the default channel
        // is dropped. Only the `monitors` stack (monitors-file, pinned at info)
        // leaves a record of a clean scheduled run.
        Log::spy();
        $channel = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('monitors')->once()->andReturn($channel);

        $this->prune();

        Log::shouldNotHaveReceived('info');
        $channel->shouldHaveReceived('info')->once();
    }

    #[Test]
    public function the_line_survives_production_log_level_in_monitors_log_and_never_pages(): void
    {
        // Production runs LOG_LEVEL=warning. This uses the real config/logging.php
        // under that environment, so a channel that follows LOG_LEVEL would drop
        // the line here exactly as it does there (a Log spy cannot show that).
        $this->logLikeProduction();

        try {
            $this->familyToken(40 * 24 * 60);

            $this->prune();

            $this->assertSame([], $this->loggedLines('laravel.log', 'tokens:prune-expired'),
                'the application log kept an info line, so this test is not at production\'s LOG_LEVEL');

            $kept = $this->loggedLines('monitors.log', 'tokens:prune-expired');
            $this->assertCount(1, $kept, 'a scheduled prune run left no record at LOG_LEVEL=warning');
            $this->assertStringContainsString('"total":1', $kept[0]);
            $this->assertSame([], $this->alertSubjects(), 'an info-level prune run emailed the operator');
        } finally {
            $this->forgetProductionLogs();
        }
    }

    #[Test]
    public function an_oversized_chunk_is_clamped_and_still_deletes_everything(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->familyToken(40 * 24 * 60);
        }
        $keep = $this->familyToken(60);

        $this->prune(['--chunk' => 70000]);

        foreach ($ids as $id) {
            $this->assertFalse($this->exists($id));
        }
        $this->assertTrue($this->exists($keep));
    }

    #[Test]
    public function a_dry_run_counts_and_deletes_nothing(): void
    {
        $id = $this->familyToken(40 * 24 * 60);

        $output = $this->prune(['--dry-run' => true, '--chunk' => 1]);

        $this->assertTrue($this->exists($id));
        $this->assertStringContainsString('would prune 1 token(s)', $output);
    }

    #[Test]
    public function the_grace_cannot_be_set_below_an_hour(): void
    {
        // A zero or negative grace would delete rows the guard still accepts.
        $id = $this->familyToken(self::FAMILY_MINUTES - 5);

        $this->prune(['--hours' => -500]);

        $this->assertTrue($this->exists($id));
    }

    // ---------------------------------------------------------- scheduling

    #[Test]
    public function the_schedule_runs_the_new_command_and_no_longer_the_sanctum_one(): void
    {
        Artisan::call('list', ['--raw' => true]);
        $commands = array_map(fn ($event) => (string) $event->command, app(Schedule::class)->events());

        $matching = array_values(array_filter($commands, fn ($c) => str_contains($c, 'tokens:prune-expired')));
        $this->assertCount(1, $matching, 'tokens:prune-expired must be scheduled exactly once');

        foreach ($commands as $command) {
            $this->assertStringNotContainsString('sanctum:prune-expired', $command);
        }

        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, 'tokens:prune-expired')) {
                $this->assertTrue($event->withoutOverlapping, 'a slow sweep must not stack up');
                $this->assertSame('0 0 * * *', $event->expression, 'daily');
            }
        }

        $source = file_get_contents(base_path('routes/console.php'));
        $this->assertStringNotContainsString("Schedule::command('sanctum:prune-expired", $source);
    }
}
