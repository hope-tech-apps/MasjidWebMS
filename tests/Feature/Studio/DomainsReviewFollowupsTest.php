<?php

namespace Tests\Feature\Studio;

use App\Exceptions\DomainsStillAttached;
use App\Models\MasjidDomain;
use App\Models\MasjidDomainChange;
use App\Models\User;
use App\Services\Domains\DetachResult;
use App\Services\Domains\DomainAttacher;
use App\Services\Domains\DomainDetacher;
use App\Services\Domains\DomainProbe;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesDetachableDomains;
use Tests\Feature\Studio\Concerns\MakesRedirectDomains;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * The follow-ups from point's security review of W2 S1–S6 (2026-09-28), one
 * test or more each, numbered as the review numbered them.
 */
class DomainsReviewFollowupsTest extends TestCase
{
    use FakesCloudflare;
    use MakesDetachableDomains;
    use MakesRedirectDomains;
    use MakesStudioDomains;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolveTo(['93.184.216.34']);
        Sleep::fake();
    }

    // 1 ------------------------------------------------------------------

    #[Test]
    public function redirect_zones_come_from_the_environment_never_the_public_file(): void
    {
        $read = function (?string $value): array {
            $key = 'CLOUDFLARE_REDIRECT_ZONES';

            try {
                if ($value === null) {
                    unset($_SERVER[$key], $_ENV[$key]);
                } else {
                    $_SERVER[$key] = $_ENV[$key] = $value;
                }

                return (require base_path('config/cloudflare.php'))['redirect_zones'];
            } finally {
                unset($_SERVER[$key], $_ENV[$key]);
            }
        };

        $this->assertSame([], $read(null));
        $this->assertSame([], $read(''), 'blank, as the staging deny-list leaves it');
        $this->assertSame(['test-zone.example', 'other.example'], $read(' Test-Zone.example , other.example,,other.example '));
        $this->assertSame(1, preg_match('/^CLOUDFLARE_REDIRECT_ZONES=$/m', (string) file_get_contents(base_path('.env.example'))));
    }

    // 2 ------------------------------------------------------------------

    #[Test]
    public function a_serving_host_an_alias_redirects_to_is_neither_detached_nor_deleted_first(): void
    {
        $this->withStudioToken();
        $org = $this->makeOrg();
        $www = $this->canonicalRow($org);
        $apex = $this->redirectRow($org, $www, MasjidDomain::STATUS_MANUAL, [
            'verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(),
            'cf_zone_id' => self::PAIR_ZONE, 'cf_redirect_rule_id' => 'rule-studio',
        ]);
        $this->fakeCloudflare([]);

        $refused = $this->app->make(DomainDetacher::class)->detach($www);

        $this->assertSame(DetachResult::REFUSED, $refused->outcome);
        $this->assertStringContainsString('Detach ' . self::APEX . ' first', (string) $refused->error);
        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $www->fresh()->status, 'still served');
        $this->assertSame([], $this->sent());
        $this->assertFalse($www->fresh()->deletableThroughStudio());
        $this->assertStringContainsString('Detach ' . self::APEX . ' first', implode(' ', $www->fresh()->detachPlan()['manual_steps']));
        $this->assertStringContainsString('Detach ' . self::APEX . ' first', implode(' ', $www->fresh()->removalSteps()));

        // domains:release takes the alias first, so the pair goes in one run.
        $org->delete();
        $this->fakeCloudflare([
            'GET ' . self::ENTRYPOINT => $this->cfOk($this->ruleset([$this->studioRule($apex)])),
            'DELETE /zones/zone-pair/rulesets/rs-1/rules/rule-studio' => $this->cfOk($this->ruleset([])),
            'GET /accounts/*/pages/projects/manara-renderer/domains/' . self::WWW => $this->cfOk($this->pagesDomainBody(self::WWW, 'active')),
            'DELETE /accounts/*/pages/projects/manara-renderer/domains/' . self::WWW => $this->cfOk([]),
            'GET /zones/zone-pair/dns_records/rec-www' => $this->cfOk($this->dnsRecord(self::WWW, 'CNAME', 'manara-renderer.pages.dev', 'rec-www')),
            'DELETE /zones/zone-pair/dns_records/rec-www' => $this->cfOk(['id' => 'rec-www']),
        ]);
        $this->assertSame(0, Artisan::call('domains:release', [
            'masjid_id' => $org->id, '--execute' => true, '--operator' => 'owner', '--reason' => 'left', '--json' => true,
        ]));
        $this->assertSame(0, MasjidDomain::query()->where('masjid_id', $org->id)->count());
    }

    // 3 ------------------------------------------------------------------

    #[Test]
    public function a_zone_studio_created_for_another_organisation_is_not_allowed(): void
    {
        config(['cloudflare.redirect_zones' => []]);
        $theirs = $this->makeOrg();
        $this->canonicalRow($theirs, ['cf_zone_created' => true]);

        $mine = $this->makeOrg();
        $row = $this->makeDomain($mine, 'shop.pair-masjid.org', MasjidDomain::STATUS_PENDING, ['zone_apex' => self::APEX]);

        $this->assertFalse($row->redirectZoneAllowed());
    }

    // 4 ------------------------------------------------------------------

    #[Test]
    public function a_collapse_whose_undo_failed_is_parked_and_reconcile_takes_the_rule_out(): void
    {
        $this->withStudioToken();
        config(['cloudflare.redirect_zones' => [self::APEX]]);
        $org = $this->makeOrg();
        $www = $this->canonicalRow($org);
        $apex = $this->canonicalRow($org, ['host' => self::APEX, 'cf_dns_record_id' => 'rec-apex', 'cf_pages_domain_id' => 'pd-' . md5(self::APEX)]);
        $this->fakeCloudflare([
            'GET ' . self::ENTRYPOINT => Http::sequence()
                ->push(['success' => true, 'errors' => [], 'result' => $this->ruleset([])])
                ->push(['success' => true, 'errors' => [], 'result' => $this->ruleset([$this->studioRule($apex)])]),
            'POST /zones/zone-pair/rulesets/rs-1/rules' => $this->cfOk($this->ruleset([$this->studioRule($apex)])),
            'DELETE /zones/zone-pair/rulesets/rs-1/rules/rule-studio' => $this->cfError(503, 0, 'Service unavailable'),
        ] + $this->apexAnswers(200, ''));
        Log::spy();

        $this->assertSame(1, Artisan::call('domains:collapse-alias', [
            'domain_id' => $apex->id, '--execute' => true, '--operator' => 'owner', '--reason' => 'test',
        ]));
        $apex->refresh();

        $this->assertSame(MasjidDomain::ROLE_SERVING, $apex->role);
        $this->assertSame('rule-studio', $apex->cf_redirect_rule_id, 'still tracked, so it can be taken out');
        $this->assertSame('rule_cleanup', $apex->waiting_on);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'could not be taken out'))->once();

        $this->travel(31)->minutes();
        $this->fakeCloudflare([
            'GET ' . self::ENTRYPOINT => $this->cfOk($this->ruleset([$this->studioRule($apex)])),
            'DELETE /zones/zone-pair/rulesets/rs-1/rules/rule-studio' => $this->cfOk($this->ruleset([])),
            'GET https://' . self::APEX . '/api/tenant' => Http::response('{}', 200, ['x-manara-tenant' => (string) $org->id]),
            'GET https://' . self::WWW . '/api/tenant' => Http::response('{}', 200, ['x-manara-tenant' => (string) $org->id]),
        ]);
        $this->artisan('domains:reconcile')->assertExitCode(0);
        $apex->refresh();

        $this->assertNull($apex->cf_redirect_rule_id);
        $this->assertNull($apex->waiting_on);
        $this->assertNotNull($www->fresh());
    }

    // 5 ------------------------------------------------------------------

    #[Test]
    public function ipv6_forms_that_carry_an_ipv4_address_are_judged_by_that_address(): void
    {
        foreach ([
            '::ffff:10.0.0.1', '::ffff:a00:1', '::10.0.0.1', '::7f00:1',
            '64:ff9b::a9fe:a9fe', '64:ff9b::192.168.1.1',
            '2002:c0a8:101::1', '2002:7f00:1::',
        ] as $private) {
            $this->assertFalse(DomainProbe::isPublicAddress($private), "{$private} carries a private or reserved IPv4 address");
        }

        foreach (['::ffff:93.184.216.34', '64:ff9b::5db8:d822', '2002:5db8:d822::1', '2606:4700:4700::1111'] as $public) {
            $this->assertTrue(DomainProbe::isPublicAddress($public), "{$public} is public");
        }

        $this->assertFalse(DomainProbe::isPublicAddress('::'));
        $this->assertFalse(DomainProbe::isPublicAddress('::1'));
    }

    // 6 ------------------------------------------------------------------

    #[Test]
    public function the_probe_takes_no_more_than_a_small_body(): void
    {
        $org = $this->makeOrg();
        $row = $this->makeDomain($org, 'www.cap-test.org');
        $seen = [];
        Http::fake(function ($request, $options) use (&$seen, $org) {
            $seen[] = $options['curl'] ?? [];

            return Http::response('{}', 200, ['x-manara-tenant' => (string) $org->id]);
        });

        $this->assertTrue($this->app->make(DomainProbe::class)->probe($row)['matched']);

        $curl = $seen[0];
        $this->assertSame(DomainProbe::MAX_BODY_BYTES, $curl[CURLOPT_MAXFILESIZE]);
        $this->assertFalse($curl[CURLOPT_NOPROGRESS]);
        $this->assertSame(1, ($curl[CURLOPT_XFERINFOFUNCTION])(null, 0, DomainProbe::MAX_BODY_BYTES + 1), 'aborts past the cap');
        $this->assertSame(0, ($curl[CURLOPT_XFERINFOFUNCTION])(null, 0, 512));
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $curl, 'still pinned to the checked address');
        $this->assertLessThanOrEqual(65536, DomainProbe::MAX_BODY_BYTES);
    }

    // 7 ------------------------------------------------------------------

    #[Test]
    public function a_detach_that_keeps_failing_warns_once_per_state_and_counts_as_waiting(): void
    {
        $this->withStudioToken();
        $row = $this->attachedRow($this->makeOrg());
        $this->fakeCloudflare(['GET ' . $this->pagesPath() => $this->cfError(503, 0, 'Service unavailable')]);
        Log::spy();

        $detacher = $this->app->make(DomainDetacher::class);
        $detacher->detach($row);
        foreach (range(1, 3) as $retry) {
            $this->travel(6)->minutes();
            $detacher->detach($row->fresh());
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'could not finish detaching'))->once();

        // A new way of failing is a new warning.
        $this->fakeCloudflare(['GET ' . $this->pagesPath() => $this->cfError(403, 10000, 'Authentication error')]);
        $this->travel(6)->minutes();
        $detacher->detach($row->fresh());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'could not finish detaching'))->twice();

        // And without the token, a detaching row is among what the hourly
        // "waiting on the token" line names.
        config(['cloudflare.studio_token' => null]);
        $this->travel(31)->minutes();
        $this->artisan('domains:reconcile')->assertExitCode(0);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $c = []) => str_contains($m, 'waiting on CLOUDFLARE_STUDIO_TOKEN') && in_array($row->id, $c['ids'] ?? [], true))
            ->once();
    }

    // 8 ------------------------------------------------------------------

    #[Test]
    public function a_redirect_rule_alone_blocks_a_force_delete(): void
    {
        $org = $this->makeOrg();
        $this->makeDomain($org, 'pair-only.org', MasjidDomain::STATUS_PENDING, ['cf_redirect_rule_id' => 'rule-1']);

        $this->expectException(DomainsStillAttached::class);
        $org->forceDelete();
    }

    // 9 ------------------------------------------------------------------

    #[Test]
    public function re_probes_are_capped_per_run_and_spread_by_host(): void
    {
        config(['cloudflare.reconfirm.per_run' => 3, 'cloudflare.studio_token' => null]);
        $org = $this->makeOrg();
        foreach (range(1, 7) as $n) {
            $this->makeDomain($org, "h{$n}.example.org", MasjidDomain::STATUS_MANUAL, [
                'verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(), 'serving_confirmed_at' => now(),
            ]);
        }
        $this->fakeCloudflare(['GET https://*/api/tenant' => Http::response('{}', 200, ['x-manara-tenant' => (string) $org->id])]);

        $this->assertSame(0, Artisan::call('domains:reconcile', ['--json' => true]));
        $out = json_decode(Artisan::output(), true);
        $this->assertCount(3, $this->sent());
        $this->assertSame(4, $out['reprobes_deferred']);

        $this->travel(5)->minutes();
        $this->artisan('domains:reconcile')->assertExitCode(0);
        $this->travel(5)->minutes();
        $this->artisan('domains:reconcile')->assertExitCode(0);
        $this->assertCount(7, $this->sent(), 'every host within three runs');

        $offsets = MasjidDomain::query()->pluck('next_check_at', 'host')
            ->map(fn ($at) => $at->minute)
            ->unique();
        $this->assertGreaterThan(1, $offsets->count(), 'not all due in the same minute tomorrow');
    }

    // 10 -----------------------------------------------------------------

    #[Test]
    public function detach_release_and_collapse_are_ledgered_with_who_and_why(): void
    {
        $this->withStudioToken();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $super = User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15550000003'])->fresh();
        Sanctum::actingAs($super);
        $org = $this->makeOrg();
        $row = $this->attachedRow($org);
        $this->fakeCloudflare($this->cloudflareAsStudioLeftIt());

        $this->postJson("/api/admin/masjids/{$org->id}/domains/{$row->id}/detach")->assertStatus(202);

        $ledger = MasjidDomainChange::query()->where('action', MasjidDomainChange::ACTION_DETACH)->sole();
        $this->assertSame(self::HOST, $ledger->host);
        $this->assertSame("user #{$super->id} {$super->email}", $ledger->operator);
        $this->assertSame('Detached from the Studio domain panel.', $ledger->reason);
        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $ledger->before['status']);
        $this->assertSame(MasjidDomain::STATUS_DETACHING, $ledger->after['status']);

        // release: ledgered per detach, refused for a live org without
        // --force, and non-zero when a detach does not finish.
        $live = $this->makeOrg();
        $this->attachedRow($live, ['host' => 'www.live-masjid.org', 'zone_apex' => 'live-masjid.org']);
        $this->assertSame(1, Artisan::call('domains:release', ['masjid_id' => $live->id, '--execute' => true, '--operator' => 'owner', '--reason' => 'x']));
        $this->assertStringContainsString('--force', Artisan::output());
        $this->assertSame(1, Artisan::call('domains:release', ['masjid_id' => $live->id, '--execute' => true, '--force' => true]),
            'operator and reason are required');

        $this->fakeCloudflare(['GET /accounts/*/pages/projects/manara-renderer/domains/www.live-masjid.org' => $this->cfError(503, 0, 'Service unavailable')]);
        $this->assertSame(1, Artisan::call('domains:release', [
            'masjid_id' => $live->id, '--execute' => true, '--force' => true, '--operator' => 'owner', '--reason' => 'client left',
        ]), 'a partial release exits non-zero');
        $this->assertSame(1, MasjidDomainChange::query()->where('host', 'www.live-masjid.org')->where('operator', 'owner')->count());

        // collapse: ledgered on success.
        config(['cloudflare.redirect_zones' => [self::APEX]]);
        $pairOrg = $this->makeOrg();
        $this->canonicalRow($pairOrg);
        $apex = $this->canonicalRow($pairOrg, ['host' => self::APEX, 'cf_dns_record_id' => 'rec-apex', 'cf_pages_domain_id' => 'pd-' . md5(self::APEX)]);
        $this->fakeCloudflare([
            'GET ' . self::ENTRYPOINT => $this->cfOk($this->ruleset([])),
            'POST /zones/zone-pair/rulesets/rs-1/rules' => $this->cfOk($this->ruleset([$this->studioRule($apex)])),
            'GET /accounts/*/pages/projects/manara-renderer/domains/' . self::APEX => $this->cfOk($this->pagesDomainBody(self::APEX, 'active')),
            'DELETE /accounts/*/pages/projects/manara-renderer/domains/' . self::APEX => $this->cfOk([]),
        ] + $this->apexAnswers());
        $this->assertSame(1, Artisan::call('domains:collapse-alias', ['domain_id' => $apex->id, '--execute' => true]), 'operator and reason are required');
        $this->assertSame(0, Artisan::call('domains:collapse-alias', ['domain_id' => $apex->id, '--execute' => true, '--operator' => 'owner', '--reason' => 'free a slot']));
        $collapse = MasjidDomainChange::query()->where('action', MasjidDomainChange::ACTION_COLLAPSE)->sole();
        $this->assertSame(MasjidDomain::ROLE_SERVING, $collapse->before['role']);
        $this->assertSame(MasjidDomain::ROLE_REDIRECT, $collapse->after['role']);
    }

    // 11 -----------------------------------------------------------------

    #[Test]
    public function releasing_an_imported_row_needs_a_person_to_say_they_checked(): void
    {
        $row = $this->makeDomain($this->makeOrg(), 'meccharlotte.org', MasjidDomain::STATUS_RESERVED, ['source' => MasjidDomain::SOURCE_IMPORTED]);
        $this->fakeCloudflare(['GET https://meccharlotte.org/api/tenant' => Http::response('', 404)]);

        $this->assertSame(1, Artisan::call('domains:imported', [
            'action' => 'release', '--id' => [$row->id], '--execute' => true, '--operator' => 'owner', '--reason' => 'test',
        ]));
        $this->assertStringContainsString('--i-checked', Artisan::output());
        $this->assertNotNull(MasjidDomain::find($row->id));
        $this->assertSame([], $this->sent(), 'refused before even the probe');
    }

    // 12 -----------------------------------------------------------------

    #[Test]
    public function adopt_dry_run_writes_nothing(): void
    {
        $row = $this->makeDomain($this->makeOrg(), 'meccharlotte.org', MasjidDomain::STATUS_RESERVED, ['source' => MasjidDomain::SOURCE_IMPORTED]);
        $this->fakeCloudflare([]);
        $before = $row->fresh()->getAttributes();

        $this->assertSame(0, Artisan::call('domains:imported', ['action' => 'adopt', '--id' => [$row->id], '--json' => true]));
        $out = json_decode(Artisan::output(), true);

        $this->assertSame('would_adopt', $out['rows'][0]['outcome']);
        $this->assertFalse($out['executed']);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame(0, MasjidDomainChange::count());
    }

    #[Test]
    public function the_schedule_runs_the_monitors_and_never_an_operator_tool(): void
    {
        $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode("\n");

        $this->assertStringContainsString('domains:reconcile', $scheduled);
        $this->assertStringContainsString('domains:capacity', $scheduled);
        foreach (['domains:release', 'domains:collapse-alias', 'domains:imported', 'domains:import-host-map'] as $tool) {
            $this->assertStringNotContainsString($tool, $scheduled, "{$tool} changes live hosts and runs only by hand");
        }
    }
}
