<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Models\MasjidDomainChange;
use App\Services\Domains\DetachResult;
use App\Services\Domains\DomainAttacher;
use App\Services\Domains\DomainDetacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * `domains:imported` (W2 S6): the one reviewed tool for the rows imported from
 * the live host map. Listing writes nothing; release and adopt are dry runs
 * until --execute with an operator and a reason, and each executed change is
 * ledgered.
 */
class ImportedDomainsCommandTest extends TestCase
{
    use FakesCloudflare;
    use MakesStudioDomains;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolveTo(['93.184.216.34']);
        config(['cloudflare.studio_token' => null]);
    }

    private function reserved(Masjid $org, string $host = 'meccharlotte.org'): MasjidDomain
    {
        return $this->makeDomain($org, $host, MasjidDomain::STATUS_RESERVED, ['source' => MasjidDomain::SOURCE_IMPORTED]);
    }

    private function servingImported(Masjid $org, string $host = 'mec.manara.hopetechapps.com', string $status = MasjidDomain::STATUS_MANUAL): MasjidDomain
    {
        return $this->makeDomain($org, $host, $status, [
            'source' => MasjidDomain::SOURCE_IMPORTED,
            'kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN,
            'zone_apex' => 'hopetechapps.com',
            'verified_by' => $status === MasjidDomain::STATUS_ACTIVE ? MasjidDomain::VERIFIED_BY_CLOUDFLARE : MasjidDomain::VERIFIED_BY_PROBE,
            'verified_at' => now(),
            'serving_confirmed_at' => now(),
        ]);
    }

    /** Each host answers as the given organisation, or 404. */
    private function hostsAnswer(array $answers): void
    {
        $routes = [];
        foreach ($answers as $host => $masjidId) {
            $routes["GET https://{$host}/api/tenant"] = $masjidId === null
                ? Http::response('Not found', 404)
                : Http::response('{}', 200, ['x-manara-tenant' => (string) $masjidId]);
        }
        $this->fakeCloudflare($routes);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function imported(string $action, array $options = []): array
    {
        $code = Artisan::call('domains:imported', ['action' => $action, '--json' => true] + $options);

        return [$code, json_decode(Artisan::output(), true) ?? []];
    }

    #[Test]
    public function list_writes_nothing(): void
    {
        $org = $this->makeOrg(['name' => 'Muslim Education Center']);
        $this->servingImported($org);
        $this->reserved($org);
        $this->makeDomain($org, 'studio.example.org');
        $this->hostsAnswer(['mec.manara.hopetechapps.com' => $org->id, 'meccharlotte.org' => null]);
        $before = MasjidDomain::query()->orderBy('id')->get()->map->getAttributes()->all();

        [$code, $out] = $this->imported('list');

        $this->assertSame(0, $code);
        $rows = collect($out['rows'])->keyBy('host');
        $this->assertSame(['mec.manara.hopetechapps.com', 'meccharlotte.org'], $rows->keys()->sort()->values()->all(), 'imported rows only');
        $this->assertTrue($rows['mec.manara.hopetechapps.com']['probe_matches_now']);
        $this->assertFalse($rows['meccharlotte.org']['probe_matches_now']);
        $this->assertSame('Muslim Education Center', $rows['meccharlotte.org']['organisation']);
        $this->assertSame($before, MasjidDomain::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame(0, MasjidDomainChange::count());
        $this->assertSame([], $this->sentToCloudflare());
    }

    #[Test]
    public function execute_without_operator_and_reason_is_refused(): void
    {
        $row = $this->reserved($this->makeOrg());
        $this->hostsAnswer(['meccharlotte.org' => null]);

        foreach ([[], ['--operator' => 'owner'], ['--reason' => 'MEC moved its zone']] as $missing) {
            $this->assertSame(1, Artisan::call('domains:imported', ['action' => 'adopt', '--id' => [$row->id], '--execute' => true] + $missing));
        }

        $this->assertSame(MasjidDomain::STATUS_RESERVED, $row->fresh()->status);
        $this->assertSame(0, MasjidDomainChange::count());
        $this->assertSame([], $this->sent(), 'refused before even the probe');
    }

    #[Test]
    public function release_refuses_a_host_that_is_serving_its_org(): void
    {
        $org = $this->makeOrg();
        $row = $this->reserved($org);
        $this->hostsAnswer(['meccharlotte.org' => $org->id]);

        [$code, $out] = $this->imported('release', ['--id' => [$row->id], '--execute' => true, '--operator' => 'owner', '--reason' => 'test']);

        $this->assertSame(1, $code);
        $this->assertSame('refused', $out['rows'][0]['outcome']);
        $this->assertStringContainsString('serving organisation', $out['rows'][0]['reason']);
        $this->assertNotNull(MasjidDomain::find($row->id));
    }

    #[Test]
    public function release_frees_a_reserved_host_for_studio(): void
    {
        $org = $this->makeOrg();
        $row = $this->reserved($org);
        $this->hostsAnswer(['meccharlotte.org' => null]);

        [$dry] = $this->imported('release', ['--id' => [$row->id]]);
        $this->assertSame(0, $dry);
        $this->assertNotNull(MasjidDomain::find($row->id), 'the dry run changed nothing');

        [$code, $out] = $this->imported('release', ['--id' => [$row->id], '--execute' => true, '--operator' => 'owner', '--reason' => 'MEC chose a new domain']);

        $this->assertSame(0, $code);
        $this->assertSame('released', $out['rows'][0]['outcome']);
        $this->assertNull(MasjidDomain::find($row->id));

        // The host is free: Studio may record it again.
        $this->makeDomain($this->makeOrg(), 'meccharlotte.org');
        $this->assertSame(1, MasjidDomain::query()->where('host', 'meccharlotte.org')->count());
    }

    #[Test]
    public function adopt_hands_the_row_to_the_attacher_which_still_refuses_a_foreign_record(): void
    {
        $org = $this->makeOrg();
        $row = $this->reserved($org);
        $this->hostsAnswer([]);

        [$code] = $this->imported('adopt', ['--id' => [$row->id], '--execute' => true, '--operator' => 'owner', '--reason' => 'MEC moved its zone to Cloudflare']);
        $row->refresh();

        $this->assertSame(0, $code);
        $this->assertSame(MasjidDomain::SOURCE_STUDIO, $row->source);
        $this->assertSame(MasjidDomain::STATUS_PENDING, $row->status);
        $this->assertNotNull($row->adopted_from_import_at);
        $this->assertFalse($row->ownedByStudio(), 'an adopted row keeps an imported row\'s protections');

        // The attacher now attaches it, and meets the registrar's A record.
        $this->withStudioToken();
        $this->fakeCloudflare([
            'GET /zones?*' => $this->cfOk([$this->zoneBody('meccharlotte.org', 'active', 'zone-mec')]),
            'GET /zones/zone-mec/dns_records?*' => $this->cfOk([$this->dnsRecord('meccharlotte.org', 'A', '198.51.100.20', 'rec-registrar')]),
        ]);
        $this->app->make(DomainAttacher::class)->advance($row);
        $row->refresh();

        $this->assertSame(MasjidDomain::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('will not overwrite', (string) $row->last_error);
        $verbs = array_map(fn (string $line) => strtok($line, ' '), $this->sentToCloudflare());
        $this->assertSame(['GET', 'GET'], $verbs, 'nothing written');
    }

    #[Test]
    public function adopt_refuses_a_manual_or_active_row(): void
    {
        $org = $this->makeOrg();
        $manual = $this->servingImported($org);
        $active = $this->servingImported($org, 'alrazi.manara.hopetechapps.com', MasjidDomain::STATUS_ACTIVE);
        $this->hostsAnswer([]);

        [$code, $out] = $this->imported('adopt', ['--id' => [$manual->id, $active->id], '--execute' => true, '--operator' => 'owner', '--reason' => 'test']);

        $this->assertSame(1, $code);
        foreach ($out['rows'] as $row) {
            $this->assertSame('refused', $row['outcome']);
            $this->assertStringContainsString('it is serving', $row['reason']);
        }
        $this->assertSame(MasjidDomain::SOURCE_IMPORTED, $manual->fresh()->source);
        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $active->fresh()->status);
        $this->assertSame(0, MasjidDomainChange::count());
    }

    #[Test]
    public function an_adopted_row_cannot_be_detached_or_auto_demoted(): void
    {
        $org = $this->makeOrg();
        $row = $this->reserved($org, 'www.meccharlotte.org');
        $this->hostsAnswer([]);
        $this->imported('adopt', ['--id' => [$row->id], '--execute' => true, '--operator' => 'owner', '--reason' => 'test']);

        // Later: attached, confirmed, then not answering for days.
        MasjidDomain::query()->whereKey($row->id)->update([
            'status' => MasjidDomain::STATUS_ACTIVE, 'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now(),
            'serving_confirmed_at' => now(), 'serving_miss_count' => 5, 'serving_missed_since' => now()->subWeek(),
            'cf_zone_id' => 'zone-mec', 'cf_dns_record_id' => 'rec-1', 'cf_dns_record_created' => true,
        ]);
        $row->refresh();

        $this->assertSame(DetachResult::REFUSED, $this->app->make(DomainDetacher::class)->detach($row)->outcome);
        $this->assertFalse($row->deletableThroughStudio(), 'never deletable, even with no Cloudflare id');
        $bare = $this->reserved($this->makeOrg(), 'bare.example.org');
        $this->imported('adopt', ['--id' => [$bare->id], '--execute' => true, '--operator' => 'owner', '--reason' => 'test']);
        $this->assertFalse($bare->fresh()->deletableThroughStudio(), 'an adopted row with no Cloudflare state is still not deletable');

        $this->hostsAnswer(['www.meccharlotte.org' => null]);
        $channel = new class
        {
            public function __call(string $level, array $arguments): void
            {
            }
        };
        Log::shouldReceive('channel')->andReturn($channel);
        $this->app->make(DomainAttacher::class)->advance($row);

        $this->assertNotNull($row->fresh()->serving_confirmed_at, 'never demoted automatically');
        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $row->fresh()->status);
    }

    #[Test]
    public function every_executed_action_writes_one_ledger_row_and_a_warning(): void
    {
        $org = $this->makeOrg();
        $release = $this->reserved($org, 'new.burlingtonmasjid.com');
        $adopt = $this->reserved($org);
        $this->hostsAnswer(['new.burlingtonmasjid.com' => null, 'meccharlotte.org' => null]);
        Log::spy();

        $this->imported('release', ['--id' => [$release->id], '--execute' => true, '--operator' => 'Moneeb', '--reason' => 'not a custom domain on the project']);
        $this->imported('adopt', ['--id' => [$adopt->id], '--execute' => true, '--operator' => 'Moneeb', '--reason' => 'MEC go, 2026-10-01']);

        $ledger = MasjidDomainChange::query()->orderBy('id')->get();
        $this->assertCount(2, $ledger);

        [$released, $adopted] = [$ledger[0], $ledger[1]];
        $this->assertSame(MasjidDomainChange::ACTION_RELEASE, $released->action);
        $this->assertSame('new.burlingtonmasjid.com', $released->host);
        $this->assertSame($release->id, (int) $released->masjid_domain_id);
        $this->assertSame(MasjidDomain::STATUS_RESERVED, $released->before['status']);
        $this->assertNull($released->after);
        $this->assertSame('Moneeb', $released->operator);
        $this->assertSame('not a custom domain on the project', $released->reason);

        $this->assertSame(MasjidDomainChange::ACTION_ADOPT, $adopted->action);
        $this->assertSame(MasjidDomain::SOURCE_IMPORTED, $adopted->before['source']);
        $this->assertSame(MasjidDomain::SOURCE_STUDIO, $adopted->after['source']);
        $this->assertSame(MasjidDomain::STATUS_PENDING, $adopted->after['status']);
        $this->assertNotNull($adopted->after['adopted_from_import_at']);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'An imported web address was changed'))->twice();

        // The ledger is append-only.
        try {
            $released->update(['reason' => 'rewritten']);
            $this->fail('a ledger row was modified');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $adopted->delete();
    }

    #[Test]
    public function the_reserved_invariant_still_holds_outside_the_tool(): void
    {
        $row = $this->reserved($this->makeOrg());

        foreach ([
            'status' => ['status' => MasjidDomain::STATUS_PENDING],
            'source' => ['source' => MasjidDomain::SOURCE_STUDIO],
            'adoption' => ['adopted_from_import_at' => now()],
        ] as $case => $change) {
            try {
                $row->fresh()->forceFill($change)->save();
                $this->fail("{$case}: changed outside the tool");
            } catch (LogicException) {
                // refused
            }
        }

        $this->assertSame(MasjidDomain::STATUS_RESERVED, $row->fresh()->status);
        $this->assertSame(MasjidDomain::SOURCE_IMPORTED, $row->fresh()->source);
        $this->assertNull($row->fresh()->adopted_from_import_at);
    }
}
