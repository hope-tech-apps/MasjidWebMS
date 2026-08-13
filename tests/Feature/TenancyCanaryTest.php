<?php

namespace Tests\Feature;

use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\Announcement;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $this->registerBlindComparisonEndpoint('api/v1/__canary_empty/announcements');

        [$exit, $run] = $this->runCanary(['--only' => '__canary_empty']);

        $check = collect($run['checks'])->firstWhere('check', 'tenants-get-different-answers');

        $this->assertSame('skipped', $check['outcome'],
            'an endpoint that returned nothing to either organisation was reported as verified');
        $this->assertStringContainsString('BLIND', $check['detail']);

        // ...and the run says it too. This assertion is the one that changed on
        // 2026-08-12: it used to read `assertSame(0, $exit)` — the check was
        // honest, the verdict was not, and only the verdict is what the cron
        // alert path and the deploy gate can read. `--only` narrows the graded
        // surface to this one endpoint, so a blind comparison here is a blind
        // comparison over ALL of it.
        //
        // Still `partial` and not `incomplete`: the structural checks DID
        // observe something (the endpoint answered, it refused all three
        // tenant-less spellings, it did not fault), so this run is evidence
        // about part of the platform. See applyComparisonFloor().
        $this->assertSame(3, $exit, 'a run whose only graded endpoint could not be compared exited clean');
        $this->assertSame('partial', $run['status']);
        $this->assertSame([], $run['findings'], 'a blind detector was reported as a leak');

        $comparison = $run['coverage']['cross_tenant_comparison'];

        $this->assertFalse($comparison['met']);
        $this->assertSame(0, $comparison['compared']);
        $this->assertSame(1, $comparison['comparable']);
        $this->assertArrayHasKey('api/v1/__canary_empty/announcements', $comparison['blind'],
            'the blind endpoint was not named at the run level');

        $this->assertGreaterThan(0, $run['coverage']['checks']['skipped'],
            'a run with a blind detector reported nothing skipped');
        $this->assertGreaterThan(0, $run['coverage']['checks']['pass']);
    }

    // ================================================================
    // ...and a leak the DIFFERENCE detector composes to nothing against
    // ================================================================

    #[Test]
    public function it_fails_when_two_organisations_are_served_each_others_rows(): void
    {
        // THE MEASURED CASE, 2026-08-13, and the one this command exists for:
        // an /api/v1 endpoint serving masjid 1 masjid 2's announcements and
        // masjid 2 masjid 1's — a complete cross-tenant read of every
        // announcement on the platform. Before the row-ownership assertion:
        //
        //     exit=0  status=clean  findings=[]
        //     origin-answered                       pass
        //     no-server-fault                       pass
        //     body-names-only-the-requested-tenant  skipped  BLIND — no masjid_id
        //     tenants-get-different-answers         pass     "no shared record ids (1/2 records)"
        //     tenant-less-request-refused           pass
        //
        // Every detector agreed, and every one was answering a question the bug
        // does not touch. `tenants-get-different-answers` proves only that the
        // two answers DIFFER; a swap makes them differ perfectly. Worse, the
        // endpoint counted as `compared`, so it inflated the arithmetic that
        // exists to say how much of the surface is being watched.
        $this->registerSwappedTenantFixture();

        [$exit, $run] = $this->runCanary(['--only' => '__canary_swap']);

        $this->assertSame(1, $exit, 'the canary exited 0 while each organisation was served the other\'s rows: '.
            json_encode($run['checks'], JSON_PRETTY_PRINT));
        $this->assertSame('leak', $run['status']);

        $findings = collect($run['findings'])->where('kind', 'foreign_rows_for_requested_tenant');

        // BOTH directions, because both are separately wrong and an operator
        // who fixes one has fixed half of it.
        $this->assertCount(2, $findings, 'only one side of a two-way swap was reported');

        $forA = $findings->firstWhere('evidence.requested', $this->masjidA->id);

        $this->assertNotNull($forA);
        $this->assertSame('critical', $forA['severity']);
        $this->assertSame(0, $forA['evidence']['own_rows'],
            'the answer to masjid A was reported as containing some of its own rows');

        // The evidence has to be the PROOF: named ids, named owner. "It
        // leaked" is a bug report nobody can act on at 3am.
        $this->assertSame(
            [$this->masjidB->id],
            array_map('intval', array_keys($forA['evidence']['foreign_rows'])),
            'the finding did not name WHICH organisation owns the rows masjid A was handed'
        );
        $this->assertStringContainsString('curl', $forA['request']['reproduce']);

        // ...and the detector that used to certify this must still say what it
        // saw, unchanged: the two answers really were different. It was the
        // wrong question, not a broken answer.
        $difference = collect($run['checks'])
            ->where('check', 'tenants-get-different-answers')
            ->firstWhere('endpoint', 'api/v1/__canary_swap/announcements');

        $this->assertSame('pass', $difference['outcome']);
        $this->assertStringContainsString('no shared record ids', $difference['detail']);

        // And the body scan is blind here, which is why reading the body harder
        // could never have closed this.
        $body = collect($run['checks'])
            ->where('check', 'body-names-only-the-requested-tenant')
            ->firstWhere('endpoint', 'api/v1/__canary_swap/announcements');

        $this->assertSame('skipped', $body['outcome']);
        $this->assertStringContainsString('BLIND', $body['detail']);
    }

    #[Test]
    public function row_ownership_never_accuses_an_endpoint_of_serving_its_own_organisation(): void
    {
        // The other half of the same fix, and the half that was measured GOING
        // WRONG. The first cut of the ownership assertion scored candidate
        // tables purely by how many of a bucket's ids they contained, and a
        // healthy `--all` run went RED:
        //
        //     FINDING foreign_rows_for_requested_tenant :: api/mobile/masjids/{masjid_id}
        //     answered masjid 2 with rows the database says belong to masjid 1
        //     — (root) ids 2 (masjid 1)
        //
        // `GET /api/mobile/masjids/2` returns the organisation's OWN row, so
        // `(root)` carried the id 2 — which is also `announcements.id = 2`, a
        // row belonging to masjid A. Three tables agreed unanimously and
        // wrongly. The fixture in setUp() reproduces that collision exactly: A
        // owns announcements 1 and 2, and B's own masjid id is 2.
        //
        // A canary that cries wolf gets silenced, which is the same outcome as
        // not having built it. So an id may only be attributed through a table
        // the ENDPOINT NAMES — see relationsNamedBy().
        [$exit, $run] = $this->runCanary(['--all' => true, '--max-requests' => 900]);

        $this->assertSame([], collect($run['findings'])->where('kind', 'foreign_rows_for_requested_tenant')->all(),
            'the ownership assertion accused a correct endpoint: '.json_encode($run['findings'], JSON_PRETTY_PRINT));
        $this->assertSame(0, $exit);
        $this->assertSame('clean', $run['status']);

        $ownership = $run['coverage']['row_ownership'];

        // Named, not silently dropped. `coverage.row_ownership.unattributed` is
        // the GRADED surface's summary, so the per-endpoint truth for a mobile
        // endpoint is in the check table — where it must read `skipped`, and
        // never `pass`, because a check that could not look and a check that
        // looked and was happy are the two answers a canary must never confuse.
        $check = collect($run['checks'])
            ->where('check', 'answer-carries-the-requested-tenants-rows')
            ->firstWhere('endpoint', 'api/mobile/masjids/{masjid_id}');

        $this->assertNotNull($check);
        $this->assertSame('skipped', $check['outcome'],
            'an endpoint whose only id is its own organisation\'s primary key was reported as verified');
        $this->assertStringContainsString('BLIND', $check['detail']);

        // And the relation that CANNOT attribute is named with its reason.
        // `Masjid::gallery` is hasMany(Media, 'model_id') — a polymorphic owner
        // id, so `model_id = 5` means "row 5 of some model", not "masjid 5".
        $this->assertArrayHasKey('gallery', $ownership['tables_skipped']);
        $this->assertStringContainsString('model_id', $ownership['tables_skipped']['gallery']);
        $this->assertSame(['announcements', 'services', 'pages'], $ownership['tables']);
    }

    #[Test]
    public function the_row_ownership_floor_separates_rows_it_cannot_place_from_nothing_to_place(): void
    {
        // THE THRESHOLD ARGUMENT, and it is not a proportion.
        //
        // The first version of this floor was ZERO — any number of endpoints
        // could be unattributable and the run stayed clean — on the grounds that
        // the endpoint list is DISCOVERED and grows, so a proportional floor
        // would go permanently amber for a reason no operator could act on
        // tonight. That reasoning was right about proportions and wrong about
        // actionability, and it cost the detector its headline case: measured on
        // 2026-08-13, a TOTAL SWAP under a bucket no relation is named by —
        // masjid A served masjid B's announcements and vice versa — scored
        // `exit=0 status=clean findings=0`, `attributable 2, attributed 1,
        // met TRUE`. Every visitor to one masjid's site reading another's
        // announcements, and the hourly line said clean.
        //
        // So the floor is not "how many" but "WHICH KIND OF BLINDNESS":
        //
        //   nothing_to_attribute — the endpoint served no rows. Nothing to
        //     place, nothing learned, nothing wrong. Stays clean. This is the
        //     normal state of a vertical that has not shipped data yet, and it
        //     is why a proportional floor was the wrong instrument.
        //
        //   unplaced — rows WERE served and nothing could place them. The
        //     detector was handed data and went blind on it, which is exactly
        //     the swap above. Degrades the run: `partial`, exit 3, `warning` —
        //     never a leak, because blindness is not evidence of one.
        //
        // And the remedy is one line: `tables_available` names the relations
        // this Masjid has that could attribute these rows and are missing from
        // `canary.compare_by`. That is what makes it fair to alarm.
        foreach (range(1, 4) as $n) {
            $this->registerUnattributableEndpoint("api/v1/__canary_opaque{$n}/things");
        }

        [$exit, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 900]);

        $ownership = $run['coverage']['row_ownership'];

        $this->assertSame(11, $ownership['attributable']);
        $this->assertSame(5, $ownership['attributed']);

        // Rows were served that nothing could place, so the run is NOT clean…
        $this->assertFalse($ownership['met'], 'rows nothing could place were treated as fine');
        $this->assertCount(4, $ownership['unplaced']);
        $this->assertArrayHasKey('api/v1/__canary_opaque1/things', $ownership['unplaced']);

        // …but it is amber, never red. A blind detector is not a leak.
        $this->assertSame(3, $exit);
        $this->assertSame('partial', $run['status']);
        $this->assertSame([], $run['findings']);

        // Every one of them is NAMED, and so is the fix.
        $this->assertArrayHasKey('api/v1/__canary_opaque1/things', $ownership['unattributed']);
        $this->assertIsArray($ownership['tables_available']);
    }

    #[Test]
    public function an_endpoint_with_no_rows_to_place_leaves_a_healthy_run_clean(): void
    {
        // The other half of the same rule, and the one that keeps this from
        // becoming the cry-wolf failure the rest of this file is a reaction to:
        // an endpoint that served NOTHING is not a blind detector, it is an
        // organisation that has not written that content yet. Four of them, and
        // the run must still be clean and exit 0.
        foreach (range(1, 4) as $n) {
            $this->registerBlindComparisonEndpoint("api/v1/__canary_empty{$n}/things");
        }

        [$exit, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 900]);

        $ownership = $run['coverage']['row_ownership'];

        $this->assertSame([], $ownership['unplaced'], 'an empty answer was counted as a blind detector');
        $this->assertTrue($ownership['met']);
        $this->assertSame(0, $exit);
        $this->assertSame('clean', $run['status']);
    }

    #[Test]
    public function a_run_that_can_trace_no_row_at_all_says_so(): void
    {
        // The bottom of that floor, which is where zero earns its place. Every
        // compared organisation dormant: the answers are empty, nothing can be
        // traced to an owner, and the positive assertion — the ONLY detector
        // that sees a swap — is completely dark. A green verdict there would
        // mean "no swap detected" while no swap could have been detected.
        $this->emptyEveryOrganisation();

        [$exit, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 900]);

        $ownership = $run['coverage']['row_ownership'];

        $this->assertSame(0, $ownership['attributed']);
        $this->assertGreaterThan(0, $ownership['attributable']);
        $this->assertFalse($ownership['met']);
        $this->assertContains('row_ownership_dark', $run['degraded_by']);

        // partial, never incomplete: the canary reached the application and
        // verified the fail-open shape. What it lacks is rows, which is a data
        // decision taken in daylight rather than a page.
        $this->assertSame(3, $exit);
        $this->assertSame('partial', $run['status']);
        $this->assertSame([], $run['findings']);
    }

    #[Test]
    public function an_endpoint_pinned_to_a_dormant_organisation_is_named_and_not_caught(): void
    {
        // THE ACCEPTED RESIDUAL, pinned so that nobody later reads the
        // row-ownership assertion as having closed it.
        //
        // `/api/v1/__canary_dormant_pin/announcements` validates the header and
        // then serves a THIRD, dormant organisation's rows to everyone. It has
        // no rows to serve, so both compared organisations get an empty list —
        // and an empty list has no owner and no ids to tell apart. Measured:
        // exit 0, clean, before AND after the ownership assertion.
        //
        // No assertion over an empty body can close this. What the run owes is
        // to say so, and it now says so on BOTH axes rather than one.
        $dormant = $this->makeMasjid();
        $dormantId = $dormant->id;

        Route::get('api/v1/__canary_dormant_pin/announcements', function () use ($dormantId) {
            $tenant = (int) request()->header('masjid-id');

            if ($tenant <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            $rows = Announcement::query()->where('masjid_id', $dormantId)->get();

            return response()->api(200, 'ok', [
                'items' => AnnouncementResource::collection($rows),
                'pagination' => ['current_page' => 1, 'total' => $rows->count()],
            ]);
        });

        [$exit, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 900]);

        $this->assertSame(0, $exit, 'an endpoint pinned to a dormant organisation is a residual, not a catch — '.
            'if this now exits non-zero, say so in the report rather than deleting the assertion');
        $this->assertSame('clean', $run['status']);

        $uri = 'api/v1/__canary_dormant_pin/announcements';

        $this->assertArrayHasKey($uri, $run['coverage']['cross_tenant_comparison']['blind'],
            'the residual endpoint was not named as uncompared');
        $this->assertArrayHasKey($uri, $run['coverage']['row_ownership']['unattributed'],
            'the residual endpoint was not named as untraceable');

        // Two blind of nine is still a strict majority, so the verdict is
        // unchanged — which is the residual, stated as arithmetic rather than
        // as a hope.
        $this->assertTrue($run['coverage']['cross_tenant_comparison']['met']);
        $this->assertTrue($run['coverage']['row_ownership']['met']);
    }

    // ================================================================
    // ...and a leaking BUCKET inside an otherwise-attributed endpoint
    // ================================================================

    #[Test]
    public function a_leaking_bucket_does_not_pass_on_its_attributed_siblings_credit(): void
    {
        // C1, MEASURED 2026-08-13 before this fix:
        //
        //     exit=0 status=clean findings=0
        //       tenants-get-different-answers              pass  "no shared record ids (3/3 records)"
        //       answer-carries-the-requested-tenants-rows  pass  "masjid 1: 2 row(s) traced back to it;
        //                                                         masjid 2: 1 row(s); 0 ambiguous,
        //                                                         3 not in an attributable table"
        //       row_ownership: attributable=1 attributed=1 unattributed=[] met=true
        //
        // Every visitor to org A's site read org B's announcements, and the run
        // said clean with 1 of 1 attributed and nothing unattributed. Attribution
        // was graded per ENDPOINT: one anchored bucket carrying its own rows was
        // enough to make the whole answer `pass` and to count the endpoint as
        // watched, and the only trace of the swapped list was the phrase "3 not
        // in an attributable table" inside a passing detail string.
        //
        // The shared-id comparison cannot see it either: overlap is bucketed per
        // key path, and the swap is symmetric across buckets.
        //
        // `featured` is not a contrivance — it is the ordinary vocabulary of a
        // home feed, and `/api/v1/home` already ships three buckets (`main`,
        // `about_us`, `contact_us`) that no relation is named by.
        $this->registerLeakingSiblingBucketFixture();

        [$exit, $run] = $this->runCanary(['--only' => '__canary_feed']);

        $ownership = $run['coverage']['row_ownership'];
        $uri = 'api/v1/__canary_feed/home';

        // The endpoint may NOT be counted as attributed on the strength of the
        // one bucket it could trace. Part of the answer was never traced, and
        // the coverage number exists to say how much of the surface a swap
        // would show up on.
        $this->assertSame(0, $ownership['attributed'],
            'an endpoint with an untraceable id-carrying bucket still counted as watched: '.
            json_encode($run['checks'], JSON_PRETTY_PRINT));
        $this->assertArrayHasKey($uri, $ownership['unattributed'],
            'the untraceable bucket was absorbed into the endpoint\'s pass');

        // NAMED, by bucket and by id. "Partially attributed" that does not say
        // WHICH list it could not read is a coverage number nobody can act on.
        $this->assertStringContainsString('featured', $ownership['unattributed'][$uri]);

        $check = collect($run['checks'])
            ->where('check', 'answer-carries-the-requested-tenants-rows')
            ->firstWhere('endpoint', $uri);

        $this->assertSame('skipped', $check['outcome'],
            'a partially attributed answer reported `pass` — the outcome a canary must never confuse');
        $this->assertStringContainsString('featured', $check['detail']);

        // It is still not a FINDING, and it must not become one: nothing here
        // proves `featured` is announcements. Guessing that from ids alone is
        // exactly what turned a healthy --all run red in round three.
        $this->assertSame([], $run['findings']);

        // ...and the run says so in its verdict rather than certifying.
        $this->assertSame(3, $exit);
        $this->assertSame('partial', $run['status']);
        $this->assertContains('row_ownership_dark', $run['degraded_by']);
    }

    #[Test]
    public function a_global_lookup_list_beside_a_scoped_one_is_not_a_leak(): void
    {
        // C2, MEASURED 2026-08-13 before this fix, on a PERFECTLY SCOPED
        // endpoint that also returns a global lookup list:
        //
        //     exit=1 status=leak findings=3
        //       same_rows_served_to_two_tenants   :: both answers contain categories ids 1, 2, 3
        //       foreign_rows_for_requested_tenant :: masjid 1 … categories ids 3 (masjid 2)  [CRITICAL]
        //       foreign_rows_for_requested_tenant :: masjid 2 … categories ids 1, 2          [CRITICAL]
        //
        // The anchor was the union of URI segments AND bucket-path segments, so
        // every bucket of `/api/v1/…/services` inherited the `services` anchor
        // whether it was services or not — and lookup ids 1, 2, 3 exist in
        // `services` too, owned by both organisations. Three tables agreed,
        // unanimously and wrongly, exactly as they did on `(root)` in round
        // three.
        //
        // This is the cry-wolf failure the whole file is written against, and
        // the only escape hatch was `canary.global_endpoints` — which is per
        // ENDPOINT, so silencing `categories` also stopped watching `items`,
        // the bucket carrying the real tenant rows.
        $this->registerGlobalLookupSiblingFixture();

        [$exit, $run] = $this->runCanary(['--only' => '__canary_lookup']);

        $this->assertSame([], $run['findings'],
            'a correctly scoped endpoint was accused of a cross-tenant leak by its lookup list: '.
            json_encode(array_column($run['findings'], 'summary'), JSON_PRETTY_PRINT));
        $this->assertNotSame(1, $exit, 'the false accusation still pages');
        $this->assertNotSame('leak', $run['status']);

        // Refusing to accuse is not the same as claiming to have checked. The
        // contradiction is NAMED — both buckets, with the two remedies — and
        // the endpoint loses its attribution credit, which is the honest price.
        $uri = 'api/v1/__canary_lookup/services';
        $ownership = $run['coverage']['row_ownership'];

        $this->assertArrayHasKey($uri, $ownership['unattributed']);
        $this->assertStringContainsString('categories', $ownership['unattributed'][$uri]);
        $this->assertStringContainsString('global_buckets', $ownership['unattributed'][$uri],
            'the report did not say how to resolve the contradiction it refused to resolve itself');
    }

    #[Test]
    public function declaring_one_lookup_bucket_leaves_its_siblings_watched(): void
    {
        // The other half of C2: the hatch has to be per BUCKET. Declaring
        // `categories` global must silence it and leave `items` — the bucket
        // carrying the real tenant rows — fully compared and fully attributed.
        $this->registerGlobalLookupSiblingFixture();

        config(['canary.global_buckets' => [
            'api/v1/__canary_lookup/services' => ['categories'],
        ]]);

        [$exit, $run] = $this->runCanary(['--only' => '__canary_lookup']);

        $uri = 'api/v1/__canary_lookup/services';

        $this->assertSame([], $run['findings']);
        $this->assertSame(0, $exit, 'a declared lookup bucket left the endpoint amber: '.
            json_encode($run['coverage']['row_ownership'], JSON_PRETTY_PRINT));
        $this->assertSame('clean', $run['status']);

        // Still WATCHED — the whole point of the finer hatch.
        $this->assertArrayNotHasKey($uri, $run['coverage']['row_ownership']['unattributed']);
        $this->assertArrayNotHasKey($uri, $run['coverage']['cross_tenant_comparison']['blind']);

        $check = collect($run['checks'])
            ->where('check', 'answer-carries-the-requested-tenants-rows')
            ->firstWhere('endpoint', $uri);

        $this->assertSame('pass', $check['outcome']);
        $this->assertStringContainsString('categories', $check['detail'],
            'the declaration was honoured silently; an endpoint whose watch was narrowed must say so');

        // ...and a leak in the STILL-WATCHED bucket must still page, or the
        // declaration has quietly turned the endpoint off after all.
        $this->registerGlobalLookupSiblingFixture(swapped: true);

        config(['canary.global_buckets' => [
            'api/v1/__canary_lookup_swap/services' => ['categories'],
        ]]);

        [$swapExit, $swapRun] = $this->runCanary(['--only' => '__canary_lookup_swap']);

        $this->assertSame(1, $swapExit, 'declaring the lookup bucket stopped the endpoint being watched');
        $this->assertNotEmpty(collect($swapRun['findings'])->where('kind', 'foreign_rows_for_requested_tenant'));
    }

    #[Test]
    public function two_sibling_lists_of_the_same_table_still_page_when_they_agree(): void
    {
        // The BOUNDARY of the contradiction rule, pinned on the side where it
        // must not bite. `/api/v1/pages/menu` returns `menu_items` AND
        // `button_items` and both are pages — neither names a relation itself,
        // so both inherit the route's. A swap makes both of them carry the
        // other organisation's rows, in every answer, and that is UNANIMOUS:
        // there is no contradiction, nothing is contested, and it must still
        // exit 1 in both directions.
        //
        // If a later change makes any two sibling lists enough to suppress an
        // accusation, this test is what fails.
        Route::get('api/v1/__canary_twin/pages', function () {
            $tenant = (int) request()->header('masjid-id');

            if ($tenant <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            $other = Masjid::query()->where('id', '!=', $tenant)->orderBy('id')->value('id');
            $theirs = Page::query()->where('masjid_id', $other)->get(['id', 'slug', 'title']);

            return response()->api(200, 'ok', [
                'menu_items' => $theirs,
                'button_items' => $theirs,
            ]);
        });

        [$exit, $run] = $this->runCanary(['--only' => '__canary_twin']);

        $findings = collect($run['findings'])->where('kind', 'foreign_rows_for_requested_tenant');

        $this->assertSame(1, $exit, 'a swap across two sibling lists of the same table stopped paging: '.
            json_encode($run['checks'], JSON_PRETTY_PRINT));
        $this->assertCount(2, $findings, 'only one side of a two-way swap was reported');
    }

    #[Test]
    public function a_leak_in_one_of_two_inherited_lists_is_a_named_ticket_not_a_page(): void
    {
        // THE PRICE OF THE CONTRADICTION RULE, pinned so that it is a decision
        // on the record rather than something discovered later.
        //
        // `menu_items` is correctly scoped and `button_items` is PINNED to one
        // organisation — one query in the same controller that lost its
        // `filterByMasjid()`. That is a real cross-tenant read, and before this
        // round the shared-id comparison caught it: both organisations are
        // handed the same `button_items` ids, so it exited 1.
        //
        // It no longer does. Inside the pinned organisation's OWN answer the
        // two lists agree; inside the other's they disagree — one list of
        // `pages` cannot be both scoped and unscoped, so the canary knows one
        // of the two inherited anchors is wrong and it does not know which.
        // Refusing to guess is what stops it accusing the identical shape made
        // by a global lookup list, which was measured paging against correct
        // code (see a_global_lookup_list_beside_a_scoped_one_is_not_a_leak).
        //
        // What it owes in exchange is total visibility, and that is what the
        // assertions below are: exit 3 rather than 0, both lists named, the
        // exact ids, and both remedies. This is a ticket the morning after, not
        // a silence.
        $pinned = $this->masjidA->id;

        Route::get('api/v1/__canary_halfleak/pages', function () use ($pinned) {
            $tenant = (int) request()->header('masjid-id');

            if ($tenant <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            return response()->api(200, 'ok', [
                'menu_items' => Page::query()->where('masjid_id', $tenant)->get(['id', 'slug', 'title']),
                // The lost filter.
                'button_items' => Page::query()->where('masjid_id', $pinned)->get(['id', 'slug', 'title']),
            ]);
        });

        [$exit, $run] = $this->runCanary(['--only' => '__canary_halfleak']);

        $uri = 'api/v1/__canary_halfleak/pages';

        $this->assertSame([], $run['findings'], 'this shape is a ticket by decision — if it pages again, '.
            'check that the false accusation in a_global_lookup_list_beside_a_scoped_one_is_not_a_leak is '.
            'still closed before keeping the change');
        $this->assertSame(3, $exit);
        $this->assertSame('partial', $run['status']);

        // Visible on BOTH axes, by name, with the ids and the two remedies.
        $unattributed = $run['coverage']['row_ownership']['unattributed'][$uri] ?? '';

        $this->assertStringContainsString('menu_items', $unattributed);
        $this->assertStringContainsString('button_items', $unattributed);
        $this->assertStringContainsString('global_buckets', $unattributed);
        $this->assertStringContainsString('fix the scope', $unattributed);

        $blind = $run['coverage']['cross_tenant_comparison']['blind'][$uri] ?? '';

        $this->assertStringContainsString('contested', $blind,
            'the comparison went quiet without saying which list it could not use as proof');
    }

    // ================================================================
    // ...and a route the CATALOGUE refused is not a watched route
    // ================================================================

    #[Test]
    public function a_route_the_catalogue_refused_is_named_rather_than_forgotten(): void
    {
        // C3, MEASURED 2026-08-13: a total swap with NO masjid filter at all on
        // an `{id}` route, and a parameterless route behind a limiter the canary
        // may not spend. `--all` reported:
        //
        //     exit=0  status=clean  findings=0  blind_spots: []
        //     uri mentioned anywhere in the run payload: FALSE
        //
        // `ProbeCatalog` refuses both — a parameter other than `{masjid_id}`, and
        // a named limiter outside `throttle_allowlist` — and `blind_spots` is
        // built only from `notProbed` (budget truncation) and `unreached`. So a
        // route the catalogue REFUSED was not in the plan, not in `blind_spots`,
        // not in `unattributed`, not in coverage, and not in the verdict.
        //
        // On-call read `clean`, `endpoints_reached: 31/31`, `blind_spots: []`,
        // and nothing said six public GET routes were never looked at — among
        // them `/api/v1/offerings/{slug}`, which publishes a program's name,
        // seats and PRICES and is refused twice over.
        Route::get('api/v1/__canary_unplanned/announcements/{id}', function (string $id) {
            // No masjid filter at all — findOrFail($id), the exact shape of
            // commit 8dde5db "close two cross-tenant holes on the public API".
            return response()->api(200, 'ok', new AnnouncementResource(Announcement::findOrFail($id)));
        });

        Route::get('api/v1/__canary_unplanned/quote', fn () => response()->api(200, 'ok', ['price' => 1]))
            ->middleware('throttle:registration-quote');

        [$exit, $run] = $this->runCanary(['--all' => true, '--max-requests' => 900]);

        // The verdict is UNCHANGED, deliberately: these refusals are a standing
        // structural fact, not an event in this run, and a canary that goes
        // amber every night for something no operator can act on tonight is
        // itself the defect. See ProbeCatalog::declined().
        $this->assertSame(0, $exit);
        $this->assertSame('clean', $run['status']);
        $this->assertSame([], $run['blind_spots'],
            'a refused route was charged to the run as an unreachable endpoint');

        // ...and it is NAMED, with its reason, at every verdict level.
        $notPlanned = $run['coverage']['routes_not_planned'];

        $this->assertArrayHasKey('api/v1/__canary_unplanned/announcements/{id}', $notPlanned);
        $this->assertStringContainsString('{id}', $notPlanned['api/v1/__canary_unplanned/announcements/{id}']);

        $this->assertArrayHasKey('api/v1/__canary_unplanned/quote', $notPlanned);
        $this->assertStringContainsString('registration-quote', $notPlanned['api/v1/__canary_unplanned/quote']);

        // The real ones, so this test fails the day somebody starts probing them
        // (good) or the day the route is deleted (also good). These are the six
        // named in the review.
        foreach ([
            'api/v1/announcements/{id}',
            'api/v1/services/{id}',
            'api/v1/pages/{slug}',
            'api/v1/gallery/{id}',
            'api/v1/offerings/{slug}',
            'api/v1/zakat/nisab',
        ] as $uri) {
            $this->assertArrayHasKey($uri, $notPlanned, "{$uri} is a public GET nothing in the run mentions");
        }

        // The money surface is doubly refused and the report has to say both
        // reasons, or the fix looks like one config line.
        $this->assertStringContainsString('{slug}', $notPlanned['api/v1/offerings/{slug}']);
        $this->assertStringContainsString('registration-quote', $notPlanned['api/v1/offerings/{slug}']);

        // The graded surface is called out separately: an unwatched route on
        // `api/v1` is a hole in the surface the verdict claims to cover.
        $this->assertGreaterThanOrEqual(6, $run['coverage']['routes_not_planned_on_graded_surface']);

        // And the human report says it on a CLEAN run, which is the only run an
        // operator reads without already being worried.
        //
        // `--only api/v1` and not a second `--all`: the mobile surface is
        // limited to 60/min per IP and the run above already spent most of that
        // bucket, so a second full run would abort on a 429 and be exit 2 — the
        // canary refusing to push through a limiter, working exactly as
        // designed, and nothing to do with what is being asserted here.
        // (The substrings must not overlap: the mocked console matches each
        // expectation against every write in declaration order, and the first
        // matching one absorbs the line — so each expectation names a different
        // route rather than sharing the `never planned:` prefix.)
        $this->artisan('tenancy:canary', ['--only' => 'api/v1', '--delay' => 0, '--max-requests' => 900])
            ->expectsOutputToContain('never planned: api/v1/announcements/{id}')
            ->expectsOutputToContain('never planned: api/v1/offerings/{slug}')
            ->expectsOutputToContain('public GET route(s) were never planned at all')
            ->assertExitCode(0);
    }

    // ================================================================
    // ...and a detector that went blind is not a watched endpoint
    // ================================================================

    #[Test]
    public function a_dormant_pair_of_organisations_cannot_certify_the_platform(): void
    {
        // THE MEASURED CASE, 2026-08-12. The real `SearchableTrait::scopeFilterByMasjid`
        // mutated in a CI copy to validate the `masjid-id` header and then pin
        // every public query to the lowest organisation:
        //
        //     both compared orgs hold rows   exit 1  leak   5 x same_rows_served_to_two_tenants
        //     both compared orgs dormant     exit 0  clean  0 findings, blind_spots: []
        //
        // Identical platform, opposite verdicts, and the difference was whether
        // the two organisations the canary happened to compare had any rows. In
        // the dormant run six of the seven comparable /api/v1 endpoints printed
        // `--  BLIND — 0/0 records, no ids…` and the run-level consequence was
        // NIL: outcome() returned `skipped`, and only `notProbed`/`unreached`
        // fed `blindSpots`. A structurally blind DETECTOR was invisible to the
        // exit code; only an unreachable ENDPOINT was not.
        //
        // The pin does not need to be reproduced here for the point to hold —
        // what is being pinned is that a run which could not tell two
        // organisations apart on most of the surface must not certify it. The
        // pinned fixture in it_fails_when_every_organisation_is_served_one_
        // organisations_rows() is the other half.
        $this->emptyEveryOrganisation();

        [$exit, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 500]);

        $this->assertSame(3, $exit, 'a run whose leak detector was blind on 6 of 7 endpoints exited clean');
        $this->assertSame('partial', $run['status']);

        // It is not a leak and must never read as one: this is the canary
        // reporting on itself.
        $this->assertSame([], $run['findings']);

        // Every endpoint was REACHED — which is exactly why reachability alone
        // could not see this. 8/8 on the old floor, and the run said `clean`.
        $this->assertSame(8, $run['coverage']['endpoints_reached']);
        $this->assertTrue($run['coverage']['graded_surface']['met']);
        $this->assertSame([], $run['blind_spots'],
            'the dormant run has no unreachable endpoint — if this fails the fixture, not the model, changed');

        $comparison = $run['coverage']['cross_tenant_comparison'];

        $this->assertFalse($comparison['met'], 'the comparison floor held with 1 of 7 endpoints compared');
        $this->assertSame(7, $comparison['comparable']);
        $this->assertSame(1, $comparison['compared']);

        // Named, not just counted — the same contract blind spots have.
        foreach ([
            'api/v1/announcements',
            'api/v1/services',
            'api/v1/pages',
            'api/v1/pages/menu',
            'api/v1/home',
            'api/v1/gallery',
        ] as $endpoint) {
            $this->assertArrayHasKey($endpoint, $comparison['blind'],
                "{$endpoint} stopped being a leak detector and the run did not say so");
        }

        // And the human report must not reuse the unreachable-endpoint wording:
        // these endpoints answered. An operator sent looking for a routing
        // problem that does not exist learns to ignore the whole line.
        // (The substrings must not overlap: the mocked console matches each
        // expectation against every write in declaration order, and the first
        // matching one absorbs the line.)
        $this->artisan('tenancy:canary', ['--only' => 'api/v1', '--delay' => 0, '--max-requests' => 500])
            ->expectsOutputToContain('had two answers to tell apart')
            ->expectsOutputToContain('not compared:')
            ->expectsOutputToContain('nothing in either organisation')
            ->assertExitCode(3);
    }

    #[Test]
    public function one_blind_endpoint_does_not_take_a_healthy_run_off_clean(): void
    {
        // The other side of the threshold, and the reason it is a threshold at
        // all rather than "any blind endpoint is amber".
        //
        // On the FIXED platform with two organisations that publish,
        // `api/v1/gallery` is still blind: neither organisation has uploaded a
        // photo, so both answers are empty, and no comparison can be made. In
        // production the same is true of any pair without a gallery — and of a
        // dozen /api/mobile endpoints for a pair with no events, funds or
        // notifications. Making that amber would put a permanent warning on a
        // correct platform for a condition no operator can act on, which is the
        // cry-wolf failure this whole file is a reaction to.
        //
        // So: clean, exit 0 — and the blind endpoint NAMED anyway, because the
        // floor decides the volume, never the visibility.
        [$exit, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 500]);

        $this->assertSame(0, $exit, 'one blind comparison on an otherwise healthy surface was not clean');
        $this->assertSame('clean', $run['status']);

        $comparison = $run['coverage']['cross_tenant_comparison'];

        $this->assertTrue($comparison['met']);
        $this->assertSame(7, $comparison['comparable']);
        $this->assertSame(6, $comparison['compared']);
        $this->assertSame(['api/v1/gallery'], array_keys($comparison['blind']),
            'the blind endpoint was not named on a clean run');

        // A green verdict that does not say what it could not see is the thing
        // this command exists to stop being.
        $this->artisan('tenancy:canary', ['--only' => 'api/v1', '--delay' => 0, '--max-requests' => 500])
            ->expectsOutputToContain('No cross-tenant leakage in what this run could see')
            ->expectsOutputToContain('Blind on 1 of 7 graded endpoint(s)')
            ->expectsOutputToContain('not compared: api/v1/gallery')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_comparison_floor_is_a_strict_majority(): void
    {
        // The threshold pinned at its own boundary, exactly as the coverage
        // floor is — because "one endpoint went quiet" and "this run is not
        // watching for a pin any more" have to part company SOMEWHERE, and an
        // untested boundary is a number somebody rounds next year.
        //
        // Six of the seven real comparable /api/v1 endpoints are compared here
        // (gallery is blind). Four blind fixtures make it 6 of 11 — a strict
        // majority by one — and five make it 6 of 12, which is a tie, and a tie
        // is a loss.
        foreach (range(1, 4) as $n) {
            $this->registerBlindComparisonEndpoint("api/v1/__canary_quiet{$n}/things");
        }

        [$heldExit, $held] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 900]);

        $this->assertSame(11, $held['coverage']['cross_tenant_comparison']['comparable']);
        $this->assertSame(6, $held['coverage']['cross_tenant_comparison']['compared']);
        $this->assertTrue($held['coverage']['cross_tenant_comparison']['met']);
        $this->assertSame('clean', $held['status']);
        $this->assertSame(0, $heldExit);

        $this->registerBlindComparisonEndpoint('api/v1/__canary_quiet5/things');

        [$lostExit, $lost] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 900]);

        $this->assertSame(12, $lost['coverage']['cross_tenant_comparison']['comparable']);
        $this->assertSame(6, $lost['coverage']['cross_tenant_comparison']['compared']);
        $this->assertFalse($lost['coverage']['cross_tenant_comparison']['met'],
            'a run that compared exactly half the surface it reached claimed a majority');
        $this->assertSame('partial', $lost['status']);
        $this->assertSame(3, $lostExit);

        // Blindness never blocks. Exit 2 means "go look at the canary and the
        // release", and every one of these endpoints answered 2xx to both
        // organisations; routing dormant data onto the page path is how the code
        // that also means "the origin is gone" gets ignored.
        $this->assertNotSame(2, $lostExit);
        $this->assertSame([], $lost['errors']);
    }

    #[Test]
    public function an_endpoint_only_one_organisation_can_answer_is_not_compared(): void
    {
        // The gap that was invisible from every angle the report offered:
        // reachability needs only ONE valid-tenant 2xx, so an endpoint that
        // answers masjid 1 and 404s masjid 2 counts as reached, is absent from
        // `blind_spots`, and its comparison is dead. Every surface said it was
        // watched.
        $first = $this->masjidA->id;

        Route::get('api/v1/__canary_one_sided/announcements', function () use ($first) {
            $tenant = (int) request()->header('masjid-id');

            if ($tenant <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            if ($tenant !== $first) {
                return response()->api(404, 'Not found.', null);
            }

            return response()->api(200, 'ok', [
                'items' => [['id' => 1, 'title' => 'only mine']],
                'pagination' => ['current_page' => 1, 'total' => 1],
            ]);
        });

        [$exit, $run] = $this->runCanary(['--only' => '__canary_one_sided']);

        $this->assertSame(1, $run['coverage']['endpoints_reached'], 'the endpoint was not reached at all');
        $this->assertSame([], $run['blind_spots'], 'a reached endpoint was reported as never seen');

        $comparison = $run['coverage']['cross_tenant_comparison'];

        $this->assertArrayHasKey('api/v1/__canary_one_sided/announcements', $comparison['blind']);
        $this->assertStringContainsString('got 1',
            $comparison['blind']['api/v1/__canary_one_sided/announcements']);
        $this->assertSame(3, $exit);
        $this->assertSame('partial', $run['status']);
    }

    #[Test]
    public function the_ungraded_mobile_surface_does_not_decide_the_comparison_floor(): void
    {
        // The same argument as the_rotating_mobile_surface_is_not_graded, for
        // the second floor, and it bites harder here: on a healthy platform
        // MOST of /api/mobile is blind for a pair of organisations with no
        // events, funds, notifications or app config. Grading it would make a
        // correct platform amber on every run, and the surface probed is a slice
        // chosen by the clock — so the verdict would also change at 04:00 for a
        // platform that did not.
        [$exit, $run] = $this->runCanary(['--all' => true, '--max-requests' => 900]);

        $this->assertSame(0, $exit, 'a healthy --all run stopped being clean');
        $this->assertSame('clean', $run['status']);
        $this->assertSame(31, $run['coverage']['endpoints_reached']);

        $comparison = $run['coverage']['cross_tenant_comparison'];

        $this->assertSame('api/v1', $comparison['surface']);
        $this->assertTrue($comparison['met']);
        $this->assertGreaterThan(5, $comparison['blind_outside_graded_surface'],
            'the mobile surface stopped being blind, so this test no longer proves anything');
        $this->assertSame(['api/v1/gallery'], array_keys($comparison['blind']),
            'a mobile endpoint was counted against the graded comparison floor');
    }

    #[Test]
    public function the_canary_compares_the_organisations_that_hold_content(): void
    {
        // `resolveTenants()` used to take the two LOWEST masjid ids, which are
        // the two OLDEST organisations — not the two that publish. Two dormant
        // organisations return empty answers to everything, and two empty
        // answers are byte-identical whether the platform is scoped correctly or
        // pinned to one organisation, so the detector that catches a pin was
        // being pointed at the pair least able to see one. `/api/v1/announcements`
        // returns zero rows the moment an org's announcements expire.
        //
        // The pair is now chosen by CONTENT, and both halves are asserted here
        // because the claim is a comparison: the same endpoint is WATCHED with
        // the chosen pair and BLIND with the two oldest.
        $this->emptyEveryOrganisation();

        $active = $this->makeMasjid();
        $alsoActive = $this->makeMasjid();

        foreach (['first', 'second'] as $title) {
            $this->makeAnnouncement($active, $title);
            $this->makeService($active, $title);
        }

        $this->makePage($active, 'active-page');
        $this->makeAnnouncement($alsoActive, 'other');
        $this->makeService($alsoActive, 'other');
        $this->makePage($alsoActive, 'other-page');

        [$exit, $run] = $this->runCanary(['--only' => 'api/v1/announcements', '--max-requests' => 500]);

        $this->assertSame([$active->id, $alsoActive->id], $run['tenants'],
            'the canary compared the oldest organisations rather than the ones holding rows');

        $check = collect($run['checks'])->firstWhere('check', 'tenants-get-different-answers');

        $this->assertSame('pass', $check['outcome'],
            'the comparison was blind against two organisations that both hold announcements');
        $this->assertStringContainsString('no shared record ids', $check['detail']);
        $this->assertSame(0, $exit);

        // ...and the pair the old code would have picked, which is the whole
        // point: same platform, same endpoint, and the detector is asleep.
        [$oldExit, $old] = $this->runCanary([
            '--only' => 'api/v1/announcements',
            '--tenants' => $this->masjidA->id.','.$this->masjidB->id,
            '--max-requests' => 500,
        ]);

        $this->assertSame([$this->masjidA->id, $this->masjidB->id], $old['tenants']);
        $this->assertArrayHasKey('api/v1/announcements',
            $old['coverage']['cross_tenant_comparison']['blind']);
        $this->assertSame(3, $oldExit, 'the dormant pair still certified the endpoint');
    }

    #[Test]
    public function a_single_organisation_platform_is_not_reported_as_blind(): void
    {
        // Over-refusal, from the other end. On a platform with ONE organisation
        // there is no second organisation to leak to and no pair to compare; a
        // canary that reported every endpoint as a gap it could not close would
        // be amber forever on a platform with nothing wrong with it.
        //
        // So this is `n/a`, said once in the check detail and kept out of the
        // floor's denominator in both directions — not counted as compared,
        // which would be a lie, and not counted as blind, which would be an
        // alarm nobody can action.
        $this->masjidB->delete();

        [$exit, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 500]);

        $this->assertSame([$this->masjidA->id], $run['tenants']);
        $this->assertSame(0, $exit);
        $this->assertSame('clean', $run['status']);

        $comparison = $run['coverage']['cross_tenant_comparison'];

        $this->assertSame(0, $comparison['comparable']);
        $this->assertSame([], $comparison['blind']);
        $this->assertStringContainsString('n/a', $comparison['floor']);

        $check = collect($run['checks'])
            ->where('check', 'tenants-get-different-answers')
            ->firstWhere('endpoint', 'api/v1/announcements');

        $this->assertSame('skipped', $check['outcome']);
        $this->assertStringContainsString('n/a', $check['detail']);

        // The run still says out loud that it cannot prove a leak by comparison.
        $this->assertNotSame([], $run['notes']);
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
    // ...but "I could not see one endpoint" is not "I saw nothing"
    // ================================================================

    #[Test]
    public function one_unreachable_endpoint_is_not_the_same_alarm_as_a_dead_origin(): void
    {
        // THE MEASURED CASE, 2026-08-12. Eight healthy /api/v1 endpoints plus one
        // that answers 404 to a probe naming a REAL organisation — the ordinary
        // shape of an optional per-org record that this org has not configured:
        //
        //     exit=2  status=incomplete  reached=8/9  pass=29  skipped=15  FAIL=0
        //
        // and the dead-origin runs, which reached 0/8 and passed 0 checks:
        //
        //     exit=2  status=incomplete  reached=0/8  pass=0   skipped=39  FAIL=0
        //
        // Identical status, identical exit code. assessCoverage() set `incomplete`
        // for EVERY entry in $unreached with no threshold, so "masjid 2 has no
        // donation link" and "CANARY_BASE_URL points at nothing" were one alarm.
        // The first happens on an ordinary Tuesday; an alarm that fires on an
        // ordinary Tuesday gets silenced, and then the second one is unheard.
        //
        // Both runs happen HERE, in one test, because the whole claim is a
        // comparison and asserting the two halves in two files is how they drift
        // back into agreement.
        $this->registerOptionalRecord404('api/v1/__canary_optional/donation-link');

        [$partialExit, $partial] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 500]);

        // ...and the same command against an origin that serves the API not at
        // all. In the SAME PROCESS on purpose: the whole claim is a comparison,
        // and asserting the two halves in two test files is how they drift back
        // into agreement without anyone noticing. (It is also what required
        // handle() to reset its own accumulators — before that, a second run in
        // one process inherited the first run's checks and findings.)
        $base = $this->startLoopbackOrigin(301);

        [$deadExit, $dead] = $this->runCanary([
            '--only' => 'api/v1',
            '--transport' => 'http',
            '--base-url' => $base,
            '--max-requests' => 500,
        ]);

        // The point of the whole exercise, asserted before any of the detail.
        $this->assertNotSame($partial['status'], $dead['status'],
            'one missing optional record still reports the same status as an origin that serves nothing');
        $this->assertNotSame($partialExit, $deadExit,
            'one missing optional record still exits with the same code as an origin that serves nothing');

        $this->assertSame('partial', $partial['status']);
        $this->assertSame(3, $partialExit);

        $this->assertSame('incomplete', $dead['status']);
        $this->assertSame(2, $deadExit);

        // Non-zero either way: an endpoint that stops being reachable stops
        // being watched, and at exit 0 that erosion is invisible forever.
        $this->assertNotSame(0, $partialExit, 'a run with an endpoint it could not see exited clean');

        // The measured coverage behind each verdict — 8/9 with 29 passes on one
        // side, 0/8 with 0 passes on the other. These are the two runs the old
        // model printed the same word for.
        $this->assertSame(8, $partial['coverage']['endpoints_reached']);
        $this->assertSame(9, $partial['coverage']['endpoints_planned']);
        $this->assertGreaterThan(20, $partial['coverage']['checks']['pass']);
        $this->assertSame(0, $partial['coverage']['checks']['FAIL']);
        $this->assertSame([], $partial['findings']);

        $this->assertSame(0, $dead['coverage']['endpoints_reached']);
        $this->assertSame(0, $dead['coverage']['checks']['pass'],
            'a second run in one process inherited the first run\'s passing checks');
    }

    #[Test]
    public function a_partial_run_names_the_endpoint_it_could_not_see(): void
    {
        // The quieter outcome is only defensible because it is not a quieter
        // SILENCE. Every gap is named at every verdict level; the floor decides
        // the volume, never the visibility.
        $this->registerOptionalRecord404('api/v1/__canary_optional/donation-link');

        [, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 500]);

        $this->assertSame('partial', $run['status']);
        $this->assertArrayHasKey('api/v1/__canary_optional/donation-link', $run['blind_spots']);
        $this->assertStringContainsString('404',
            $run['blind_spots']['api/v1/__canary_optional/donation-link']);
        $this->assertArrayHasKey('api/v1/__canary_optional/donation-link',
            $run['coverage']['endpoints_not_reached']);

        // And it must not be an ERROR, which is what made it indistinguishable
        // from the run that could not start.
        $this->assertSame([], $run['errors']);

        // The human report is what an operator reads at 3am. It must say what
        // was and was not covered, and must NOT reuse the dead-origin sentence.
        $this->artisan('tenancy:canary', ['--only' => 'api/v1', '--delay' => 0, '--max-requests' => 500])
            ->expectsOutputToContain('PARTIAL COVERAGE')
            ->expectsOutputToContain('not seen:')
            ->expectsOutputToContain('api/v1/__canary_optional/donation-link')
            ->assertExitCode(3);
    }

    #[Test]
    public function losing_the_majority_of_the_graded_surface_is_incomplete_not_partial(): void
    {
        // The threshold itself, pinned at its own boundary — because "a few
        // endpoints unreachable" and "most of the platform unreachable" have to
        // part company SOMEWHERE, and an untested boundary is a number somebody
        // will round next year.
        //
        // Eight real /api/v1 endpoints are reachable. Add eight unreachable ones
        // and exactly half the graded surface is reached: no majority either
        // way, and a tie must not be resolved in favour of the reassuring
        // reading. Add a ninth and the run is describing less of the platform
        // than it missed.
        foreach (range(1, 8) as $n) {
            $this->registerOptionalRecord404("api/v1/__canary_gone{$n}/thing");
        }

        [$tieExit, $tie] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 900]);

        $this->assertSame(8, $tie['coverage']['graded_surface']['reached']);
        $this->assertSame(16, $tie['coverage']['graded_surface']['planned']);
        $this->assertFalse($tie['coverage']['graded_surface']['met']);
        $this->assertSame('incomplete', $tie['status'],
            'a run that reached exactly half its graded surface claimed a majority');
        $this->assertSame(2, $tieExit);
        $this->assertStringContainsString('COVERAGE FLOOR', implode(' ', $tie['errors']));

        // Still not a leak, and it must not read as one — this is the canary
        // reporting on itself.
        $this->assertSame([], $tie['findings']);
    }

    #[Test]
    public function a_strict_majority_of_the_graded_surface_is_partial(): void
    {
        // The other side of the same boundary: seven unreachable against eight
        // reachable is 8/15 — a strict majority by one — and must be the QUIET
        // outcome, not the loud one. This is the assertion that stops a future
        // reader "simplifying" `* 2 <` into `* 2 <=`, or `reached < planned`.
        foreach (range(1, 7) as $n) {
            $this->registerOptionalRecord404("api/v1/__canary_gone{$n}/thing");
        }

        [$exit, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 900]);

        $this->assertSame(8, $run['coverage']['graded_surface']['reached']);
        $this->assertSame(15, $run['coverage']['graded_surface']['planned']);
        $this->assertTrue($run['coverage']['graded_surface']['met']);
        $this->assertSame('partial', $run['status']);
        $this->assertSame(3, $exit);

        // All seven named, not just counted.
        $this->assertCount(7, $run['blind_spots']);
    }

    #[Test]
    public function the_rotating_mobile_surface_is_not_graded(): void
    {
        // WHY the floor is computed on /api/v1 and not on the whole plan.
        //
        // /api/mobile is probed as a slice chosen from the CLOCK, and much of it
        // reads an optional per-org singleton (donation-link, about, app-config,
        // splash, signage, tv-config) where a 404 for a valid tenant is the
        // correct answer for an org that has not configured it. A floor over the
        // whole plan would therefore answer differently at 03:00 than at 04:00
        // for a platform that did not change, and would treat "this org has no
        // TV config" as evidence the canary is broken.
        //
        // So a mobile endpoint the run could not see is named and degrades the
        // run — but it is not in the denominator the verdict is computed from.
        $this->registerOptionalRecord404('api/mobile/__canary_optional/tv-config');

        [$exit, $run] = $this->runCanary(['--all' => true, '--max-requests' => 900]);

        $this->assertSame('partial', $run['status']);
        $this->assertSame(3, $exit);

        $this->assertArrayHasKey('api/mobile/__canary_optional/tv-config', $run['blind_spots'],
            'an unreachable mobile endpoint went unnamed');

        $graded = $run['coverage']['graded_surface'];

        $this->assertStringContainsString('api/v1', $graded['surface']);
        $this->assertSame($graded['planned'], $graded['reached'],
            'the unreachable mobile endpoint was counted against the graded surface');
        $this->assertLessThan($run['coverage']['endpoints_planned'], $graded['planned'],
            'the graded surface is the whole plan, so the verdict depends on which mobile slice the clock picked');
    }

    #[Test]
    public function a_leak_outranks_a_partial_run(): void
    {
        // Precedence, and the property the whole redesign must not break: a leak
        // the canary REACHED is exit 1 no matter how blind the rest of the run
        // was. Downgrading a finding because coverage was imperfect is the one
        // mistake worse than the one being fixed.
        $this->registerPinnedTenantFixture();
        $this->registerOptionalRecord404('api/v1/__canary_optional/donation-link');

        [$exit, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 900]);

        $this->assertSame(1, $exit, 'a leak on a partially blind run did not exit 1');
        $this->assertSame('leak', $run['status']);
        $this->assertNotEmpty($run['blind_spots'], 'the blind spot stopped being reported once there was a finding');
        $this->assertNotNull(collect($run['findings'])->firstWhere('kind', 'same_rows_served_to_two_tenants'));
    }

    #[Test]
    public function a_partial_run_logs_quieter_than_a_blocked_one(): void
    {
        // For a SCHEDULED run this is the alert path that exists today:
        // schedule:run discards stdout, so the log line is the only evidence,
        // and its level is something an alerting rule can already route on
        // without anyone touching the schedule. `error` for a missing optional
        // record is the same cry-wolf one layer down.
        $channel = \Mockery::mock();
        $channel->shouldIgnoreMissing();

        Log::shouldReceive('channel')->andReturn($channel);

        $channel->shouldReceive('warning')->once()
            ->with('tenancy:canary partial', \Mockery::type('array'));
        $channel->shouldReceive('error')->never();
        $channel->shouldReceive('info')->never();

        $this->registerOptionalRecord404('api/v1/__canary_optional/donation-link');

        [$exit] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 500]);

        $this->assertSame(3, $exit);
    }

    #[Test]
    public function a_partial_run_names_which_axis_degraded_it(): void
    {
        // THE MEASURED CASE, 2026-08-13. Two runs against the same platform:
        //
        //     one unreachable UNGRADED /api/mobile endpoint         partial, exit 3, warning
        //     every org dormant, comparison blind on 6 of 7 graded  partial, exit 3, warning
        //
        // routes/console.php states the contract as "an alerting rule can route
        // on [the level] without anyone editing this file". Both of those route
        // IDENTICALLY, and a `partial` run deliberately writes nothing to
        // `errors` — that is what keeps it from reading like a blocked run, and
        // a_partial_run_names_the_endpoint_it_could_not_see pins it — so the
        // only discriminator left was re-deriving the coverage arithmetic.
        //
        // Both remain exit 3 and both remain `warning`: the contract says exit 3
        // covers "an endpoint it never reached, OR one it reached and could not
        // compare", and both of these are tickets. What changes is that they are
        // now separable by a field.
        //
        // Both halves in ONE test, because the whole claim is a comparison.
        $this->registerOptionalRecord404('api/mobile/__canary_optional/tv-config');

        [$unreachedExit, $unreached] = $this->runCanary(['--all' => true, '--max-requests' => 900]);

        $this->assertSame(3, $unreachedExit);
        $this->assertSame(['unreached_endpoints'], $unreached['degraded_by']);

        $this->emptyEveryOrganisation();

        [$dormantExit, $dormant] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 900]);

        $this->assertSame(3, $dormantExit);
        $this->assertSame(['comparison_floor', 'row_ownership_dark'], $dormant['degraded_by']);

        // Same status, same exit code, same log level — and no longer the same
        // alert.
        $this->assertSame($unreached['status'], $dormant['status']);
        $this->assertSame($unreachedExit, $dormantExit);
        $this->assertNotSame($unreached['degraded_by'], $dormant['degraded_by'],
            'the two halves of exit 3 still cannot be routed apart without parsing the coverage block');

        // Still not errors. That distinction is what separates exit 3 from
        // exit 2 and it must not be borrowed for this.
        $this->assertSame([], $unreached['errors']);
        $this->assertSame([], $dormant['errors']);
    }

    #[Test]
    public function a_clean_run_reports_no_reason_to_be_degraded(): void
    {
        // The field has to be empty when there is nothing to route, or an alert
        // rule written against "degraded_by is present" fires on every run.
        [$exit, $run] = $this->runCanary(['--only' => 'api/v1', '--max-requests' => 900]);

        $this->assertSame(0, $exit);
        $this->assertSame([], $run['degraded_by']);
    }

    #[Test]
    public function a_truncation_that_costs_the_whole_run_is_still_incomplete(): void
    {
        // The budget doctrine survives the new state: a run cut off after three
        // probes reached 1 of 31 endpoints, which is nowhere near a majority of
        // /api/v1, so it stays the LOUD outcome. Only a truncation that still
        // left the graded surface covered gets the quiet one.
        [$exit, $run] = $this->runCanary(['--all' => true, '--max-requests' => 3]);

        $this->assertSame(2, $exit);
        $this->assertSame('incomplete', $run['status']);
        $this->assertFalse($run['coverage']['graded_surface']['met']);
        $this->assertStringContainsString('COVERAGE FLOOR', implode(' ', $run['errors']));

        // ...and a truncation that only cost the tail of the mobile slice is
        // `partial`: every /api/v1 endpoint was reached, the truncation is still
        // reported, and the run is still non-zero.
        [$tailExit, $tail] = $this->runCanary(['--all' => true, '--max-requests' => 60]);

        $this->assertSame(3, $tailExit);
        $this->assertSame('partial', $tail['status']);
        $this->assertTrue($tail['coverage']['graded_surface']['met']);
        $this->assertNotEmpty($tail['errors'], 'the truncation itself stopped being reported');
        $this->assertNotEmpty($tail['coverage']['endpoints_not_probed']);
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
     * The SWAP: the header is validated, and then each organisation is served
     * the OTHER one's rows.
     *
     * Not a contrived shape — it is one `where('masjid_id', $other)` away from
     * correct, which is what a mis-plumbed resolver, a copied query that kept a
     * stale variable, or a join on the wrong side produces. Through the real V1
     * Resource, so the response strips `masjid_id` exactly as production does;
     * without that the body scan would catch it and the fixture would prove
     * nothing about the detector under test.
     *
     * What makes it the important case: every negative detector is satisfied.
     * The two answers differ (a swap makes them differ perfectly), the
     * tenant-less request is still refused, no 5xx, no `masjid_id` to be
     * foreign. Only a POSITIVE assertion — these are supposed to be masjid A's
     * rows, are they? — sees it.
     */
    private function registerSwappedTenantFixture(): void
    {
        Route::get('api/v1/__canary_swap/announcements', function () {
            $tenant = (int) request()->header('masjid-id');

            if ($tenant <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            $other = Masjid::query()->where('id', '!=', $tenant)->orderBy('id')->value('id');
            $rows = Announcement::query()->where('masjid_id', $other)->get();

            return response()->api(200, 'ok', [
                'items' => AnnouncementResource::collection($rows),
                'pagination' => ['current_page' => 1, 'total' => $rows->count()],
            ]);
        });
    }

    /**
     * The HOME FEED SHAPE: sibling lists under one key, one correctly scoped and
     * named after its table, the other a TOTAL SWAP under a key no relation is
     * named by.
     *
     * `featured`, `latest`, `news`, `upcoming` — the ordinary vocabulary of a
     * home feed, and `/api/v1/home` already carries three buckets (`main`,
     * `about_us`, `contact_us`) that no relation is named by. The URI names
     * nothing either, so `featured` has no anchor from any direction: its ids
     * are simply unreadable to this canary, and the question is only whether the
     * run SAYS so or absorbs it into the sibling's pass.
     *
     * Through the real V1 Resource, so `masjid_id` is stripped exactly as
     * production strips it and the body scan stays blind — without that the
     * fixture would be caught by a detector that is not the one under test.
     */
    private function registerLeakingSiblingBucketFixture(): void
    {
        Route::get('api/v1/__canary_feed/home', function () {
            $tenant = (int) request()->header('masjid-id');

            if ($tenant <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            $other = Masjid::query()->where('id', '!=', $tenant)->orderBy('id')->value('id');

            return response()->api(200, 'ok', [
                // Correctly scoped, and named after its table.
                'announcements' => AnnouncementResource::collection(
                    Announcement::query()->where('masjid_id', $tenant)->get()
                ),
                // A total swap, under a key no relation is named by.
                'featured' => AnnouncementResource::collection(
                    Announcement::query()->where('masjid_id', $other)->get()
                ),
            ]);
        });
    }

    /**
     * A PERFECTLY SCOPED collection that also returns a global lookup list.
     *
     * `categories`, `tags`, `types` — ids 1, 2, 3, the same three for every
     * organisation because nobody owns them. The hazard is that those ids also
     * exist in `services` (this fixture's setUp gives A services 1 and 2 and B
     * service 3), and the endpoint's URI names `services`, so before this fix
     * every bucket inherited that anchor and the lookup list read as two
     * organisations' service rows in one answer.
     *
     * `swapped: true` makes the WATCHED bucket leak while the lookup list stays
     * identical, so the fix can be shown to narrow the anchor without turning
     * the endpoint off.
     */
    private function registerGlobalLookupSiblingFixture(bool $swapped = false): void
    {
        $uri = $swapped ? 'api/v1/__canary_lookup_swap/services' : 'api/v1/__canary_lookup/services';

        Route::get($uri, function () use ($swapped) {
            $tenant = (int) request()->header('masjid-id');

            if ($tenant <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            $whose = $swapped
                ? Masjid::query()->where('id', '!=', $tenant)->orderBy('id')->value('id')
                : $tenant;

            return response()->api(200, 'ok', [
                'items' => ServiceResource::collection(
                    Service::query()->where('masjid_id', $whose)->get()
                ),
                // Owned by nobody, identical for everybody — and colliding with
                // `services.id` from the first row onwards.
                'categories' => [
                    ['id' => 1, 'name' => 'Education'],
                    ['id' => 2, 'name' => 'Community'],
                    ['id' => 3, 'name' => 'Charity'],
                ],
            ]);
        });
    }

    /**
     * A correctly scoped endpoint whose rows come from a table no `compare_by`
     * relation covers — the ordinary shape of a new vertical, and of
     * `/api/v1/settings` and `/api/mobile/…/signage` today.
     *
     * Both organisations get real, different, non-empty answers, so the
     * comparison is NOT blind: this is specifically an endpoint the run can
     * compare and cannot attribute, which is the only thing that makes it a
     * test of the ownership floor rather than of the comparison floor.
     */
    private function registerUnattributableEndpoint(string $uri): void
    {
        Route::get($uri, function () {
            $tenant = (int) request()->header('masjid-id');

            if ($tenant <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            return response()->api(200, 'ok', [
                'items' => [['id' => 900 + $tenant, 'title' => 'row for '.$tenant]],
                'pagination' => ['current_page' => 1, 'total' => 1],
            ]);
        });
    }

    /**
     * A CORRECTLY SCOPED endpoint that has no rows for anybody.
     *
     * It refuses a tenant-less request with the contract 400 and answers a valid
     * organisation with an empty collection — so every structural check passes
     * and only the cross-tenant comparison is blind. That combination is the
     * whole point: this is what a healthy endpoint on a vertical with no data
     * looks like, and it is also what a PINNED endpoint looks like when the
     * organisation it is pinned to is dormant. The canary cannot tell them
     * apart, which is exactly why it must stop claiming it can.
     */
    private function registerBlindComparisonEndpoint(string $uri): void
    {
        Route::get($uri, function () {
            $tenant = (int) request()->header('masjid-id');

            if ($tenant <= 0) {
                return response()->api(400, 'A masjid must be specified.', null);
            }

            return response()->api(200, 'ok', [
                'items' => [],
                'pagination' => ['current_page' => 1, 'total' => 0],
            ]);
        });
    }

    /**
     * Take every organisation's public content away — the dormant platform.
     *
     * Not a contrivance: `/api/v1/announcements` empties itself the moment an
     * organisation's announcements pass their `end_date`, and an organisation
     * that has never uploaded a photo has an empty gallery permanently.
     */
    private function emptyEveryOrganisation(): void
    {
        Announcement::query()->delete();
        Service::query()->delete();
        Page::query()->delete();
    }

    /**
     * An endpoint that answers 404 to a probe naming a REAL organisation.
     *
     * This is not a broken endpoint — it is the ordinary shape of an OPTIONAL
     * per-organisation record. `/api/mobile/masjids/{id}/donation-link`,
     * `…/about`, `…/tv-config`, `…/splash` and `…/signage` all read a singleton
     * row that an org may simply not have configured, and `findOrFail` on a
     * missing one is a correct 404. The canary cannot see past it, so the
     * endpoint is unverified — which is a fact to report, not an emergency.
     */
    private function registerOptionalRecord404(string $uri): void
    {
        Route::get($uri, fn () => response()->api(404, 'Not found.', null));
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
