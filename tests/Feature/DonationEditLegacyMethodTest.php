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
 * Editing a manually-recorded gift must not be blocked by a legacy payment
 * method.
 *
 * A gift imported (or recorded before the method picker existed) can carry a
 * value the picker no longer offers — 'unknown' is the common one. The picker
 * shows blank and resubmits that stale value, which the `in:` rule rejects; and
 * because every other field is `sometimes`, that one bad method used to reject an
 * edit of a COMPLETELY unrelated field — a fund correction — with an error the
 * form did not surface. Measured on a real Burlington gift. These pin the fix.
 */
class DonationEditLegacyMethodTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private Fund $general;
    private Fund $school;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid '.uniqid(),
            'email' => 'm-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true,
        ]);
        $this->general = $this->makeFund('General Donation');
        $this->school = $this->makeFund('Islamic School');

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin', 'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        Sanctum::actingAs($this->admin);
    }

    #[Test]
    public function a_legacy_unknown_method_does_not_block_changing_the_fund(): void
    {
        $donation = $this->legacyGift();  // payment_method = 'unknown'

        // The broken form resubmits the stale, un-selectable value.
        $this->putJson($this->url($donation), [
            'fund_id' => $this->school->id,
            'payment_method' => 'unknown',
        ])->assertOk();

        $donation->refresh();
        $this->assertSame($this->school->id, (int) $donation->fund_id, 'the fund change went through');
        // The legacy value is healed to a real, selectable one.
        $this->assertSame('other', $donation->payment_method);
    }

    #[Test]
    public function a_null_method_does_not_block_changing_the_fund(): void
    {
        $donation = $this->legacyGift();

        $this->putJson($this->url($donation), [
            'fund_id' => $this->school->id,
            'payment_method' => null,
        ])->assertOk();

        $this->assertSame($this->school->id, (int) $donation->refresh()->fund_id);
    }

    #[Test]
    public function a_real_method_selection_still_updates_normally(): void
    {
        $donation = $this->legacyGift();

        $this->putJson($this->url($donation), [
            'fund_id' => $this->school->id,
            'payment_method' => 'check',
        ])->assertOk();

        $donation->refresh();
        $this->assertSame($this->school->id, (int) $donation->fund_id);
        // A valid choice is honoured, never overwritten by the heal.
        $this->assertSame('check', $donation->payment_method);
    }

    // ------------------------------------------------------------- helpers

    private function url(Donation $d): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/donations/{$d->id}";
    }

    private function makeFund(string $name): Fund
    {
        return Fund::create([
            'masjid_id' => $this->masjid->id, 'name' => $name,
            'type' => 'general', 'receiptable' => true, 'is_active' => true,
        ]);
    }

    /**
     * A hand-recorded gift whose stored method is the legacy 'unknown' — created
     * through the real store endpoint (so it is a valid row), then aged.
     */
    private function legacyGift(): Donation
    {
        $this->postJson("/api/admin/masjids/{$this->masjid->id}/donations", [
            'fund_id' => $this->general->id,
            'amount' => 2500.00,
            'payment_method' => 'cash',
            'donated_at' => '2026-03-01',
        ])->assertStatus(201);

        $donation = Donation::withoutGlobalScopes()->latest('id')->firstOrFail();
        // Age it: an imported/legacy row the picker can no longer represent.
        $donation->forceFill(['payment_method' => 'unknown'])->saveQuietly();

        return $donation;
    }
}
