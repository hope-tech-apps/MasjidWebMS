<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * `active` is Cloudflare's word that the domain and its certificate are live.
 * Nothing else may write it: a probe proves only that our site answered, and a
 * row that said `active` on a probe's say-so would claim a check nobody made.
 */
class MasjidDomainActiveInvariantTest extends TestCase
{
    use MakesStudioDomains;
    use RefreshDatabase;

    #[Test]
    public function a_row_cannot_be_saved_active_without_cloudflare_verification(): void
    {
        $org = $this->makeOrg();

        foreach ([
            'no verification at all' => [],
            'verified by the probe' => ['verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now()],
            'cloudflare named but no time' => ['verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE],
        ] as $case => $attributes) {
            try {
                $this->makeDomain($org, 'x' . md5($case) . '.example.org', MasjidDomain::STATUS_ACTIVE, $attributes);
                $this->fail("saved an active row with {$case}");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, MasjidDomain::query()->count());
    }

    #[Test]
    public function an_existing_row_cannot_be_moved_to_active_by_a_probe(): void
    {
        $domain = $this->makeDomain($this->makeOrg(), 'www.example.org', MasjidDomain::STATUS_MANUAL, [
            'verified_by' => MasjidDomain::VERIFIED_BY_PROBE, 'verified_at' => now(), 'serving_confirmed_at' => now(),
        ]);

        $domain->status = MasjidDomain::STATUS_ACTIVE;

        $this->expectException(LogicException::class);
        $domain->save();
    }

    #[Test]
    public function cloudflare_verification_may_save_active(): void
    {
        $domain = $this->makeDomain($this->makeOrg(), 'www.example.org', MasjidDomain::STATUS_ACTIVE, [
            'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now(),
        ]);

        $this->assertSame(MasjidDomain::STATUS_ACTIVE, $domain->fresh()->status);
    }

    #[Test]
    public function an_unknown_status_or_kind_is_refused(): void
    {
        $org = $this->makeOrg();

        foreach ([['status' => 'live'], ['kind' => 'subdomain']] as $bad) {
            try {
                $this->makeDomain($org, 'y' . md5(json_encode($bad)) . '.example.org', $bad['status'] ?? MasjidDomain::STATUS_PENDING, $bad);
                $this->fail('saved ' . json_encode($bad));
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
