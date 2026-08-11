<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Support\ZakatCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public zakat calculator (T-031).
 *
 * Two things are under test and only one of them is arithmetic. The other is the
 * honesty contract: with no metal price there is no threshold, so the endpoint
 * must report "unknown" rather than default, estimate, or quietly answer "you
 * owe nothing" — and every disputed position it took has to be in the payload
 * where the donor can see it.
 */
class ZakatCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

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

        // No configured metal price by default — the shipped state, and the one
        // the "unknown threshold" behaviour has to be correct for.
        config([
            'zakat.nisab.basis' => 'silver',
            'zakat.nisab.gold_grams' => 87.48,
            'zakat.nisab.silver_grams' => 612.36,
            'zakat.nisab.gold_price_per_gram_minor' => null,
            'zakat.nisab.silver_price_per_gram_minor' => null,
            'services.stripe.currency' => 'usd',
        ]);

        $this->masjid = Masjid::create([
            'name' => 'Calculator Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    // ============================== arithmetic ==============================

    #[Test]
    public function it_takes_two_and_a_half_percent_of_assets_minus_liabilities(): void
    {
        // $10,000 cash + $2,000 gold − $2,000 debt = $10,000 net; 2.5% = $250.
        $data = $this->calculate([
            'cash' => 1000000,
            'gold_value' => 200000,
            'debts_due' => 200000,
        ]);

        $this->assertSame(1200000, $data['assets']['total_minor']);
        $this->assertSame(200000, $data['liabilities']['total_minor']);
        $this->assertSame(1000000, $data['net_zakatable_wealth_minor']);
        $this->assertSame(25000, $data['zakat_at_rate_minor']);
        $this->assertSame('1/40', $data['rate']['fraction']);
        $this->assertSame(2.5, $data['rate']['percent']);
    }

    #[Test]
    public function a_fractional_cent_rounds_up_rather_than_leaving_a_shortfall(): void
    {
        // 101 / 40 = 2.525 cents. Rounding down would leave part of an
        // obligation unpaid; the cost of rounding up is half a cent.
        $this->assertSame(3, $this->calculate(['cash' => 101])['zakat_at_rate_minor']);
        // Exact multiples are untouched by the ceiling.
        $this->assertSame(1, $this->calculate(['cash' => 40])['zakat_at_rate_minor']);
    }

    #[Test]
    public function debts_larger_than_assets_produce_no_zakat_rather_than_a_negative(): void
    {
        $data = $this->calculate(['cash' => 100000, 'debts_due' => 500000]);

        $this->assertSame(0, $data['net_zakatable_wealth_minor']);
        $this->assertSame(0, $data['zakat_at_rate_minor']);
    }

    #[Test]
    public function every_bucket_is_reported_including_the_ones_left_blank(): void
    {
        // The breakdown IS the derivation the donor is owed; a bare total gives
        // them nothing to check their own figure against.
        $data = $this->calculate(['cash' => 100000]);

        foreach (ZakatCalculator::ASSET_KEYS as $key) {
            $this->assertArrayHasKey($key, $data['assets']['items']);
        }
        foreach (ZakatCalculator::LIABILITY_KEYS as $key) {
            $this->assertArrayHasKey($key, $data['liabilities']['items']);
        }
        $this->assertSame(0, $data['assets']['items']['investments']);
    }

    // ================================ nisab ================================

    #[Test]
    public function an_unknown_metal_price_reports_an_unknown_threshold_not_a_zero_bill(): void
    {
        $data = $this->calculate(['cash' => 100000000]);

        $this->assertNull($data['nisab']['threshold_minor']);
        $this->assertNull($data['nisab']['price_source']);
        // "We could not tell" — never false, which would read as "under the
        // threshold", and never 0 due, which would read as "you owe nothing".
        $this->assertNull($data['nisab']['meets_nisab']);
        $this->assertNull($data['zakat_due_minor']);
        // The arithmetic is still there; only the ruling is withheld.
        $this->assertSame(2500000, $data['zakat_at_rate_minor']);
    }

    #[Test]
    public function a_supplied_price_yields_a_threshold_and_a_verdict(): void
    {
        // Silver at $1.00/g: 612.36 g => $612.36 threshold.
        $data = $this->calculate([
            'cash' => 100000,
            'nisab_price_per_gram' => 100,
        ]);

        $this->assertSame('silver', $data['nisab']['basis']);
        $this->assertSame(61236, $data['nisab']['threshold_minor']);
        $this->assertSame('request', $data['nisab']['price_source']);
        $this->assertTrue($data['nisab']['meets_nisab']);
        $this->assertSame(2500, $data['zakat_due_minor']);
    }

    #[Test]
    public function wealth_under_the_threshold_owes_nothing(): void
    {
        $data = $this->calculate([
            'cash' => 50000,
            'nisab_price_per_gram' => 100,
        ]);

        $this->assertFalse($data['nisab']['meets_nisab']);
        // A real zero, distinguishable from the null above.
        $this->assertSame(0, $data['zakat_due_minor']);
        // And the arithmetic is still reported, so the donor can see how close
        // they were rather than just being told "no".
        $this->assertSame(1250, $data['zakat_at_rate_minor']);
    }

    #[Test]
    public function the_basis_defaults_to_silver_and_the_caller_may_choose_gold(): void
    {
        $this->assertSame('silver', $this->calculate(['cash' => 1])['nisab']['basis']);

        // Gold at $1.00/g: 87.48 g => $87.48 — a very different threshold from
        // the same price on the silver weight, which is the whole dispute.
        $gold = $this->calculate([
            'cash' => 1,
            'basis' => 'gold',
            'nisab_price_per_gram' => 100,
        ]);

        $this->assertSame('gold', $gold['nisab']['basis']);
        $this->assertSame(8748, $gold['nisab']['threshold_minor']);
    }

    #[Test]
    public function a_configured_price_is_used_and_labelled_as_such(): void
    {
        config(['zakat.nisab.silver_price_per_gram_minor' => 100]);

        $data = $this->calculate(['cash' => 100000]);

        $this->assertSame(61236, $data['nisab']['threshold_minor']);
        $this->assertSame('config', $data['nisab']['price_source']);
    }

    // ============================= transparency =============================

    #[Test]
    public function every_disputed_position_is_named_in_the_payload(): void
    {
        $keys = array_column($this->calculate(['cash' => 100000])['assumptions'], 'key');

        foreach ([
            'not_a_ruling',
            'nisab_basis',
            'nisab_weights',
            'metal_price_not_live',
            'rate_scope',
            'hawl_not_verified',
            'nisab_compared_to_net',
            'liabilities_as_entered',
            'holdings_valued_by_you',
            'rounding_up',
            'not_stored',
        ] as $expected) {
            $this->assertContains($expected, $keys, "assumption `{$expected}` must be stated");
        }
    }

    #[Test]
    public function the_stated_basis_assumption_names_the_alternative_the_caller_did_not_take(): void
    {
        $data = $this->calculate(['cash' => 1, 'basis' => 'gold']);

        $basisNote = collect($data['assumptions'])->firstWhere('key', 'nisab_basis')['statement'];

        // A donor on the gold basis has to be told silver exists and obliges
        // more people, or the tool has picked a side for them in silence.
        $this->assertStringContainsString('gold basis', $basisNote);
        $this->assertStringContainsString('silver', $basisNote);
    }

    #[Test]
    public function nothing_is_persisted(): void
    {
        $before = \Illuminate\Support\Facades\DB::table('donations')->count();

        $this->calculate(['cash' => 100000000, 'investments' => 500000000]);

        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('donations')->count());
    }

    // ============================== the endpoint ==============================

    #[Test]
    public function the_nisab_reference_is_available_without_declaring_any_wealth(): void
    {
        $data = $this->getJson('/api/v1/zakat/nisab?nisab_price_per_gram=100', [
            'masjid-id' => (string) $this->masjid->id,
        ])->assertOk()->json('data');

        $this->assertSame(61236, $data['nisab']['threshold_minor']);
        // No wealth was supplied, so no verdict is fabricated.
        $this->assertNull($data['nisab']['meets_nisab']);
        $this->assertNotEmpty($data['assumptions']);
    }

    #[Test]
    public function an_unusable_basis_is_rejected_rather_than_silently_defaulted(): void
    {
        $this->getJson('/api/v1/zakat/nisab?basis=platinum', [
            'masjid-id' => (string) $this->masjid->id,
        ])->assertStatus(422);

        $this->postJson('/api/v1/zakat/calculate', ['basis' => 'platinum'], [
            'masjid-id' => (string) $this->masjid->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function decimal_money_is_rejected_because_minor_units_are_the_contract(): void
    {
        $this->postJson('/api/v1/zakat/calculate', ['cash' => 1000.55], [
            'masjid-id' => (string) $this->masjid->id,
        ])->assertStatus(422)->assertJsonPath('status', 'failed');
    }

    #[Test]
    public function the_tenant_header_is_required_and_an_unknown_tenant_is_a_404(): void
    {
        $this->postJson('/api/v1/zakat/calculate', ['cash' => 100])
            ->assertStatus(400);

        $this->postJson('/api/v1/zakat/calculate', ['cash' => 100], [
            'masjid-id' => (string) ($this->masjid->id + 9999),
        ])->assertStatus(404);
    }

    // ================================ helper ================================

    /** @return array<string,mixed> */
    private function calculate(array $payload): array
    {
        return $this->postJson('/api/v1/zakat/calculate', $payload, [
            'masjid-id' => (string) $this->masjid->id,
        ])->assertOk()->json('data');
    }
}
