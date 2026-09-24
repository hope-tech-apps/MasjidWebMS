<?php

namespace Tests\Feature\Studio;

use App\Models\MasjidDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\MakesStudioDomains;
use Tests\TestCase;

/**
 * The two readers of `masjid_domains` get two different answers on purpose
 * (R3): the lookup must answer for a host that is still being set up, and CORS
 * must trust only a host we have seen serving our own site.
 */
class MasjidDomainScopesTest extends TestCase
{
    use MakesStudioDomains;
    use RefreshDatabase;

    private function served(): array
    {
        return MasjidDomain::query()->served()->orderBy('host')->pluck('host')->all();
    }

    private function admitted(): array
    {
        return MasjidDomain::query()->corsAdmitted()->orderBy('host')->pluck('host')->all();
    }

    #[Test]
    public function served_includes_pending_for_the_lookup_but_cors_admitted_does_not(): void
    {
        $org = $this->makeOrg();

        foreach (MasjidDomain::NON_TERMINAL as $status) {
            // Even a confirmed timestamp does not admit a host still being set up.
            $this->makeDomain($org, str_replace('_', '-', $status) . '.example.org', $status, ['serving_confirmed_at' => now()]);
        }

        $this->assertSame(['awaiting-nameservers.example.org', 'pending.example.org', 'provisioning.example.org'], $this->served());
        $this->assertSame([], $this->admitted());
    }

    #[Test]
    public function an_active_row_is_not_cors_admitted_until_serving_is_confirmed(): void
    {
        $org = $this->makeOrg();
        $active = $this->makeDomain($org, 'active.example.org', MasjidDomain::STATUS_ACTIVE, [
            'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now(),
        ]);
        $manual = $this->makeDomain($org, 'manual.example.org', MasjidDomain::STATUS_MANUAL);

        $this->assertSame(['active.example.org', 'manual.example.org'], $this->served());
        $this->assertSame([], $this->admitted());

        $active->update(['serving_confirmed_at' => now()]);
        $this->assertSame(['active.example.org'], $this->admitted());

        $manual->update(['serving_confirmed_at' => now()]);
        $this->assertSame(['active.example.org', 'manual.example.org'], $this->admitted());
    }

    #[Test]
    public function failed_reserved_and_trashed_org_rows_are_in_neither_scope(): void
    {
        $org = $this->makeOrg();
        $this->makeDomain($org, 'failed.example.org', MasjidDomain::STATUS_FAILED, ['serving_confirmed_at' => now()]);
        $this->makeDomain($org, 'reserved.example.org', MasjidDomain::STATUS_RESERVED, ['serving_confirmed_at' => now()]);

        $gone = $this->makeOrg();
        $this->makeDomain($gone, 'gone.example.org', MasjidDomain::STATUS_ACTIVE, [
            'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now(), 'serving_confirmed_at' => now(),
        ]);
        $this->assertSame(['gone.example.org'], $this->admitted(), 'the live control row is missing');

        $gone->delete();

        $this->assertSame([], $this->served());
        $this->assertSame([], $this->admitted());
        $this->assertSame([], MasjidDomain::corsOrigins());
    }

    #[Test]
    public function cors_origins_equals_the_cors_admitted_hosts_exactly(): void
    {
        $org = $this->makeOrg();
        $this->makeDomain($org, 'pending.example.org');
        $this->makeDomain($org, 'reserved.example.org', MasjidDomain::STATUS_RESERVED);
        $this->makeDomain($org, 'unconfirmed.example.org', MasjidDomain::STATUS_MANUAL);
        $this->makeDomain($org, 'www.example.org', MasjidDomain::STATUS_MANUAL, ['serving_confirmed_at' => now()]);
        $this->makeDomain($org, 'app.example.org', MasjidDomain::STATUS_ACTIVE, [
            'verified_by' => MasjidDomain::VERIFIED_BY_CLOUDFLARE, 'verified_at' => now(), 'serving_confirmed_at' => now(),
        ]);

        $expected = array_map(fn (string $host) => 'https://' . $host, $this->admitted());

        $this->assertSame(['https://app.example.org', 'https://www.example.org'], $expected);
        $this->assertSame($expected, MasjidDomain::corsOrigins());

        // Cached under the one fixed key, and forgotten when a row changes.
        $this->assertSame($expected, Cache::get(MasjidDomain::CORS_ORIGINS_CACHE_KEY));

        $this->makeDomain($org, 'new.example.org', MasjidDomain::STATUS_MANUAL, ['serving_confirmed_at' => now()]);
        $this->assertNull(Cache::get(MasjidDomain::CORS_ORIGINS_CACHE_KEY));
        $this->assertContains('https://new.example.org', MasjidDomain::corsOrigins());

        MasjidDomain::query()->where('host', 'new.example.org')->first()->delete();
        $this->assertSame($expected, MasjidDomain::corsOrigins());
    }
}
