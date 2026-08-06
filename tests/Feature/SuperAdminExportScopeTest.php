<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Does a SuperAdmin's masjid-scoped read actually stay inside that masjid?
 *
 * A review flagged that `/masjids/{id}/donations/export` would hand a SuperAdmin
 * EVERY masjid's rows in a file named after one of them — reasoning from the
 * ResolveMasjidTenant class docblock, which said "SuperAdmin -> leave UNBOUND".
 * The code beneath that docblock had already stopped doing so: it binds the
 * context to the route's masjid whenever the route names one, and only leaves it
 * unbound for genuinely cross-masjid routes (the masjid list, the portal).
 *
 * The docblock, not the behaviour, was the bug — but "the comment is wrong" is
 * exactly the kind of claim that should be settled by the HTTP stack rather than
 * by reading, because the answer depends on middleware order and on the global
 * scope firing. So these go through the real routes, with real auth, and assert
 * on bytes and row counts.
 *
 * Sqlite-in-memory, mirroring AppProvisioningTest.
 */
class SuperAdminExportScopeTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;

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

        $this->masjidA = $this->makeMasjid('Masjid A');
        $this->masjidB = $this->makeMasjid('Masjid B');

        // Both masjids need the CRM on: the donations routes sit behind the `crm`
        // gate, and a 403 there would make this suite pass for the wrong reason.
        foreach ([$this->masjidA, $this->masjidB] as $masjid) {
            $masjid->forceFill(['crm_enabled' => true])->save();
        }

        // Amounts are distinctive so a leak is unmistakable in the CSV body
        // rather than something inferred from a count.
        $this->makeGift($this->masjidA, 'Fund A', 11_11);
        $this->makeGift($this->masjidA, 'Fund A', 22_22);
        $this->makeGift($this->masjidB, 'Fund B', 99_99);
    }

    private function makeMasjid(string $name): Masjid
    {
        return Masjid::create([
            'name' => $name . ' ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'timezone' => 'America/New_York',
        ]);
    }

    private function makeGift(Masjid $masjid, string $fundName, int $cents): Donation
    {
        $fund = Fund::withoutMasjidScope()->firstOrCreate(
            ['masjid_id' => $masjid->id, 'name' => $fundName],
            ['type' => 'general', 'is_active' => true],
        );

        return Donation::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'fund_id' => $fund->id,
            'type' => 'one_time',
            'source' => 'offline',
            'payment_method' => 'cash',
            'donated_at' => '2026-08-01',
            'intended_amount' => $cents,
            'charged_amount' => $cents,
            'currency' => 'usd',
            'donor_covers_fees' => false,
            'status' => 'succeeded',
            'idempotency_key' => 'test_' . uniqid(),
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    #[Test]
    public function a_superadmins_export_contains_only_the_masjid_named_in_the_url(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $res = $this->get("/api/admin/masjids/{$this->masjidA->id}/donations/export");
        $res->assertOk();

        $csv = $res->streamedContent();

        // Masjid A's two gifts are present...
        $this->assertStringContainsString('11.11', $csv);
        $this->assertStringContainsString('22.22', $csv);
        // ...and B's is not. This is the actual leak assertion.
        $this->assertStringNotContainsString('99.99', $csv);

        // Header + exactly two data rows, so nothing arrives that the amount
        // assertions above happen not to name.
        $rows = array_values(array_filter(explode("\n", trim($csv)), fn ($l) => trim($l) !== ''));
        $this->assertCount(3, $rows, 'expected a header and exactly 2 gift rows');
    }

    #[Test]
    public function a_superadmins_ledger_is_scoped_the_same_way_as_the_export(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $res = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations");

        $res->assertOk()->assertJsonPath('data.total', 2);
    }

    #[Test]
    public function a_superadmins_stats_header_is_scoped_the_same_way_too(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $res = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/donations/stats/summary");
        $res->assertOk();

        // 11.11 + 22.22 = 33.33 — B's 99.99 must not be in the total.
        $this->assertSame(33_33, $res->json('data.all_time.gross_cents'));
    }

    #[Test]
    public function every_masjid_scoped_admin_route_spells_the_param_masjid_id(): void
    {
        // ResolveMasjidTenant binds from $request->route('masjid_id') — the literal
        // name. A route declared with {masjid} or {mosque_id} would still look
        // masjid-scoped to a reader, but would leave a SuperAdmin UNBOUND and
        // therefore unfiltered. That is the one way the leak reported in review
        // could actually be introduced, so pin the spelling rather than trusting
        // that everyone keeps typing it the same way.
        $offenders = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin/')) {
                continue;
            }
            foreach ($route->parameterNames() as $param) {
                if ($param !== 'masjid_id' && str_contains(strtolower($param), 'masjid')) {
                    $offenders[] = $route->uri() . ' -> {' . $param . '}';
                }
            }
        }

        $this->assertSame([], $offenders, "Masjid-scoped admin routes must name the param 'masjid_id' "
            . 'or ResolveMasjidTenant will not bind the tenant: ' . implode(', ', $offenders));
    }

    #[Test]
    public function switching_the_url_switches_the_masjid_rather_than_widening_it(): void
    {
        Sanctum::actingAs($this->superAdmin());

        // The same SuperAdmin, same session, pointed at B: they must see B's
        // gift and NOT A's. This is what makes the binding a scope rather than
        // a filter that only ever narrows the first request.
        $res = $this->get("/api/admin/masjids/{$this->masjidB->id}/donations/export");
        $res->assertOk();

        $csv = $res->streamedContent();
        $this->assertStringContainsString('99.99', $csv);
        $this->assertStringNotContainsString('11.11', $csv);
    }
}
