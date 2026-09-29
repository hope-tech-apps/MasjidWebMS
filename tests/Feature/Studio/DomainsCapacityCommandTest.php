<?php

namespace Tests\Feature\Studio;

use App\Console\Commands\DomainsCapacity;
use App\Models\MasjidDomain;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * `domains:capacity` (W2 S1): how full the renderer's Pages project is, where
 * the figure came from, and when the owner is emailed about it.
 */
class DomainsCapacityCommandTest extends TestCase
{
    use FakesCloudflare;
    use MakesStudioDomains;
    use RefreshDatabase;

    private const LIST = 'GET /accounts/*/pages/projects/manara-renderer/domains';

    /**
     * Stand in for the `monitors` channel and record what each run wrote to
     * it. The mock also pins that the command writes nowhere else.
     */
    private function recordMonitorLines(): object
    {
        $recorder = new class
        {
            /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
            public array $lines = [];

            public function __call(string $level, array $arguments): void
            {
                $this->lines[] = [$level, (string) $arguments[0], (array) ($arguments[1] ?? [])];
            }

            /** @return list<string> */
            public function levels(): array
            {
                return array_column($this->lines, 0);
            }
        };

        Log::shouldReceive('channel')->with('monitors')->andReturn($recorder);

        return $recorder;
    }

    /** @return array<string, mixed> */
    private function capacity(): array
    {
        $this->assertSame(0, Artisan::call('domains:capacity', ['--json' => true]));

        return json_decode(Artisan::output(), true);
    }

    /** Cloudflare reports `$count` custom domains on the project, whatever the page. */
    private function projectHolds(int &$count): void
    {
        $this->withStudioToken();
        $this->fakeCloudflare([
            self::LIST => function () use (&$count) {
                return $this->cfOk([], ['count' => min($count, 20), 'page' => 1, 'per_page' => 20, 'total_count' => $count, 'total_pages' => max(1, (int) ceil($count / 20))]);
            },
        ]);
    }

    #[Test]
    public function without_a_token_it_counts_rows_and_says_it_is_an_estimate(): void
    {
        $org = $this->makeOrg();
        $trashed = $this->makeOrg();
        $cloudflare = ['verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now()];

        // Counted: a slot held or being acquired.
        $this->makeDomain($org, 'a.example.org');
        $this->makeDomain($org, 'b.example.org', MasjidDomain::STATUS_PROVISIONING);
        $this->makeDomain($org, 'c.example.org', MasjidDomain::STATUS_ACTIVE, $cloudflare);
        $this->makeDomain($org, 'd.example.org', MasjidDomain::STATUS_MANUAL, ['source' => MasjidDomain::SOURCE_IMPORTED]);
        // A trashed organisation's host still holds its slot in Cloudflare.
        $this->makeDomain($trashed, 'e.example.org', MasjidDomain::STATUS_ACTIVE, $cloudflare);
        $trashed->delete();
        // Not counted: no Pages domain yet, or none at all.
        $this->makeDomain($org, 'f.example.org', MasjidDomain::STATUS_AWAITING_NAMESERVERS);
        $this->makeDomain($org, 'g.example.org', MasjidDomain::STATUS_FAILED);
        $this->makeDomain($org, 'h.example.org', MasjidDomain::STATUS_RESERVED, ['source' => MasjidDomain::SOURCE_IMPORTED]);

        Http::preventStrayRequests();
        Http::fake();
        $monitors = $this->recordMonitorLines();

        $out = $this->capacity();

        Http::assertNothingSent();
        $this->assertSame(5, $out['used']);
        $this->assertSame('rows_estimate', $out['source']);
        $this->assertSame(100, $out['ceiling']);
        $this->assertEquals(5.0, $out['percent']);
        // W2 S5: a new client uses one slot; its other host redirects.
        $this->assertSame(95, $out['clients_left_estimate']);
        $this->assertStringStartsWith('estimate:', $out['clients_left_basis']);
        $this->assertArrayNotHasKey('cloudflare_error', $out);
        $this->assertSame(['info'], $monitors->levels());
        $this->assertStringContainsString('rows_estimate', $monitors->lines[0][1]);
    }

    #[Test]
    public function with_a_token_it_counts_through_cloudflare_with_one_get(): void
    {
        $count = 5;
        $this->projectHolds($count);
        // Rows the estimate would count differently: the figure must be Cloudflare's.
        $this->makeDomain($this->makeOrg(), 'a.example.org');
        $this->recordMonitorLines();

        $out = $this->capacity();

        $this->assertSame(5, $out['used']);
        $this->assertSame('cloudflare', $out['source']);
        $this->assertCount(1, $this->sentToCloudflare());
        $this->assertStringStartsWith('GET ' . self::API . '/accounts/', $this->sentToCloudflare()[0]);
        $this->assertStringEndsWith('/pages/projects/manara-renderer/domains', $this->sentToCloudflare()[0]);
    }

    #[Test]
    public function a_failed_cloudflare_read_falls_back_to_the_row_estimate_and_says_why(): void
    {
        $this->withStudioToken();
        $this->fakeCloudflare([self::LIST => $this->cfError(503, 0, 'Service unavailable')]);
        $this->makeDomain($this->makeOrg(), 'a.example.org');
        Log::shouldReceive('warning')->withArgs(fn (string $message) => str_contains($message, 'Cloudflare API call did not succeed'))->once();
        $monitors = $this->recordMonitorLines();

        $out = $this->capacity();

        $this->assertSame(1, $out['used']);
        $this->assertSame('rows_estimate', $out['source']);
        $this->assertStringStartsWith('transient', $out['cloudflare_error']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($out) . json_encode($monitors->lines));
        $this->assertSame(['info'], $monitors->levels());
    }

    #[Test]
    public function each_threshold_emails_once_and_only_once(): void
    {
        $count = 49;
        $this->projectHolds($count);
        $monitors = $this->recordMonitorLines();

        $this->assertNull($this->capacity()['notice']);

        $count = 50;
        $this->assertSame(50, $this->capacity()['notice']);
        $this->assertNull($this->capacity()['notice'], 'a second run at the same figure');

        $count = 72;
        $this->assertSame(70, $this->capacity()['notice']);

        // A run that passes two thresholds at once writes one line, naming the
        // higher, and neither is told again.
        $count = 96;
        $this->assertSame(95, $this->capacity()['notice']);
        $this->assertNull($this->capacity()['notice']);

        $this->assertSame(['info', 'error', 'info', 'error', 'error', 'info'], $monitors->levels());

        $errors = array_values(array_filter($monitors->lines, fn (array $line) => $line[0] === 'error'));
        $this->assertStringContainsString('50%', $errors[0][1]);
        $this->assertStringContainsString('50 of 100 (50%, cloudflare)', $errors[0][1]);
        $this->assertStringContainsString(DomainsCapacity::RUNBOOK, $errors[0][1]);
        $this->assertStringContainsString('70%', $errors[1][1]);
        $this->assertStringContainsString('95%', $errors[2][1]);
        foreach ([50, 70, 85, 95] as $threshold) {
            $this->assertTrue(Cache::has(DomainsCapacity::NOTICED_KEY . $threshold), "{$threshold}% is marked as told");
        }

        // A lost cache can only repeat a notice, never swallow one.
        Cache::flush();
        $this->assertSame(95, $this->capacity()['notice']);
    }

    #[Test]
    public function a_row_waiting_on_capacity_is_an_error_every_run(): void
    {
        $count = 100;
        $this->projectHolds($count);
        $this->makeDomain($this->makeOrg(), 'new.example.org', MasjidDomain::STATUS_PENDING, [
            'waiting_on' => 'capacity',
            'next_check_at' => now()->addHour(),
        ]);
        // Every threshold already told: the waiting row alone must still speak.
        foreach ([50, 70, 85, 95] as $threshold) {
            Cache::forever(DomainsCapacity::NOTICED_KEY . $threshold, now()->toIso8601String());
        }
        $monitors = $this->recordMonitorLines();

        $this->assertSame(1, $this->capacity()['waiting_on_capacity']);
        $this->travel(1)->days();
        $this->assertSame(1, $this->capacity()['waiting_on_capacity']);

        $this->assertSame(['error', 'error'], $monitors->levels());
        foreach ($monitors->lines as [, $message]) {
            $this->assertStringContainsString('waiting for a Pages custom-domain slot', $message);
            $this->assertStringContainsString(DomainsCapacity::RUNBOOK, $message);
        }
    }

    #[Test]
    public function a_threshold_first_reached_while_a_host_waits_is_said_in_the_same_line(): void
    {
        $count = 100;
        $this->projectHolds($count);
        $this->makeDomain($this->makeOrg(), 'new.example.org', MasjidDomain::STATUS_PENDING, [
            'waiting_on' => 'capacity',
            'next_check_at' => now()->addHour(),
        ]);
        $monitors = $this->recordMonitorLines();

        $this->capacity();

        $this->assertSame(['error'], $monitors->levels());
        $this->assertStringContainsString('reached 95% of its ceiling', $monitors->lines[0][1]);
        $this->assertTrue(Cache::has(DomainsCapacity::NOTICED_KEY . 95));
    }

    #[Test]
    public function reserved_rows_are_not_counted(): void
    {
        $org = $this->makeOrg();
        $this->makeDomain($org, 'meccharlotte.org', MasjidDomain::STATUS_RESERVED, ['source' => MasjidDomain::SOURCE_IMPORTED]);
        $this->makeDomain($org, 'www.meccharlotte.org', MasjidDomain::STATUS_RESERVED, ['source' => MasjidDomain::SOURCE_IMPORTED]);
        $this->recordMonitorLines();

        $out = $this->capacity();

        $this->assertSame(0, $out['used']);
        $this->assertSame(100, $out['clients_left_estimate']);
    }

    #[Test]
    public function a_redirect_row_uses_no_slot(): void
    {
        // W2 S5: only the canonical host is a custom domain on the project.
        $org = $this->makeOrg();
        $www = $this->makeDomain($org, 'www.pair-masjid.org', MasjidDomain::STATUS_ACTIVE, [
            'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now(),
        ]);
        $this->makeDomain($org, 'pair-masjid.org', MasjidDomain::STATUS_MANUAL, [
            'role' => MasjidDomain::ROLE_REDIRECT, 'redirect_to_id' => $www->id,
            'verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(),
        ]);
        $this->recordMonitorLines();

        $this->assertSame(1, $this->capacity()['used']);
    }

    #[Test]
    public function it_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'domains:capacity'));

        $this->assertCount(1, $events, 'scheduled exactly once');
        $event = $events->first();

        $this->assertSame('17 7 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    #[Test]
    public function the_ceiling_can_be_raised_by_env_without_a_code_change(): void
    {
        $read = function (?string $value): mixed {
            $key = 'CLOUDFLARE_PAGES_DOMAIN_CEILING';

            try {
                if ($value === null) {
                    unset($_SERVER[$key], $_ENV[$key]);
                } else {
                    $_SERVER[$key] = $_ENV[$key] = $value;
                }

                return (require base_path('config/cloudflare.php'))['pages_domain_ceiling'];
            } finally {
                unset($_SERVER[$key], $_ENV[$key]);
            }
        };

        $this->assertSame(250, $read('250'));
        $this->assertSame(500, $read('500'));
        // Unset, blank (what the staging deny-list leaves) or nonsense: the Free plan's 100.
        $this->assertSame(100, $read(null));
        $this->assertSame(100, $read(''));
        $this->assertSame(100, $read('0'));
        $this->assertSame(100, $read('lots'));

        // And the command reads the configured figure.
        config(['cloudflare.pages_domain_ceiling' => 250]);
        $this->makeDomain($this->makeOrg(), 'a.example.org');
        $this->recordMonitorLines();
        $out = $this->capacity();
        $this->assertSame(250, $out['ceiling']);
        $this->assertSame(249, $out['clients_left_estimate']);
    }

    #[Test]
    public function the_runbook_it_names_exists(): void
    {
        $this->assertFileExists(base_path(DomainsCapacity::RUNBOOK));
        $this->assertSame([50, 70, 85, 95], config('cloudflare.pages_domain_notice_at'));
    }
}
