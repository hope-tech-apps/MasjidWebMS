<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\MasjidDomain;
use App\Services\Domains\DomainAttacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * W2 S4: a host seen serving is asked again once a day. One blip never takes
 * its CORS and payment-return admission away; three misses over at least 72
 * hours take a Studio host's away; an imported host never loses it, and the
 * owner is told instead.
 */
class DomainReconfirmationTest extends TestCase
{
    use FakesCloudflare;
    use MakesStudioDomains;
    use RefreshDatabase;

    private const HOST = 'www.new-masjid.org';

    /** Whether the host answers for its own organisation on the next probe. */
    private bool $serving = true;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cloudflare.studio_token' => null]);
        $this->resolveTo(['93.184.216.34']);
    }

    private function attacher(): DomainAttacher
    {
        return $this->app->make(DomainAttacher::class);
    }

    /** The host answers for `$org` while $this->serving, and with a plain 404 otherwise. */
    private function hostAnswersFor(Masjid $org, string $host = self::HOST): void
    {
        $this->fakeCloudflare([
            "GET https://{$host}/api/tenant" => fn () => $this->serving
                ? Http::response('{}', 200, ['x-manara-tenant' => (string) $org->id])
                : Http::response('Not found', 404),
        ]);
    }

    private function confirmedStudioRow(Masjid $org): MasjidDomain
    {
        return $this->makeDomain($org, self::HOST, MasjidDomain::STATUS_ACTIVE, [
            'zone_apex' => 'new-masjid.org',
            'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE,
            'verified_at' => now()->subWeek(),
            'serving_confirmed_at' => now()->subWeek(),
        ]);
    }

    private function confirmedImportedRow(Masjid $org): MasjidDomain
    {
        return $this->makeDomain($org, self::HOST, MasjidDomain::STATUS_MANUAL, [
            'zone_apex' => 'new-masjid.org',
            'source' => MasjidDomain::SOURCE_IMPORTED,
            'verified_by' => MasjidDomain::VERIFIED_BY_PROBE,
            'verified_at' => now()->subWeek(),
            'serving_confirmed_at' => now()->subWeek(),
        ]);
    }

    /** A day passes and the poller gets to the row. */
    private function nextDay(MasjidDomain $row): MasjidDomain
    {
        $this->travel(24)->hours();
        $this->attacher()->advance($row);

        return $row->refresh();
    }

    private function admitted(MasjidDomain $row): bool
    {
        return MasjidDomain::query()->corsAdmitted()->whereKey($row->id)->exists();
    }

    /** Stand in for `monitors` and record what reached it. */
    private function recordMonitorLines(): object
    {
        $recorder = new class
        {
            public array $lines = [];

            public function __call(string $level, array $arguments): void
            {
                $this->lines[] = [$level, (string) $arguments[0]];
            }
        };

        Log::shouldReceive('channel')->with('monitors')->andReturn($recorder);

        return $recorder;
    }

    #[Test]
    public function one_miss_changes_nothing_a_visitor_or_payer_sees(): void
    {
        $org = $this->makeOrg();
        $row = $this->confirmedStudioRow($org);
        $confirmed = $row->serving_confirmed_at->toDateTimeString();
        $this->hostAnswersFor($org);
        $this->serving = false;

        $this->attacher()->advance($row);
        $row->refresh();

        $this->assertSame(1, $row->serving_miss_count);
        $this->assertNotNull($row->serving_missed_since);
        $this->assertSame($confirmed, $row->serving_confirmed_at->toDateTimeString());
        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $row->status);
        $this->assertTrue($this->admitted($row));
        $this->assertSame('https://' . self::HOST, $row->liveUrl());
        $this->getJson('/api/v1/organizations/by-host?host=' . self::HOST)->assertOk();
        $this->assertNull($row->last_error, 'a single miss is not reported as a fault');
        $this->assertNotNull($row->next_check_at);
        $this->assertTrue($row->next_check_at->gte(now()->addHours(23)), 'next asked in a day');
    }

    #[Test]
    public function three_misses_over_seventy_two_hours_demote_a_studio_row(): void
    {
        $org = $this->makeOrg();
        $row = $this->confirmedStudioRow($org);
        $this->hostAnswersFor($org);
        $this->serving = false;
        Log::spy();

        $this->attacher()->advance($row);             // miss 1, the run starts
        $this->nextDay($row);                         // miss 2, 24 h in
        $this->nextDay($row);                         // miss 3, 48 h in: not yet
        $this->assertSame(3, $row->serving_miss_count);
        $this->assertNotNull($row->serving_confirmed_at, 'three misses inside 72 hours keep it');
        $this->assertTrue($this->admitted($row));

        $this->nextDay($row);                         // miss 4, 72 h in

        $this->assertNull($row->serving_confirmed_at);
        $this->assertFalse($this->admitted($row));
        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $row->status, 'the status is unchanged');
        $this->assertSame(MasjidDomain::VERIFIED_BY_CLOUDFLARE, $row->verified_by);
        $this->assertNull($row->liveUrl());
        $this->assertStringContainsString('Not seen serving this organisation in 4 checks', (string) $row->last_error);
        $this->assertStringContainsString('stopped answering for this organisation (4 checks in a row', $row->manualSteps()[0]);
        // The lookup still answers, so the next probe can reach our site.
        $this->getJson('/api/v1/organizations/by-host?host=' . self::HOST)->assertOk();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'lost its CORS and payment-return admission'))->once();
    }

    #[Test]
    public function three_misses_inside_seventy_two_hours_do_not_demote(): void
    {
        $org = $this->makeOrg();
        $row = $this->confirmedStudioRow($org);
        $this->hostAnswersFor($org);
        $this->serving = false;

        // An operator presses Check now again and again: every press probes,
        // and none of those misses spans 72 hours.
        foreach (range(1, 6) as $press) {
            $this->assertTrue($this->attacher()->checkNow($row));
            $this->travel(11)->hours();
        }
        $row->refresh();

        $this->assertSame(6, $row->serving_miss_count);
        $this->assertNotNull($row->serving_confirmed_at);
        $this->assertTrue($this->admitted($row));
    }

    #[Test]
    public function the_daily_probe_is_not_repeated_inside_a_day(): void
    {
        $org = $this->makeOrg();
        $row = $this->confirmedStudioRow($org);
        $this->hostAnswersFor($org);

        foreach (range(1, 5) as $tick) {
            $this->attacher()->advance($row);
            $this->travel(4)->hours();
        }

        // Five advances over sixteen hours: one probe.
        $this->assertCount(1, $this->sent());
        $this->assertNotNull($row->fresh()->serving_last_seen_at);
    }

    #[Test]
    public function the_next_days_run_probes_even_when_it_comes_a_little_early(): void
    {
        // next_check_at is stored to the second and a run can reach a row a
        // moment before the day is quite up; the once-a-day marker must be
        // gone by then, or the probe slips a whole day.
        $org = $this->makeOrg();
        $row = $this->confirmedStudioRow($org);
        $this->hostAnswersFor($org);

        $this->attacher()->advance($row);
        $this->travel(23 * 60 + 40)->minutes();
        $this->attacher()->advance($row);

        $this->assertCount(2, $this->sent());
    }

    #[Test]
    public function an_imported_row_is_never_demoted_and_the_owner_is_told_once(): void
    {
        $org = $this->makeOrg();
        $row = $this->confirmedImportedRow($org);
        $this->hostAnswersFor($org);
        $this->serving = false;
        $monitors = $this->recordMonitorLines();

        $this->attacher()->advance($row);
        foreach (range(2, 7) as $day) {
            $this->nextDay($row);
        }

        $this->assertSame(7, $row->serving_miss_count);
        $this->assertNotNull($row->serving_confirmed_at, 'never demoted');
        $this->assertTrue($this->admitted($row));
        $this->assertCount(1, $monitors->lines, 'told once in the run of misses');
        [$level, $message] = $monitors->lines[0];
        $this->assertSame('error', $level);
        $this->assertStringContainsString(self::HOST, $message);
        $this->assertStringContainsString("organisation #{$org->id}", $message);
        $this->assertStringContainsString('404', $message, 'what the probe saw');
        $this->assertStringContainsString('php artisan domains:imported list', $message);

        // A match ends the run; a new run of misses is told again.
        $this->serving = true;
        $this->nextDay($row);
        $this->assertSame(0, $row->serving_miss_count);
        $this->serving = false;
        foreach (range(1, 3) as $day) {
            $this->nextDay($row);
        }
        $this->assertCount(2, $monitors->lines);

        // Adopted from the import (S6) is the same.
        // As `domains:imported adopt` leaves a row (W2 S6), written past the
        // model, which refuses that change anywhere but in the tool.
        MasjidDomain::query()->whereKey($row->id)->update([
            'source' => MasjidDomain::SOURCE_STUDIO, 'adopted_from_import_at' => now(),
            'serving_miss_count' => 5, 'serving_missed_since' => now()->subWeek(),
        ]);
        $row->refresh();
        $this->nextDay($row);
        $this->assertNotNull($row->serving_confirmed_at);
    }

    #[Test]
    public function a_match_after_demotion_reconfirms(): void
    {
        $org = $this->makeOrg();
        $row = $this->confirmedStudioRow($org);
        $row->forceFill([
            'serving_confirmed_at' => null,
            'serving_miss_count' => 4,
            'serving_missed_since' => now()->subDays(3),
            'last_error' => 'Not seen serving this organisation in 4 checks.',
        ])->save();
        $this->assertTrue($row->underReconfirmation(), 'the premise: a demoted row is still asked');
        $this->hostAnswersFor($org);

        $this->attacher()->advance($row);
        $row->refresh();

        $this->assertNotNull($row->serving_confirmed_at);
        $this->assertNotNull($row->serving_last_seen_at);
        $this->assertSame(0, $row->serving_miss_count);
        $this->assertNull($row->serving_missed_since);
        $this->assertNull($row->last_error);
        $this->assertTrue($this->admitted($row));
    }

    #[Test]
    public function a_demoted_row_leaves_cors_admission_within_the_cache_ttl(): void
    {
        $org = $this->makeOrg();
        $row = $this->confirmedStudioRow($org);
        $row->forceFill(['serving_miss_count' => 3, 'serving_missed_since' => now()->subHours(72)])->save();
        $this->assertSame(['https://' . self::HOST], MasjidDomain::corsOrigins());
        $this->assertTrue(Cache::has(MasjidDomain::CORS_ORIGINS_CACHE_KEY), 'the premise: the list is cached');
        $this->hostAnswersFor($org);
        $this->serving = false;

        $this->attacher()->advance($row);

        $this->assertNull($row->fresh()->serving_confirmed_at);
        // The save forgot the cached list, so the next read no longer has it:
        // well inside the five-minute TTL.
        $this->assertSame([], MasjidDomain::corsOrigins());
    }

    #[Test]
    public function with_a_token_a_manual_row_is_read_every_six_hours_and_probed_once_a_day(): void
    {
        $this->withStudioToken();
        $org = $this->makeOrg();
        $row = $this->confirmedImportedRow($org);
        $this->fakeCloudflare([
            "GET https://" . self::HOST . '/api/tenant' => Http::response('{}', 200, ['x-manara-tenant' => (string) $org->id]),
            'GET /accounts/*/pages/projects/manara-renderer/domains/' . self::HOST => $this->cfError(404, 8000007, 'Domain not found.'),
        ]);

        foreach (range(1, 4) as $read) {
            $this->attacher()->advance($row);
            $row->refresh();
            $this->travel(6)->hours();
        }

        $probes = array_filter($this->sent(), fn (string $line) => str_ends_with($line, '/api/tenant'));
        $this->assertCount(1, $probes, 'one probe in 24 hours');
        $this->assertCount(4, $this->sentToCloudflare(), 'a read every six hours, as in W1');
        $this->assertSame(MasjidDomain::STATUS_MANUAL, $row->status);
        $this->assertNotNull($row->serving_confirmed_at);
    }
}
