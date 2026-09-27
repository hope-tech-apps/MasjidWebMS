<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use App\Services\Domains\DomainAttacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\FakesCloudflare;
use Tests\Feature\Studio\Concerns\MakesRedirectDomains;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * W2 S5: a redirect host is verified only by seeing it answer 301 to its own
 * canonical host. The redirect is read, never followed.
 */
class RedirectProbeTest extends TestCase
{
    use FakesCloudflare;
    use MakesRedirectDomains;
    use MakesStudioDomains;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withStudioToken();
        $this->resolveTo(['93.184.216.34']);
    }

    private function provisioningRedirect(): MasjidDomain
    {
        $org = $this->makeOrg();

        return $this->redirectRow($org, $this->canonicalRow($org), MasjidDomain::STATUS_PROVISIONING, [
            'cf_zone_id' => self::PAIR_ZONE,
            'cf_redirect_rule_id' => 'rule-studio',
            'stage_started_at' => now(),
        ]);
    }

    #[Test]
    public function a_301_to_the_canonical_host_verifies(): void
    {
        $row = $this->provisioningRedirect();
        $this->fakeCloudflare($this->apexAnswers(301, 'https://www.pair-masjid.org/about?x=1'));

        $this->app->make(DomainAttacher::class)->advance($row);
        $row->refresh();

        $this->assertSame(MasjidDomain::STATUS_MANUAL, $row->status);
        $this->assertSame(MasjidDomain::VERIFIED_BY_PROBE, $row->verified_by);
        $this->assertNotNull($row->verified_at);
        $this->assertNull($row->serving_confirmed_at, 'a redirect host is never "serving"');
        $this->assertNull($row->next_check_at);
        $this->assertSame([], $row->manualSteps());
        $this->assertSame([], $this->sentToCloudflare());
        $this->assertSame(['GET https://pair-masjid.org/'], $this->sent());
    }

    #[Test]
    public function a_301_elsewhere_or_a_200_does_not(): void
    {
        foreach ([
            '301 to another host' => [301, 'https://www.someone-else.org/'],
            '302 to the canonical host' => [302, 'https://www.pair-masjid.org/'],
            '301 to plain http' => [301, 'http://www.pair-masjid.org/'],
            'a page' => [200, ''],
        ] as $case => [$status, $location]) {
            $row = $this->provisioningRedirect();
            $this->fakeCloudflare($this->apexAnswers($status, $location));

            $this->app->make(DomainAttacher::class)->advance($row);
            $row->refresh();

            $this->assertSame(MasjidDomain::STATUS_PROVISIONING, $row->status, $case);
            $this->assertStringContainsString('Not redirecting to www.pair-masjid.org yet', (string) $row->last_error, $case);

            MasjidDomain::query()->delete();
        }
    }

    #[Test]
    public function it_fails_after_72_hours_without_the_301(): void
    {
        $row = $this->provisioningRedirect();
        $row->forceFill(['stage_started_at' => now()->subHours(73)])->save();
        $this->fakeCloudflare($this->apexAnswers(200, ''));

        $this->app->make(DomainAttacher::class)->advance($row);

        $this->assertSame(MasjidDomain::STATUS_FAILED, $row->fresh()->status);
    }

    #[Test]
    public function without_a_token_a_redirect_made_by_hand_is_verified_by_the_probe_alone(): void
    {
        config(['cloudflare.studio_token' => null]);
        $org = $this->makeOrg();
        $row = $this->redirectRow($org, $this->canonicalRow($org));
        $this->fakeCloudflare($this->apexAnswers());

        $this->app->make(DomainAttacher::class)->advance($row);
        $row->refresh();

        $this->assertSame(MasjidDomain::STATUS_MANUAL, $row->status);
        $this->assertSame([], $this->sentToCloudflare());
        Http::assertSentCount(1);
    }
}
