<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * `reserved` holds a live host for an organisation without trusting it (R4):
 * never served, never CORS-admitted, never advanced. The model refuses the
 * move itself, so no caller (the probe, S7's "Check now", the reconcile job)
 * can promote one by forgetting to check first.
 */
class MasjidDomainReservedInvariantTest extends TestCase
{
    use MakesStudioDomains;
    use RefreshDatabase;

    #[Test]
    public function a_reserved_row_cannot_change_status(): void
    {
        $org = $this->makeOrg();

        foreach (array_diff(MasjidDomain::STATUSES, [MasjidDomain::STATUS_RESERVED]) as $status) {
            $domain = $this->makeDomain($org, 'r-' . str_replace('_', '-', $status) . '.example.org', MasjidDomain::STATUS_RESERVED);

            // Cloudflare's verification and a confirmed serve, so `active`
            // and `manual` fail on the reserved rule and nothing else.
            $domain->fill([
                'status' => $status,
                'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE,
                'verified_at' => now(),
                'serving_confirmed_at' => now(),
            ]);

            try {
                $domain->save();
                $this->fail("a reserved row became {$status}");
            } catch (LogicException $e) {
                $this->assertStringContainsString('is reserved', $e->getMessage());
            }

            $this->assertSame(MasjidDomain::STATUS_RESERVED, $domain->fresh()->status);
        }

        $this->assertSame(0, MasjidDomain::query()->corsAdmitted()->count());
        $this->assertSame(0, MasjidDomain::query()->served()->count());

        // It can still be stamped as checked, and released by removing it.
        $held = MasjidDomain::query()->firstOrFail();
        $held->last_checked_at = now();
        $held->save();
        $this->assertNotNull($held->fresh()->last_checked_at);

        MasjidDomain::query()->get()->each->delete();
        $this->assertSame(0, MasjidDomain::query()->count());
    }
}
