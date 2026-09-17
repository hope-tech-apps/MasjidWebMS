<?php

namespace Tests\Feature;

use App\Http\Middleware\CountLegacyFeaturesHit;
use App\Models\Masjid;
use App\Support\AppClientHeader;
use App\Support\Canary\CanaryHeader;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesMenuOrganisations;
use Tests\TestCase;

/**
 * Who is still reading the legacy `/features` list, and the two ways that
 * counting could do harm.
 *
 * S3b deletes that endpoint, and the only honest basis for deleting it is
 * evidence that nothing still calls it. The counter is that evidence, so this
 * file checks the evidence is (a) split the way the decision needs and (b)
 * incapable of damaging the thing it measures.
 *
 * (b) is the part worth being strict about. This endpoint is the entire drawer
 * for every installed build; on 2026-08-28 a single null icon in this same
 * payload emptied that drawer on every phone. A counter that can 500 the route,
 * change a byte of the body, or add latency to it would be a strictly worse
 * trade than knowing nothing.
 *
 * The distinction the report turns on:
 *
 *   untagged  a build shipped BEFORE R1 — the installs deletion would break.
 *   tagged    an R1 build falling back, which is what it does while the kill
 *             row is set. Expected, and not a reason to delay anything.
 *
 * What this cannot cover: whether the counts are COMPLETE. A cache flush, a
 * restarted box, or a day the report did not run all look exactly like silence,
 * and no test can tell them apart. The counters are a floor on the traffic.
 */
class LegacyFeaturesTelemetryTest extends TestCase
{
    use RefreshDatabase;
    use MakesMenuOrganisations;

    private Masjid $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemory();

        $this->org = $this->listedOrg('Muslim Education Center');
    }

    // ------------------------------------------------------------ the counter

    #[Test]
    public function a_build_that_does_not_identify_itself_counts_as_untagged(): void
    {
        $this->getJson($this->featuresUrl($this->org))->assertOk();
        $this->getJson($this->featuresUrl($this->org))->assertOk();

        $this->assertSame(2, $this->countedHits($this->org, 'untagged'));
        $this->assertSame(0, $this->countedHits($this->org, 'tagged'));
    }

    #[Test]
    public function an_r1_build_falling_back_counts_as_tagged(): void
    {
        $this->getJson($this->featuresUrl($this->org), [AppClientHeader::HEADER => 'ios/1.0/47'])->assertOk();

        $this->assertSame(1, $this->countedHits($this->org, 'tagged'));
        $this->assertSame(0, $this->countedHits($this->org, 'untagged'));
    }

    /**
     * A header that does not parse is a build that did not identify itself,
     * not a third bucket. Otherwise anything that mangles the header on the way
     * in would quietly shrink the pre-R1 count — the number S3b turns on.
     */
    #[Test]
    public function a_malformed_header_counts_as_untagged(): void
    {
        foreach (['garbage', 'ios/1.0', 'IOS/1.0/47', ''] as $value) {
            $this->getJson($this->featuresUrl($this->org), [AppClientHeader::HEADER => $value])->assertOk();
        }

        $this->assertSame(4, $this->countedHits($this->org, 'untagged'));
        $this->assertSame(0, $this->countedHits($this->org, 'tagged'));
    }

    #[Test]
    public function the_count_is_kept_per_organisation(): void
    {
        $other = $this->listedOrg('Al-Razi School');

        $this->getJson($this->featuresUrl($this->org))->assertOk();
        $this->getJson($this->featuresUrl($other))->assertOk();
        $this->getJson($this->featuresUrl($other))->assertOk();

        $this->assertSame(1, $this->countedHits($this->org, 'untagged'));
        $this->assertSame(2, $this->countedHits($other, 'untagged'));
    }

    #[Test]
    public function the_count_is_kept_per_day(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(9));
        $this->getJson($this->featuresUrl($this->org))->assertOk();

        $yesterday = now()->toDateString();

        $this->travelTo(now()->addDay());
        $this->getJson($this->featuresUrl($this->org))->assertOk();
        $this->getJson($this->featuresUrl($this->org))->assertOk();

        $this->assertSame(1, (int) Cache::get(
            CountLegacyFeaturesHit::key($yesterday, $this->org->id, 'untagged'), 0
        ));
        $this->assertSame(2, $this->countedHits($this->org, 'untagged'));
    }

    /**
     * A 404 for an organisation that does not exist is not a phone reading a
     * feature list. Counting refusals would put invented organisation ids in
     * the daily report and let one scanner look like a stranded congregation.
     */
    #[Test]
    public function a_refused_request_is_not_counted(): void
    {
        // Asserted as "did not succeed" rather than as a literal status: which
        // 4xx/5xx an unknown organisation produces belongs to the endpoint's own
        // contract test, and pinning it twice would make this file fail for a
        // change it does not cover.
        $this->assertGreaterThanOrEqual(
            400,
            $this->getJson('/api/mobile/masjids/999999/features')->getStatusCode()
        );

        $this->assertSame(0, (int) Cache::get(
            CountLegacyFeaturesHit::key(now()->toDateString(), 999999, 'untagged'), 0
        ));
        $this->assertSame(0, (int) Cache::get(
            CountLegacyFeaturesHit::key(now()->toDateString(), 999999, 'tagged'), 0
        ));
    }

    // ------------------------------------------------ our own canary is not a phone

    /**
     * `tenancy:canary` probes this endpoint as organisation 1 on about six runs
     * a day, with no `X-Manara-App`. Counted, each probe is an untagged hit on
     * the organisation S3b is gated on, and that organisation never reaches
     * zero. The header is written out literally here: it is what goes over
     * the wire.
     *
     * Seeded to a non-zero count, so "unchanged" is a real number staying put
     * rather than an empty key staying empty. The plain request at the end
     * proves the same request without the header still counts.
     */
    #[Test]
    public function a_canary_probe_leaves_the_counter_unchanged(): void
    {
        $today = now()->toDateString();
        $this->seedCount($today, $this->org, 'untagged', 5);

        $this->getJson($this->featuresUrl($this->org), [
            'X-Canary' => 'tenancy',
            'User-Agent' => 'ManaraTenancyCanary/1',
        ])->assertOk();

        $this->assertSame(5, $this->countedHits($this->org, 'untagged'));
        $this->assertSame(0, $this->countedHits($this->org, 'tagged'));

        $this->getJson($this->featuresUrl($this->org))->assertOk();

        $this->assertSame(6, $this->countedHits($this->org, 'untagged'));
    }

    /**
     * The canary is skipped before the bucket is chosen. A probe that also
     * named a build is still a probe, and must not count as an R1 build
     * falling back.
     */
    #[Test]
    public function a_canary_probe_that_names_a_build_is_not_counted_either(): void
    {
        $this->getJson($this->featuresUrl($this->org), [
            'X-Canary' => 'tenancy',
            AppClientHeader::HEADER => 'ios/1.0/47',
        ])->assertOk();

        $this->assertSame(0, $this->countedHits($this->org, 'tagged'));
        $this->assertSame(0, $this->countedHits($this->org, 'untagged'));
    }

    /**
     * Any non-empty value marks a probe, so a second canary with its own value
     * is excluded too. An empty header is not a marker, and that request still
     * counts.
     */
    #[Test]
    public function any_canary_value_is_skipped_but_an_empty_one_is_not(): void
    {
        $this->getJson($this->featuresUrl($this->org), ['X-Canary' => 'menu'])->assertOk();

        $this->assertSame(0, $this->countedHits($this->org, 'untagged'));

        $this->getJson($this->featuresUrl($this->org), ['X-Canary' => ''])->assertOk();

        $this->assertSame(1, $this->countedHits($this->org, 'untagged'));
    }

    /**
     * The headers the canary ACTUALLY sends, replayed against the endpoint.
     * This fails if TenancyCanary::headers() stops sending the marker, or
     * HttpTransport stops forwarding it.
     *
     * Not run through the canary's `kernel` transport, although that is the
     * default under test. KernelTransport::send() calls $kernel->handle() and
     * never $kernel->terminate(), so CountLegacyFeaturesHit::terminate() does
     * not run at all. A test built on it would pass with the filter deleted.
     * Instead the canary runs on the `http` transport against a faked client.
     * The requests it records are then replayed through the test client, which
     * does terminate.
     */
    #[Test]
    public function the_probes_the_canary_actually_sends_are_not_counted(): void
    {
        $other = $this->listedOrg('Al-Razi School');

        Http::fake(['*' => Http::response([], 200)]);

        Artisan::call('tenancy:canary', [
            '--transport' => 'http',
            '--base-url' => 'http://canary.invalid',
            '--tenants' => "{$this->org->id},{$other->id}",
            '--only' => '{masjid_id}/features',
            '--delay' => 0,
            '--json' => true,
        ]);

        $probes = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (ClientRequest $request) => preg_match(
                '#/api/mobile/masjids/\d+/features$#',
                (string) parse_url($request->url(), PHP_URL_PATH)
            ) === 1)
            ->values();

        $this->assertNotEmpty(
            $probes,
            'the canary sent no /features probe, so this test proves nothing: '.Artisan::output()
        );

        foreach ($probes as $probe) {
            $path = (string) parse_url($probe->url(), PHP_URL_PATH);
            $org = Masjid::findOrFail((int) preg_replace('#^.*/masjids/(\d+)/features$#', '$1', $path));

            // Host would point the test request at canary.invalid.
            $headers = collect($probe->headers())
                ->except(['Host', 'Content-Length'])
                ->map(fn (array $values) => implode(', ', $values))
                ->all();

            $this->assertSame(CanaryHeader::TENANCY, $headers[CanaryHeader::NAME] ?? null);

            $before = $this->countedHits($org, 'untagged');

            $this->getJson($path, $headers)->assertOk();

            $this->assertSame($before, $this->countedHits($org, 'untagged'), "the canary's probe of {$path} was counted");
            $this->assertSame(0, $this->countedHits($org, 'tagged'));

            // Control: the same path without the marker still counts.
            $this->getJson($path, collect($headers)->except(CanaryHeader::NAME)->all())->assertOk();

            $this->assertSame($before + 1, $this->countedHits($org, 'untagged'));
        }
    }

    // ------------------------------------- the counter cannot harm the payload

    /**
     * The body an installed build draws its whole drawer from must be
     * bit-for-bit what it was before the middleware existed.
     */
    #[Test]
    public function the_payload_is_unchanged_by_being_counted(): void
    {
        $counted = $this->getJson($this->featuresUrl($this->org))->assertOk();

        $uncounted = $this->withoutMiddleware(CountLegacyFeaturesHit::class)
            ->getJson($this->featuresUrl($this->org))
            ->assertOk();

        $this->assertSame($uncounted->getContent(), $counted->getContent());
        $this->assertSame($uncounted->getStatusCode(), $counted->getStatusCode());
    }

    /**
     * A cache backend that is down must not be able to take out the endpoint.
     * Everything in terminate() is inside one catch-all for exactly this, so a
     * store that throws on every call must produce no exception at all.
     *
     * Driven against terminate() directly rather than through a request: mocking
     * the cache facade for a whole request would also replace the store the rate
     * limiter runs on, and the test would then be measuring the wrong outage.
     */
    #[Test]
    public function a_broken_cache_cannot_break_the_endpoint(): void
    {
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache is down'));
        Cache::shouldReceive('increment')->andThrow(new \RuntimeException('cache is down'));

        $request = Request::create($this->featuresUrl($this->org), 'GET');
        $request->setRouteResolver(fn () => tap(
            new Route('GET', 'api/mobile/masjids/{masjid_id}/features', []),
            fn (Route $route) => $route->bind($request)
        ));

        // No expectation beyond "this returns": an exception escaping here is a
        // 500 on the endpoint every installed build draws its drawer from.
        (new CountLegacyFeaturesHit)->terminate($request, new Response('', 200));

        $this->addToAssertionCount(1);
    }

    /** handle() is a pass-through; nothing may be decided before the response. */
    #[Test]
    public function the_middleware_passes_the_request_straight_through(): void
    {
        $request = Request::create($this->featuresUrl($this->org), 'GET');
        $expected = new Response('untouched', 200);

        $this->assertSame(
            $expected,
            (new CountLegacyFeaturesHit)->handle($request, fn () => $expected)
        );
    }

    // ------------------------------------------------------------- the report

    #[Test]
    public function the_report_summarises_yesterday_per_organisation(): void
    {
        $other = $this->listedOrg('Al-Razi School');
        $yesterday = now()->subDay()->toDateString();

        $this->seedCount($yesterday, $this->org, 'untagged', 41);
        $this->seedCount($yesterday, $this->org, 'tagged', 2);
        $this->seedCount($yesterday, $other, 'tagged', 7);

        // Today's traffic is not yesterday's report.
        $this->seedCount(now()->toDateString(), $this->org, 'untagged', 900);

        $this->assertSame(0, Artisan::call('app:legacy-features-report', ['--json' => true]));

        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($yesterday, $report['date']);
        $this->assertSame(41, $report['untagged']);
        $this->assertSame(9, $report['tagged']);
        $this->assertSame(2, $report['organisations']);

        // Pre-R1 traffic decides the order: those are the organisations the
        // retirement is blocked on.
        $this->assertSame($this->org->id, $report['per_organisation'][0]['masjid_id']);
        $this->assertSame(41, $report['per_organisation'][0]['untagged']);
        $this->assertSame($other->id, $report['per_organisation'][1]['masjid_id']);

        // An organisation nobody called is not a row.
        $this->assertCount(2, $report['per_organisation']);
    }

    /**
     * Production runs LOG_LEVEL=warning. An info line here would be written by
     * this code, dropped by the logger, and leave a daily report that runs and
     * produces nothing anybody can find — a failure below the log level, which
     * this repository has been bitten by before.
     */
    #[Test]
    public function the_report_writes_exactly_one_warning_a_run(): void
    {
        $yesterday = now()->subDay()->toDateString();

        $this->seedCount($yesterday, $this->org, 'untagged', 5);
        $this->seedCount($yesterday, $this->listedOrg('Al-Razi School'), 'untagged', 5);

        // A spy rather than a strict mock: the point is the ONE warning, not
        // that nothing else in the framework may ever log during the run.
        Log::spy();

        Artisan::call('app:legacy-features-report');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'legacy mobile /features usage'
                    && $context['untagged'] === 10
                    && count($context['per_organisation']) === 2;
            });

        // And nothing at info level, which production's LOG_LEVEL=warning drops.
        Log::shouldNotHaveReceived('info');
    }

    #[Test]
    public function a_day_with_no_traffic_is_a_success_not_a_failure(): void
    {
        $this->assertSame(0, Artisan::call('app:legacy-features-report', ['--json' => true]));

        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $report['organisations']);
        $this->assertSame(0, $report['tagged']);
        $this->assertSame(0, $report['untagged']);
    }

    /** A malformed date would read keys that cannot exist and report a confident zero. */
    #[Test]
    public function a_malformed_date_is_refused_rather_than_answered(): void
    {
        $this->assertSame(1, Artisan::call('app:legacy-features-report', ['--date' => 'yesterday']));
    }

    #[Test]
    public function the_report_deletes_no_counter_so_a_rerun_is_safe(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $this->seedCount($yesterday, $this->org, 'untagged', 3);

        Artisan::call('app:legacy-features-report');
        Artisan::call('app:legacy-features-report', ['--json' => true]);

        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(3, $report['untagged'], 'the second run must read the same counters');
    }

    #[Test]
    public function the_report_is_actually_scheduled_daily(): void
    {
        $event = $this->scheduledEventFor('app:legacy-features-report');

        $this->assertNotNull(
            $event,
            'app:legacy-features-report is not on the schedule, so the counters are written and never read.'
        );

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = explode(' ', $event->expression);

        $this->assertNotSame('*', $minute);
        $this->assertNotSame('*', $hour);
        $this->assertSame(['*', '*', '*'], [$dayOfMonth, $month, $dayOfWeek]);
    }

    // ------------------------------------------------------------------ helpers

    private function featuresUrl(Masjid $org): string
    {
        return "/api/mobile/masjids/{$org->id}/features";
    }

    /**
     * Not `count()`: PHPUnit\Framework\TestCase::count() is FINAL, so declaring
     * one here is a fatal error at class-load time — the whole file fails to
     * load, and with it every suite in the same run.
     */
    private function countedHits(Masjid $org, string $bucket): int
    {
        return (int) Cache::get(
            CountLegacyFeaturesHit::key(now()->toDateString(), $org->id, $bucket),
            0
        );
    }

    private function seedCount(string $date, Masjid $org, string $bucket, int $hits): void
    {
        Cache::put(
            CountLegacyFeaturesHit::key($date, $org->id, $bucket),
            $hits,
            CountLegacyFeaturesHit::TTL_SECONDS
        );
    }

    private function scheduledEventFor(string $command): ?Event
    {
        // Resolving the Schedule needs the console kernel bootstrapped, which
        // is what loads routes/console.php. Calling any command does that.
        Artisan::call('list', ['--raw' => true]);

        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        return null;
    }
}
