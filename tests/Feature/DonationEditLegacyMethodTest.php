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
 *
 * They also pin the contract the form's blank method rests on, which is the same
 * question from the other end: an OMITTED method leaves the recorded one alone,
 * and a gift being recorded for the first time must state one. Between them,
 * nothing on this path can write a payment method nobody chose — the value a tax
 * receipt later snapshots and can no longer be corrected.
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

    #[Test]
    public function an_edit_that_states_no_method_leaves_the_recorded_one_alone(): void
    {
        // The third way the form can behave, and the one it now uses: it neither
        // resubmits a value it cannot display nor invents one, it OMITS the key —
        // and `sometimes` then means "leave it alone".
        //
        // This is what lets the picker sit blank on a gift whose method was never
        // captured. Without it the form is stuck between two bad options: block a
        // note-only correction behind a required field, or make the treasurer
        // assert how money they never saw arrived — an assertion that a receipt
        // issued later would freeze onto the donor's tax document.
        $donation = $this->legacyGift();

        $this->putJson($this->url($donation), [
            'fund_id' => $this->school->id,
            'note' => 'Reconciled against the March deposit.',
        ])->assertOk();

        $donation->refresh();
        $this->assertSame($this->school->id, (int) $donation->fund_id, 'the fund change went through');
        $this->assertSame('Reconciled against the March deposit.', $donation->note);
        // Untouched: not healed to 'other', and above all not invented as 'cash'.
        $this->assertSame('unknown', $donation->payment_method);
    }

    #[Test]
    public function recording_a_gift_still_demands_a_stated_method(): void
    {
        // The other side of the same contract. Omission means "leave alone" only
        // because there IS something to leave alone; on a new gift there is
        // nothing, so the method has to be stated. The form used to hide this by
        // opening the picker on "cash" with no placeholder, which is how a cheque
        // came to be booked — and receipted — as cash.
        $this->postJson("/api/admin/masjids/{$this->masjid->id}/donations", [
            'fund_id' => $this->general->id,
            'amount' => 5000.00,
            'donated_at' => '2026-03-01',
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['data' => ['payment_method']]);

        $this->assertSame(0, Donation::withoutGlobalScopes()->count());
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
