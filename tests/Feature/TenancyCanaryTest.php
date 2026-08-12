<?php

namespace Tests\Feature;

use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    /** @var resource|null the loopback origin, when a test started one */
    private $origin = null;

    protected function tearDown(): void
    {
        $this->stopLoopbackOrigin();

        parent::tearDown();
    }

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
    // The canary goes red on a leak its first three detectors cannot see
    // ================================================================

    #[Test]
    public function it_fails_when_every_organisation_is_served_one_organisations_rows(): void
    {
        // The shape measured on 2026-08-12: `scopeFilterByMasjid()` validates
        // the `masjid-id` header and then IGNORES it, pinning every public query
        // to the lowest masjid id. Applied to the real trait, the canary
        // reported exit 0, status clean, 36/36 probes, 23 passing checks and
        // ZERO findings — while every visitor to masjid 5's and masjid 13's
        // sites read masjid 1's content.
        //
        // It survived all three original detectors at once, and not by luck:
        // the 400 on a tenant-less request is still there (the mutation kept the
        // validation), every V1 Resource strips `masjid_id` so the body scan has
        // nothing to read, and the per-tenant row counts are EQUAL to each other
        // — which is also exactly what correct behaviour looks like.
        $this->registerPinnedTenantFixture();

        [$exit, $run] = $this->runCanary(['--only' => '__canary_pinned']);

        $this->assertSame(1, $exit, 'the canary exited 0 while both organisations were served the same rows');
        $this->assertSame('leak', $run['status']);

        $finding = collect($run['findings'])->firstWhere('kind', 'same_rows_served_to_two_tenants');

        $this->assertNotNull($finding, 'a pin to one organisation went undetected: '.
            json_encode($run['checks'], JSON_PRETTY_PRINT));
        $this->assertSame('critical', $finding['severity']);

        // The evidence has to be the PROOF, not the inference: the same primary
        // keys in two organisations' answers. Ids are unique across tenants in a
        // shared database, so these are literally the same rows.
        $this->assertSame([$this->masjidA->id, $this->masjidB->id], $finding['evidence']['tenants']);
        $this->assertNotEmpty($finding['evidence']['shared_record_ids']);

        // And it must be reproducible for BOTH organisations — one curl proves
        // nothing here, the pair is the whole argument.
        $this->assertStringContainsString('curl', $finding['request']['reproduce']);
        $this->assertStringContainsString('curl', $finding['evidence']['reproduce_other_tenant']);
        $this->assertNotSame($finding['request']['reproduce'], $finding['evidence']['reproduce_other_tenant']);
    }

    #[Test]
    public function it_fails_when_only_some_of_another_organisations_rows_leak_in(): void
    {
        // Generality: the detector compares SETS of primary keys, not whole
        // bodies, so a partial contamination — B's own row plus one of A's —
        // is caught on the same evidence. A fingerprint comparison would miss
        // this entirely, because the two bodies genuinely differ.
        $strayId = Announcement::query()->where('masjid_id', $this->masjidA->id)->value('id');

        Route::get('api/v1/__canary_pinned/partial', function () use ($strayId) {
            $tenant = (int) request()->header('masjid-id');

            if ($tenant <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            $rows = Announcement::query()
                ->where(fn ($q) => $q->where('masjid_id', $tenant)->orWhere('id', $strayId))
                ->get();

            return response()->api(200, 'ok', [
                'items' => AnnouncementResource::collection($rows),
                'pagination' => ['current_page' => 1, 'total' => $rows->count()],
            ]);
        });

        [$exit, $run] = $this->runCanary(['--only' => '__canary_pinned/partial']);

        $this->assertSame(1, $exit, 'one stray row shared between two organisations went undetected');

        $finding = collect($run['findings'])->firstWhere('kind', 'same_rows_served_to_two_tenants');

        $this->assertNotNull($finding);
        $this->assertSame([[$strayId]], array_values($finding['evidence']['shared_record_ids']),
            'the finding must name the stray row, not just say the answers overlapped');
    }

    #[Test]
    public function the_cross_tenant_comparison_says_what_it_cannot_see(): void
    {
        // The honest half of the same detector, and the reason it can be trusted
        // when it DOES pass. Two organisations with no rows get byte-identical
        // empty answers, which is what a pinned endpoint also looks like — so it
        // reports `skipped` and names the reason, rather than passing.
        //
        // This is not a corner case: every S1-S4 vertical table has zero
        // production rows today, so this is the honest answer for most of the
        // new surface until Al-Razi and Al-Aqsa put content in it.
        Route::get('api/v1/__canary_empty/announcements', function () {
            $tenant = (int) request()->header('masjid-id');

            if ($tenant <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            return response()->api(200, 'ok', [
                'items' => [],
                'pagination' => ['current_page' => 1, 'total' => 0],
            ]);
        });

        [$exit, $run] = $this->runCanary(['--only' => '__canary_empty']);

        $check = collect($run['checks'])->firstWhere('check', 'tenants-get-different-answers');

        $this->assertSame('skipped', $check['outcome'],
            'an endpoint that returned nothing to either organisation was reported as verified');
        $this->assertStringContainsString('BLIND', $check['detail']);

        // The run is still clean, and that is the deliberate half of the trade.
        // The structural checks DID observe something here — the endpoint
        // answered, it refused all three tenant-less spellings, it did not fault
        // — so calling the run incomplete would be its own lie, and would also
        // make the canary exit 2 every hour on a platform whose new verticals
        // have no data yet. An alarm that cries wolf gets silenced, which is the
        // same outcome as not having built this.
        //
        // What must never happen is the blindness being INVISIBLE. It is
        // counted in `coverage`, so a green run always reports how much of
        // itself it could not see.
        $this->assertSame(0, $exit);
        $this->assertSame('clean', $run['status']);
        $this->assertGreaterThan(0, $run['coverage']['checks']['skipped'],
            'a run with a blind detector reported nothing skipped');
        $this->assertGreaterThan(0, $run['coverage']['checks']['pass']);
    }

    #[Test]
    public function a_green_run_reports_how_much_of_itself_it_could_not_see(): void
    {
        // The human report is what an operator actually reads at 3am, and
        // "No cross-tenant leakage detected." is a sentence that invites more
        // trust than any run of this command has ever earned. It must carry its
        // own coverage.
        $this->artisan('tenancy:canary', ['--only' => 'api/v1', '--delay' => 0, '--max-requests' => 500])
            ->expectsOutputToContain('No cross-tenant leakage in what this run could see')
            ->expectsOutputToContain('skipped')
            ->assertExitCode(0);
    }

    // ================================================================
    // A green run must have looked at something
    // ================================================================

    #[Test]
    public function an_origin_that_redirects_every_probe_is_not_a_clean_run(): void
    {
        // Measured before this was fixed: exit=0, status=clean, probes=36/36,
        // findings=0, 23 `pass` rows — against an origin serving the application
        // not at all. `isSuccessful()` was 2xx and `isServerError()` was 5xx, so
        // 3xx sat in a gap no check read, and the tenant-less check counted
        // "not a 2xx" as "correctly refused".
        //
        // This is one config line from real. The site's nginx server block
        // answers plain HTTP with `return 301 https://$host$request_uri`, so a
        // CANARY_BASE_URL written `http://` makes every probe a redirect and
        // every run green, forever and silently.
        $base = $this->startLoopbackOrigin(301);

        [$exit, $run] = $this->runCanary([
            '--only' => 'api/v1',
            '--transport' => 'http',
            '--base-url' => $base,
            '--max-requests' => 500,
        ]);

        $this->assertSame(2, $exit, 'an origin that redirected every probe was certified clean');
        $this->assertSame('incomplete', $run['status']);

        // The run LOOKED complete, which is exactly why the probe counter cannot
        // be the thing an operator reads.
        $this->assertSame($run['probes']['planned'], $run['probes']['sent']);
        $this->assertGreaterThan(30, $run['probes']['sent']);

        $this->assertSame(0, $run['coverage']['endpoints_reached']);
        $this->assertSame(0, $run['coverage']['checks']['pass'],
            'a check reported `pass` about an endpoint the canary never reached');
        $this->assertStringContainsString('NOTHING WAS VERIFIED', implode(' ', $run['errors']));
        $this->assertStringContainsString('301', json_encode($run['coverage']['endpoints_not_reached']));
    }

    #[Test]
    public function an_origin_that_404s_every_probe_is_not_a_clean_run(): void
    {
        // The same gap from the other side, and the more dangerous half: a 404
        // IS a 4xx, so the tenant-less check reads it as a genuine refusal and
        // would otherwise pass on it honestly. Only the valid-tenant anchor
        // separates "the application refused a tenant-less request" from "there
        // is no application here".
        $base = $this->startLoopbackOrigin(404);

        [$exit, $run] = $this->runCanary([
            '--only' => 'api/v1',
            '--transport' => 'http',
            '--base-url' => $base,
            '--max-requests' => 500,
        ]);

        $this->assertSame(2, $exit, 'an origin that 404d every probe was certified clean');
        $this->assertSame('incomplete', $run['status']);
        $this->assertSame($run['probes']['planned'], $run['probes']['sent']);
        $this->assertSame(0, $run['coverage']['endpoints_reached']);
        $this->assertSame(0, $run['coverage']['checks']['pass']);

        $refusals = collect($run['checks'])->where('check', 'tenant-less-request-refused');

        $this->assertGreaterThan(0, $refusals->count());
        $this->assertSame([], $refusals->where('outcome', 'pass')->pluck('endpoint')->all(),
            'a 404 from a dead origin was read as the application refusing a tenant-less request');
    }

    #[Test]
    public function a_dead_transport_leaves_no_check_reporting_pass(): void
    {
        // The run level always got this one right — 36 ConnectionExceptions,
        // status `incomplete`, exit 2. What it got wrong was everything
        // underneath: all 23 per-endpoint checks read `pass`, including
        // "no-server-fault :: 5 probe(s)" for endpoints no request ever reached.
        // `pass` keyed off "every planned probe produced a result object", and a
        // FAILED probe is a result object.
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect to 127.0.0.1 port 9: Connection refused');
        });

        [$exit, $run] = $this->runCanary([
            '--only' => 'api/v1',
            '--transport' => 'http',
            '--base-url' => 'http://127.0.0.1:9',
            '--max-requests' => 500,
        ]);

        $this->assertSame(2, $exit);
        $this->assertSame('incomplete', $run['status']);
        $this->assertSame(0, $run['coverage']['checks']['pass'],
            'checks reported `pass` about responses that never arrived');

        $faultChecks = collect($run['checks'])->where('check', 'no-server-fault');

        $this->assertGreaterThan(0, $faultChecks->count());
        $this->assertSame([], $faultChecks->where('outcome', 'pass')->pluck('endpoint')->all(),
            'an endpoint cleared of server faults by probes that never got a response');
    }

    #[Test]
    public function a_check_with_nothing_to_look_at_reports_skipped_not_pass(): void
    {
        // `body-names-only-the-requested-tenant` reported `pass — no masjid_id
        // seen` for all 8 /api/v1 endpoints on EVERY run since it was written:
        // the V1 Resources strip `masjid_id`, so it was structurally incapable
        // of ever observing anything on that surface, and it printed `ok`
        // anyway. `$observed` was computed and thrown away.
        [, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 500]);

        $blind = collect($run['checks'])
            ->where('check', 'body-names-only-the-requested-tenant')
            ->filter(fn (array $c) => str_contains($c['detail'], 'no masjid_id'));

        $this->assertGreaterThanOrEqual(6, $blind->count(),
            'the /api/v1 Resources strip masjid_id — this check should be blind across that surface');

        $this->assertSame([], $blind->where('outcome', 'pass')->pluck('endpoint')->all(),
            'a check that saw no masjid_id at all reported `pass`');

        foreach ($blind as $check) {
            $this->assertSame('skipped', $check['outcome']);
            $this->assertStringContainsString('BLIND', $check['detail']);
        }
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
     * The pin: the `masjid-id` header is validated and then ignored, and every
     * query answers with the lowest organisation's rows.
     *
     * This is `scopeFilterByMasjid()` with `$resourceId` swapped for a constant
     * — a `where('masjid_id', $default)` typo, a mis-plumbed tenant resolver, a
     * copied query that kept its literal. Same reasoning as
     * `registerFailOpenFixture()`: reintroducing it on the real trait would be a
     * live cross-tenant hole in a file another agent is working in, so the SHAPE
     * is reproduced here. Verified against the real trait during development —
     * the mutation produced five `same_rows_served_to_two_tenants` findings
     * across announcements, services, pages, pages/menu and home.
     */
    private function registerPinnedTenantFixture(): void
    {
        Route::get('api/v1/__canary_pinned/announcements', function () {
            $resourceId = (int) request()->header('masjid-id');

            // The header is still REQUIRED — which is why the fail-open detector
            // and the count arithmetic both stay green through this.
            if ($resourceId <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            // ...and then ignored.
            $pinned = Masjid::query()->orderBy('id')->value('id');
            $rows = Announcement::query()->where('masjid_id', $pinned)->get();

            // Through the real V1 Resource, so the response strips `masjid_id`
            // exactly as production does. Without that this fixture would be
            // caught by the body scan and prove nothing.
            return response()->api(200, 'ok', [
                'items' => AnnouncementResource::collection($rows),
                'pagination' => ['current_page' => 1, 'total' => $rows->count()],
            ]);
        });
    }

    /**
     * A real origin on the loopback interface that answers every request with
     * one status — the `return 301` proxy, or the misrouted vhost.
     *
     * Deliberately a socket rather than `Http::fake()`: the failure being pinned
     * lives in the gap between the transport and the verdict, and a fake would
     * let the test pass while HttpTransport itself (`withoutRedirecting()`, the
     * status it reports for a body-less redirect) drifted underneath it.
     */
    private function startLoopbackOrigin(int $status): string
    {
        $port = $this->freePort();
        $dir = sys_get_temp_dir().'/canary-origin-'.$status.'-'.$port;

        @mkdir($dir, 0777, true);

        file_put_contents($dir.'/router.php', $status === 301
            ? '<?php header("Location: https://".($_SERVER["HTTP_HOST"] ?? "example.test").$_SERVER["REQUEST_URI"], true, 301); return true;'
            : '<?php http_response_code('.$status.'); header("Content-Type: text/html"); echo "<html><body>not found</body></html>"; return true;');

        $this->origin = proc_open(
            'exec '.escapeshellarg(PHP_BINARY).' -S 127.0.0.1:'.$port.
                ' -t '.escapeshellarg($dir).' '.escapeshellarg($dir.'/router.php'),
            [1 => ['file', $dir.'/out.log', 'a'], 2 => ['file', $dir.'/err.log', 'a']],
            $pipes
        );

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return 'http://127.0.0.1:'.$port;
            }

            usleep(50_000);
        }

        // Never skipped. A test that cannot look is the exact failure this file
        // exists to make impossible.
        $this->fail('the loopback origin never came up on port '.$port);
    }

    private function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        $this->assertNotFalse($server, 'could not reserve a loopback port: '.$errstr);

        $name = (string) stream_socket_get_name($server, false);

        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function stopLoopbackOrigin(): void
    {
        if (is_resource($this->origin)) {
            proc_terminate($this->origin, 9);
            proc_close($this->origin);
            $this->origin = null;
        }
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
