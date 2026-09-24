<?php

namespace Tests\Feature\Studio;

use App\Console\Commands\ReconcileDomains;
use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Services\Domains\DomainAttacher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * `domains:reconcile`, the five-minute poller (S7): which rows it owns, that it
 * is a no-op on production's shape of data, and its schedule.
 */
class DomainsReconcileCommandTest extends TestCase
{
    use FakesCloudflare;
    use MakesStudioDomains;
    use RefreshDatabase;

    /**
     * One row in every state the table can hold, keyed by what it is.
     *
     * @return array<string, MasjidDomain>
     */
    private function everyKindOfRow(Masjid $org): array
    {
        $probed = ['verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(), 'serving_confirmed_at' => now()];
        $cloudflare = ['verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now()];

        return [
            'pending, due' => $this->makeDomain($org, 'a.example.org'),
            'pending, not due' => $this->makeDomain($org, 'b.example.org', MasjidDomain::STATUS_PENDING, ['next_check_at' => now()->addMinutes(20)]),
            'awaiting nameservers, due' => $this->makeDomain($org, 'c.example.org', MasjidDomain::STATUS_AWAITING_NAMESERVERS, ['next_check_at' => now()->subMinute()]),
            'provisioning, due' => $this->makeDomain($org, 'd.example.org', MasjidDomain::STATUS_PROVISIONING),
            'active, unconfirmed' => $this->makeDomain($org, 'e.example.org', MasjidDomain::STATUS_ACTIVE, $cloudflare),
            'active, confirmed' => $this->makeDomain($org, 'f.example.org', MasjidDomain::STATUS_ACTIVE, $cloudflare + ['serving_confirmed_at' => now()]),
            'manual, imported' => $this->makeDomain($org, 'g.example.org', MasjidDomain::STATUS_MANUAL, $probed + ['source' => MasjidDomain::SOURCE_IMPORTED]),
            'manual, studio' => $this->makeDomain($org, 'h.example.org', MasjidDomain::STATUS_MANUAL, $probed),
            'manual, imported, not due' => $this->makeDomain($org, 'i.example.org', MasjidDomain::STATUS_MANUAL, $probed + ['source' => MasjidDomain::SOURCE_IMPORTED, 'next_check_at' => now()->addHour()]),
            'reserved' => $this->makeDomain($org, 'j.example.org', MasjidDomain::STATUS_RESERVED, ['source' => MasjidDomain::SOURCE_IMPORTED]),
            'failed' => $this->makeDomain($org, 'k.example.org', MasjidDomain::STATUS_FAILED),
        ];
    }

    /** @param  array<string, MasjidDomain>  $rows */
    private function selected(array $rows, bool $token): array
    {
        $ids = ReconcileDomains::selection($token)->pluck('id')->all();

        return array_keys(array_filter($rows, fn (MasjidDomain $row) => in_array($row->id, $ids, true)));
    }

    #[Test]
    public function it_selects_the_rows_that_are_moving_or_unconfirmed_and_reads_manual_ones_only_with_a_token(): void
    {
        $rows = $this->everyKindOfRow($this->makeOrg());

        $this->assertSame(
            ['pending, due', 'awaiting nameservers, due', 'provisioning, due', 'active, unconfirmed'],
            $this->selected($rows, false),
        );

        $this->assertSame(
            ['pending, due', 'awaiting nameservers, due', 'provisioning, due', 'active, unconfirmed', 'manual, imported', 'manual, studio'],
            $this->selected($rows, true),
        );

        // --id narrows to the rows asked for, due or not, and still never a
        // reserved or failed one.
        $ids = ReconcileDomains::selection(false, [$rows['pending, not due']->id, $rows['reserved']->id, $rows['failed']->id])->pluck('id')->all();
        $this->assertSame([$rows['pending, not due']->id], $ids);
    }

    #[Test]
    public function without_a_token_on_productions_rows_it_selects_nothing_and_sends_nothing(): void
    {
        // What production holds after the S3 import: imported manual rows seen
        // serving, and reserved ones. No Studio row yet.
        $org = $this->makeOrg();
        $probed = ['source' => MasjidDomain::SOURCE_IMPORTED, 'verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(), 'serving_confirmed_at' => now()];
        $this->makeDomain($org, 'mec.manara.hopetechapps.com', MasjidDomain::STATUS_MANUAL, $probed + ['kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN, 'zone_apex' => 'hopetechapps.com']);
        $this->makeDomain($org, 'meccharlotte.org', MasjidDomain::STATUS_RESERVED, ['source' => MasjidDomain::SOURCE_IMPORTED]);
        $before = MasjidDomain::query()->orderBy('id')->get()->map->getAttributes()->all();

        Http::preventStrayRequests();
        Http::fake();
        Log::spy();

        $this->assertSame(0, Artisan::call('domains:reconcile', ['--json' => true]));
        $out = json_decode(Artisan::output(), true);

        $this->assertFalse($out['token_configured']);
        $this->assertSame(0, $out['selected']);
        Http::assertNothingSent();
        Log::shouldNotHaveReceived('warning');
        $this->assertSame($before, MasjidDomain::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    #[Test]
    public function with_a_token_and_no_row_in_a_state_it_owns_it_sends_nothing(): void
    {
        $this->withStudioToken();
        $org = $this->makeOrg();
        $this->makeDomain($org, 'meccharlotte.org', MasjidDomain::STATUS_RESERVED, ['source' => MasjidDomain::SOURCE_IMPORTED]);
        $this->makeDomain($org, 'k.example.org', MasjidDomain::STATUS_FAILED);
        $this->makeDomain($org, 'f.example.org', MasjidDomain::STATUS_ACTIVE, [
            'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now(), 'serving_confirmed_at' => now(),
        ]);
        $before = MasjidDomain::query()->orderBy('id')->get()->map->getAttributes()->all();
        $this->fakeCloudflare([]);

        $this->artisan('domains:reconcile', ['--json' => true])->assertExitCode(0);

        $this->assertSame([], $this->sent());
        $this->assertSame($before, MasjidDomain::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    #[Test]
    public function without_a_token_it_warns_once_an_hour_while_rows_wait_and_only_probes_their_own_hosts(): void
    {
        config(['cloudflare.studio_token' => null]);
        $org = $this->makeOrg();
        $row = $this->makeDomain($org, 'www.new-masjid.org', MasjidDomain::STATUS_PENDING, ['zone_apex' => 'new-masjid.org']);
        $this->resolveTo(['93.184.216.34']);
        $this->fakeCloudflare(['GET https://www.new-masjid.org/api/tenant' => Http::response('', 404)]);
        Log::spy();

        $this->artisan('domains:reconcile')->assertExitCode(0);
        $this->travel(6)->minutes();
        $this->artisan('domains:reconcile')->assertExitCode(0);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'CLOUDFLARE_STUDIO_TOKEN'))
            ->once();

        $this->travel(61)->minutes();
        $this->artisan('domains:reconcile')->assertExitCode(0);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'CLOUDFLARE_STUDIO_TOKEN'))
            ->twice();

        $this->assertSame([], $this->sentToCloudflare());
        $this->assertSame(['GET https://www.new-masjid.org/api/tenant'], array_values(array_unique($this->sent())));
        $this->assertSame('token', $row->fresh()->waiting_on);
    }

    #[Test]
    public function it_exits_zero_when_a_row_cannot_be_advanced(): void
    {
        config(['cloudflare.studio_token' => null]);
        $this->makeDomain($this->makeOrg(), 'a.example.org');
        $this->app->instance(DomainAttacher::class, new class extends DomainAttacher
        {
            public function __construct()
            {
            }

            public function advance(MasjidDomain $domain): MasjidDomain
            {
                throw new RuntimeException('boom');
            }
        });
        Log::spy();

        $this->artisan('domains:reconcile')->assertExitCode(0);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'could not advance'))->once();
    }

    #[Test]
    public function it_is_scheduled_every_five_minutes_off_the_busy_minutes_with_a_short_overlap_lock(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'domains:reconcile'));

        $this->assertCount(1, $events, 'scheduled exactly once');
        $event = $events->first();

        $this->assertSame('3-59/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);

        foreach (range(3, 59, 5) as $minute) {
            $this->assertNotContains($minute, [0, 15, 30, 45, 47], 'a minute the reaper or the canary owns');
        }
    }
}
