<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The canary must be shown to FAIL, or it is decoration.
 *
 * `tests/Feature/PublicApiTenantScopingTest.php` pins the fix to
 * SearchableTrait. This file pins the DETECTOR: it reintroduces the fail-open
 * shape on a scratch route — the pre-2026-08-11 code, verbatim — and proves
 * `tenancy:canary` finds it, names it, and exits non-zero. Then it proves the
 * same command reports clean against the fixed public API.
 *
 * A test suite full of green assertions is exactly what both live holes shipped
 * through. The only assertion here that means anything is the one where the
 * canary goes red.
 *
 * Every run in this file uses the in-process `kernel` transport (selected
 * automatically under test), so the probes hit THIS test application — seeded
 * tenants, in-memory sqlite, scratch routes — and never open a socket.
 */
class TenancyCanaryTest extends TestCase
{
    use RefreshDatabase;

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

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        // A has two of everything, B has one — the same asymmetry
        // PublicApiTenantScopingTest uses, so "A's rows" (2), "B's rows" (1)
        // and "everything" (3) are three different numbers and a leak cannot be
        // mistaken for a correct answer.
        foreach (['A-first', 'A-second'] as $title) {
            $this->makeAnnouncement($this->masjidA, $title);
            $this->makeService($this->masjidA, $title);
        }

        $this->makeAnnouncement($this->masjidB, 'B-only');
        $this->makeService($this->masjidB, 'B-only');

        $this->makePage($this->masjidA, 'a-one');
        $this->makePage($this->masjidA, 'a-two');
        $this->makePage($this->masjidB, 'b-only');
    }

    // ================================================================
    // The canary goes red on a real leak
    // ================================================================

    #[Test]
    public function it_fails_when_the_fail_open_scope_is_reintroduced(): void
    {
        $this->registerFailOpenFixture();

        [$exit, $run] = $this->runCanary(['--only' => '__canary_fixture']);

        $this->assertSame(1, $exit, 'the canary exited 0 on a live cross-tenant leak');
        $this->assertSame('leak', $run['status']);

        $kinds = array_column($run['findings'], 'kind');

        // Detector 1 — the arithmetic. A has 2 announcements, B has 1, and the
        // tenant-less request answered with 3. This is the production
        // measurement (11 / 3 / 14) reproduced at test scale.
        $this->assertContains('cross_tenant_rows', $kinds,
            'the count comparison did not notice an unscoped answer larger than any single tenant');

        // Detector 2 — the body. Independent of any count: one response named
        // two organisations at once.
        $this->assertContains('cross_tenant_body', $kinds,
            'the body scan did not notice two organisations in one tenant-less response');

        $finding = collect($run['findings'])->firstWhere('kind', 'cross_tenant_rows');

        $this->assertSame('critical', $finding['severity']);
        $this->assertSame('api/v1/__canary_fixture/announcements', $finding['endpoint']);
        $this->assertSame(3, $finding['evidence']['unscoped_records']);
        $this->assertSame(2, $finding['evidence']['max_single_tenant']);

        // "Report actionably": the finding must carry the exact request, as
        // something a human can paste into a terminal at 3am.
        $this->assertStringContainsString('curl', $finding['request']['reproduce']);
        $this->assertStringContainsString('api/v1/__canary_fixture/announcements', $finding['request']['reproduce']);
        $this->assertSame('GET', $finding['request']['method']);
    }

    #[Test]
    public function it_names_the_falsy_header_that_exposed_the_leak(): void
    {
        $this->registerFailOpenFixture();

        [, $run] = $this->runCanary(['--only' => '__canary_fixture']);

        $variants = collect($run['findings'])
            ->pluck('request.variant')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // All three spellings of "no tenant" slipped past `if ($resourceId)`,
        // and the report must show which ones did — an operator who fixes only
        // the missing-header case has fixed a third of it.
        sort($variants);
        $this->assertSame(
            ['falsy-tenant-header-empty', 'falsy-tenant-header-zero', 'no-tenant-header'],
            $variants
        );
    }

    #[Test]
    public function it_fails_when_a_body_names_an_organisation_the_request_did_not_ask_for(): void
    {
        // The second production hole's shape: a lookup that resolves the wrong
        // tenant's record and hands it back to whoever asked.
        $other = $this->masjidB->id;

        Route::get('api/v1/__canary_fixture/wrong-tenant', function () use ($other) {
            $rows = Announcement::query()->where('masjid_id', $other)->get();

            return response()->api(200, 'ok', ['items' => $rows, 'pagination' => ['total' => $rows->count()]]);
        });

        [$exit, $run] = $this->runCanary(['--only' => 'wrong-tenant']);

        $this->assertSame(1, $exit);

        $finding = collect($run['findings'])->firstWhere('kind', 'foreign_tenant_in_body');

        $this->assertNotNull($finding, 'a response carrying another tenant\'s masjid_id was not reported');
        $this->assertSame('critical', $finding['severity']);
        $this->assertSame($this->masjidA->id, $finding['evidence']['requested']);
        $this->assertSame([$other], $finding['evidence']['foreign']);
    }

    #[Test]
    public function it_fails_when_an_unresolved_tenant_escapes_as_a_server_error(): void
    {
        // How the gallery bug hid: a client mistake reported as a server fault,
        // where it reads as infrastructure flakiness and nobody goes looking
        // for a tenancy bug behind it.
        Route::get('api/v1/__canary_fixture/boom', function () {
            if ((int) request()->header('masjid-id') <= 0) {
                throw new \RuntimeException('no masjid');
            }

            return response()->api(200, 'ok', ['items' => [], 'pagination' => ['total' => 0]]);
        });

        [$exit, $run] = $this->runCanary(['--only' => 'boom']);

        $this->assertSame(1, $exit);

        $finding = collect($run['findings'])->firstWhere('kind', 'tenant_error_escaped_as_5xx');

        $this->assertNotNull($finding, 'a 500 on a tenant-less request was not reported');
        $this->assertSame(500, $finding['evidence']['status']);
    }

    // ================================================================
    // ...and green on the fixed code
    // ================================================================

    #[Test]
    public function it_passes_against_the_fixed_public_api(): void
    {
        // The whole /api/v1 surface — where both production holes were, and the
        // only surface where a caller can spell "no tenant" at all.
        [$exit, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 500]);

        $this->assertSame(0, $exit, 'canary reported a finding against the fixed API: '.
            json_encode($run['findings'] ?? [], JSON_PRETTY_PRINT));
        $this->assertSame('clean', $run['status']);
        $this->assertSame([], $run['findings']);
        $this->assertSame([], $run['errors']);
    }

    #[Test]
    public function the_mobile_surface_shows_no_cross_tenant_finding(): void
    {
        // Asserted separately, and narrowly. The /api/mobile controllers hand
        // back raw models, so the body scan is meaningful there — but their
        // responses also depend on optional per-masjid records (a donation
        // link, an about block, TV config) that a bare test tenant does not
        // have, and whether one of those answers 500 on an empty fixture is a
        // different question from whether it leaks. This test pins the tenancy
        // answer; the 5xx findings belong to whoever owns those endpoints.
        [, $run] = $this->runCanary(['--only' => 'api/mobile', '--all' => true, '--max-requests' => 500]);

        $leakKinds = array_intersect(
            array_column($run['findings'], 'kind'),
            ['cross_tenant_rows', 'cross_tenant_body', 'foreign_tenant_in_body', 'fail_open']
        );

        $this->assertSame([], array_values($leakKinds),
            'cross-tenant finding on /api/mobile: '.json_encode($run['findings'], JSON_PRETTY_PRINT));

        $this->assertGreaterThan(10, $run['probes']['sent']);
    }

    #[Test]
    public function a_clean_report_is_not_an_empty_one(): void
    {
        // The failure mode this whole file exists to prevent: a canary that
        // probes nothing, finds nothing, and is believed. A green run must be
        // able to name the endpoints it cleared.
        [, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 500]);

        $this->assertGreaterThan(10, $run['probes']['sent'], 'the canary barely probed anything');
        $this->assertSame($run['probes']['planned'], $run['probes']['sent']);

        $refusalChecks = collect($run['checks'])
            ->where('check', 'tenant-less-request-refused')
            ->where('outcome', 'pass')
            ->pluck('endpoint')
            ->all();

        foreach ([
            'api/v1/announcements',
            'api/v1/services',
            'api/v1/pages',
            'api/v1/pages/menu',
            'api/v1/gallery',
            'api/v1/home',
        ] as $endpoint) {
            $this->assertContains($endpoint, $refusalChecks,
                "{$endpoint} was never probed for the fail-open shape");
        }

        $this->assertSame([$this->masjidA->id, $this->masjidB->id], $run['tenants']);
    }

    // ================================================================
    // Production safety
    // ================================================================

    #[Test]
    public function a_full_run_writes_nothing(): void
    {
        $before = $this->rowCounts();

        $this->runCanary(['--all' => true, '--max-requests' => 500]);

        $this->assertSame($before, $this->rowCounts(), 'the canary mutated the database');
    }

    #[Test]
    public function it_never_probes_an_endpoint_that_writes(): void
    {
        [, $run] = $this->runCanary(['--all' => true, '--max-requests' => 500]);

        $probed = collect($run['checks'])->pluck('endpoint')->unique()->all();

        // Write verbs are dropped structurally by ProbeCatalog.
        foreach ([
            'api/v1/contact-us',
            'api/v1/forms/{form_id}/responses',
            'api/v1/appointment-requests',
            'api/v1/offerings/{slug}/register',
            'api/v1/registrations/{uuid}/checkout',
            'api/v1/zakat/calculate',
            'api/mobile/masjids/{masjid_id}/donations/checkout',
        ] as $writeRoute) {
            $this->assertNotContains($writeRoute, $probed, "{$writeRoute} is a write and must never be probed");
        }

        // And the one that writes behind a GET, which no filter can infer —
        // PrayersController::index() INSERTs into `prayers` before selecting.
        // Named in config/canary.php; pinned here so removing it fails loudly.
        $this->assertNotContains('api/mobile/masjids/{masjid_id}/prayers', $probed,
            'GET /prayers INSERTs rows — a read-only canary must never call it');
    }

    #[Test]
    public function it_does_not_spend_a_scarce_named_limiter(): void
    {
        [, $run] = $this->runCanary(['--all' => true, '--max-requests' => 500]);

        $probed = collect($run['checks'])->pluck('endpoint')->unique()->all();

        // 60/hour per IP+org, and it is a GET, so nothing but the throttle rule
        // keeps the canary off it.
        $this->assertNotContains('api/v1/zakat/nisab', $probed);

        // 10/hour per IP. An hourly canary would consume a tenth of a real
        // device's registration allowance forever.
        $this->assertNotContains('api/mobile/user/masjid', $probed);
    }

    #[Test]
    public function a_truncated_run_reports_incomplete_rather_than_clean(): void
    {
        [$exit, $run] = $this->runCanary(['--all' => true, '--max-requests' => 3]);

        $this->assertSame(2, $exit, 'a truncated run must not exit 0');
        $this->assertSame('incomplete', $run['status']);
        $this->assertSame(3, $run['probes']['sent']);
        $this->assertNotEmpty($run['errors']);

        // Endpoints it never reached must not be reported as having passed.
        $outcomes = collect($run['checks'])->pluck('outcome')->unique()->all();
        $this->assertContains('skipped', $outcomes);
    }

    #[Test]
    public function a_truncated_run_still_reports_a_leak_it_did_reach(): void
    {
        // Priority matters: the budget must cut the cheap signal, not the
        // finding. If the canary reached the leak, exit 1 (page someone) beats
        // exit 2 (a run to retry).
        $this->registerFailOpenFixture();

        [$exit, $run] = $this->runCanary(['--only' => '__canary_fixture', '--max-requests' => 4]);

        $this->assertSame(1, $exit, 'a run truncated AFTER reaching the leak reported exit '.$exit);
        $this->assertSame('leak', $run['status']);
        $this->assertNotEmpty($run['errors'], 'the truncation itself must still be reported');
    }

    #[Test]
    public function it_paces_itself_rather_than_bursting(): void
    {
        // The pacing IS the throttle-safety argument — `throttle:mobile` allows
        // 60/min/IP and the canary must never be able to hold that bucket. With
        // max_per_minute at 6 the derived delay is 10s, so five probes cannot
        // finish in under 40 seconds; asserting the shape at a smaller scale
        // keeps the test fast.
        config(['canary.budget.max_per_minute' => 240]); // -> 250ms between probes

        $started = microtime(true);
        [, $run] = $this->runCanary(['--only' => 'api/v1/services', '--delay' => null]);
        $elapsed = microtime(true) - $started;

        $sent = $run['probes']['sent'];

        $this->assertGreaterThanOrEqual(5, $sent);
        $this->assertGreaterThan(
            0.25 * ($sent - 1),
            $elapsed,
            'the canary fired its plan faster than its own rate limit allows'
        );
    }

    // ================================================================
    // helpers
    // ================================================================

    /**
     * The pre-2026-08-11 `SearchableTrait::scopeFilterByMasjid()`, verbatim, on
     * a scratch route.
     *
     * Reintroducing it on the real trait would be a live cross-tenant hole in a
     * file another agent is not touching; putting it here reintroduces the
     * SHAPE, which is what the canary detects, without editing the fix.
     */
    private function registerFailOpenFixture(): void
    {
        Route::get('api/v1/__canary_fixture/announcements', function () {
            $resourceId = request()->header('masjid-id');

            $query = Announcement::query();

            // The bug: falsy means "no filter" instead of "refuse".
            if ($resourceId) {
                $query->where('masjid_id', $resourceId);
            }

            $rows = $query->get();

            return response()->api(200, 'ok', [
                'items' => $rows,
                'pagination' => ['current_page' => 1, 'total' => $rows->count()],
            ]);
        });
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{0:int,1:array<string,mixed>}
     */
    private function runCanary(array $options = []): array
    {
        $options = array_merge([
            '--json' => true,
            // Pacing is asserted by its own test; everywhere else it would only
            // make the suite slow.
            '--delay' => 0,
        ], $options);

        $options = array_filter($options, static fn ($v) => $v !== null);

        $exit = Artisan::call('tenancy:canary', $options);

        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded, 'the canary did not emit parseable JSON: '.Artisan::output());

        return [$exit, $decoded];
    }

    /** @return array<string,int> */
    private function rowCounts(): array
    {
        $counts = [];

        foreach ([
            'masjids', 'announcements', 'services', 'pages',
            'contact_us_messages', 'mobile_app_users', 'prayers',
        ] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Org '.uniqid(),
            'email' => 'org-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    private function makeAnnouncement(Masjid $masjid, string $title): Announcement
    {
        return Announcement::create([
            'masjid_id' => $masjid->id,
            'title' => $title,
            'summary' => $title,
            'details' => $title,
            'text' => $title,
            'start_date' => now()->subDay()->format('Y-m-d'),
            'end_date' => now()->addDay()->format('Y-m-d'),
        ]);
    }

    private function makeService(Masjid $masjid, string $title): Service
    {
        return Service::create([
            'masjid_id' => $masjid->id,
            'title' => $title,
            'summary' => $title,
            'description' => $title,
            'text' => $title,
        ]);
    }

    private function makePage(Masjid $masjid, string $slug): Page
    {
        return Page::create([
            'masjid_id' => $masjid->id,
            'slug' => $slug,
            'title' => $slug,
            'is_active' => true,
            'order' => 0,
        ]);
    }
}
