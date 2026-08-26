<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\User;
use App\Support\ZakatDesignation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A TYPED DONOR NAME IS NOT THE SAME THING AS NO DONOR.
 *
 * MEASURED IN PRODUCTION 2026-08-26: donation 780, a $500 cheque (#1084) for
 * Burlington Masjid, recorded through the admin "Record gift" form with the
 * payer's name typed into the Donor box. The box was a typeahead over EXISTING
 * contacts; the typed string lived in a local ref, was never put in the request
 * under any key, and the row saved with contact_id NULL. The ledger then
 * rendered it "— (general)", which is the same thing it renders for a donation
 * box. The name existed nowhere in the database, the donor search could never
 * find the gift (the filter is a whereHas EXISTS join over contacts), and no
 * update route existed, so the mistake was permanent.
 *
 * Two claims are pinned here, and they are separate:
 *
 *   1. RECORDING never drops a name. A name that matches nobody CREATES the
 *      contact — a first-time cheque writer is a real donor, not an anonymous
 *      one — and a name that matches exactly one existing contact REUSES it,
 *      because the alternative is forking a household across seasons.
 *
 *   2. A RECORDED GIFT CAN BE CORRECTED, within limits that exist for a reason:
 *      never a Stripe row (the webhook owns those), and never the four facts a
 *      already-issued tax receipt states.
 */
class OfflineGiftDonorTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private Fund $fund;

    private User $admin;

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

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->makeMasjid();
        $this->fund = $this->makeFund($this->masjid, ['name' => 'Islamic School', 'type' => 'general']);
        $this->admin = $this->makeAdminFor($this->masjid);

        Sanctum::actingAs($this->admin);
    }

    // ===================== 1. recording never drops a name =====================

    #[Test]
    public function a_donor_who_is_not_in_the_directory_yet_is_created_rather_than_dropped(): void
    {
        // The production case, verbatim: a cheque from somebody the masjid has
        // never recorded before.
        $this->postJson($this->url(), [
            'fund_id' => $this->fund->id,
            'amount' => 500.00,
            'payment_method' => 'check',
            'check_number' => '1084',
            'donated_at' => '2026-08-26',
            'donor_name' => 'Huda M ElShershari',
            'note' => 'Chicago Fundraiser',
        ])->assertStatus(201);

        $contact = Contact::where('masjid_id', $this->masjid->id)
            ->where('first_name', 'Huda')
            ->first();

        $this->assertNotNull($contact, 'the typed donor name was dropped again');
        $this->assertSame('M ElShershari', $contact->last_name);

        // A real directory entry, not a placeholder — a placeholder would be
        // excluded from the year-end statement this gift belongs on.
        $this->assertFalse((bool) $contact->is_placeholder);

        $this->assertDatabaseHas('donations', [
            'check_number' => '1084',
            'contact_id' => $contact->id,
            'charged_amount' => 50000,
        ]);
    }

    #[Test]
    public function a_name_that_already_exists_is_reused_and_never_duplicated(): void
    {
        $existing = Contact::create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Ahmad',
            'last_name' => 'Fais',
        ]);

        // Case and spacing are the admin's typing, not the donor's identity.
        $this->postJson($this->url(), [
            'fund_id' => $this->fund->id,
            'amount' => 40.00,
            'payment_method' => 'cash',
            'donated_at' => '2026-08-26',
            'donor_name' => '  ahmad   FAIS ',
        ])->assertStatus(201);

        $this->assertSame(1, Contact::where('masjid_id', $this->masjid->id)->count(),
            'a second contact was forked for a donor who was already in the directory');

        $this->assertDatabaseHas('donations', ['contact_id' => $existing->id]);
    }

    #[Test]
    public function an_ambiguous_name_is_refused_rather_than_attributed_by_coin_flip(): void
    {
        // Two real people who share a name. Money attributed to the wrong one
        // lands on the wrong year-end statement, so the platform declines to
        // guess and says so.
        foreach ([1, 2] as $_) {
            Contact::create([
                'masjid_id' => $this->masjid->id,
                'first_name' => 'Mohammed',
                'last_name' => 'Ali',
            ]);
        }

        $this->postJson($this->url(), [
            'fund_id' => $this->fund->id,
            'amount' => 100.00,
            'payment_method' => 'cash',
            'donated_at' => '2026-08-26',
            'donor_name' => 'Mohammed Ali',
        ])->assertStatus(422)->assertJsonPath('status', 'error')
            ->assertJsonStructure(['errors' => ['donor_name']]);

        $this->assertSame(0, Donation::count(), 'an ambiguous donor still booked a gift');
    }

    #[Test]
    public function a_picked_contact_wins_over_a_typed_name(): void
    {
        $picked = Contact::create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Sultan',
            'last_name' => 'Ansari',
        ]);

        $this->postJson($this->url(), [
            'fund_id' => $this->fund->id,
            'amount' => 40.00,
            'payment_method' => 'cash',
            'donated_at' => '2026-08-26',
            'contact_id' => $picked->id,
            'donor_name' => 'Somebody Else Entirely',
        ])->assertStatus(201);

        $this->assertDatabaseHas('donations', ['contact_id' => $picked->id]);
        $this->assertSame(1, Contact::where('masjid_id', $this->masjid->id)->count());
    }

    #[Test]
    public function a_gift_with_no_donor_at_all_is_still_a_general_gift(): void
    {
        // The legitimately anonymous case must survive the fix: a donation box
        // has no donor and never did.
        $this->postJson($this->url(), [
            'fund_id' => $this->fund->id,
            'amount' => 954.00,
            'payment_method' => 'cash',
            'donated_at' => '2026-08-06',
            'note' => 'Donation Box',
        ])->assertStatus(201);

        $this->assertDatabaseHas('donations', ['note' => 'Donation Box', 'contact_id' => null]);
        $this->assertSame(0, Contact::where('masjid_id', $this->masjid->id)->count(),
            'an anonymous gift invented a contact');
    }

    // ===================== 2. a recorded gift can be fixed =====================

    #[Test]
    public function the_donor_can_be_attached_to_a_gift_that_was_recorded_without_one(): void
    {
        // Exactly the remediation donation 780 needed.
        $donation = $this->recordGift(['note' => 'Chicago Fundraiser']);

        $this->assertNull($donation->contact_id);

        $this->putJson($this->url($donation->id), [
            'donor_name' => 'Huda M ElShershari',
        ])->assertStatus(200);

        $donation->refresh();

        $this->assertNotNull($donation->contact_id);
        $this->assertSame('Huda', $donation->contact->first_name);

        // An edit that only names the donor must not blank everything else.
        $this->assertSame('Chicago Fundraiser', $donation->note);
        $this->assertSame(50000, (int) $donation->charged_amount);
    }

    #[Test]
    public function an_edit_can_put_a_gift_back_to_general(): void
    {
        $contact = Contact::create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Wrongly',
            'last_name' => 'Attributed',
        ]);

        $donation = $this->recordGift(['contact_id' => $contact->id]);

        $this->putJson($this->url($donation->id), ['contact_id' => null])->assertStatus(200);

        $this->assertNull($donation->refresh()->contact_id);
    }

    #[Test]
    public function the_amount_fund_and_date_can_be_corrected(): void
    {
        $other = $this->makeFund($this->masjid, ['name' => 'Cemetery', 'type' => 'general']);
        $donation = $this->recordGift();

        $this->putJson($this->url($donation->id), [
            'amount' => 250.50,
            'fund_id' => $other->id,
            'donated_at' => '2026-08-25',
            'payment_method' => 'cash',
        ])->assertStatus(200);

        $donation->refresh();

        $this->assertSame(25050, (int) $donation->charged_amount);
        $this->assertSame(25050, (int) $donation->intended_amount);
        $this->assertSame($other->id, $donation->fund_id);
        $this->assertStringStartsWith('2026-08-25', (string) $donation->donated_at);

        // A cheque number belongs to a cheque; switching to cash drops it.
        $this->assertNull($donation->check_number);
    }

    #[Test]
    public function a_stripe_gift_cannot_be_edited_here(): void
    {
        $donation = $this->recordGift();
        $donation->forceFill([
            'source' => 'stripe',
            'stripe_payment_intent_id' => 'pi_test_123',
        ])->save();

        $this->putJson($this->url($donation->id), ['donor_name' => 'Anybody'])
            ->assertStatus(422);

        $this->assertNull($donation->refresh()->contact_id);
    }

    #[Test]
    public function a_receipted_gift_freezes_the_facts_the_receipt_states(): void
    {
        $donation = $this->recordGift();

        $this->postJson($this->url($donation->id).'/receipt')->assertStatus(201);

        $serial = $donation->refresh()->receipt->serial_number;

        // Donor, fund, amount and date are on a tax document in the donor's hands.
        $this->putJson($this->url($donation->id), ['amount' => 999.00])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->putJson($this->url($donation->id), ['donor_name' => 'Someone New'])
            ->assertStatus(422);

        $this->assertSame(50000, (int) $donation->refresh()->charged_amount);
        $this->assertNotEmpty($serial);

        // What the receipt does NOT assert stays correctable.
        $this->putJson($this->url($donation->id), ['note' => 'cleared 2026-09-02'])
            ->assertStatus(200);

        $this->assertSame('cleared 2026-09-02', $donation->refresh()->note);
    }

    #[Test]
    public function editing_a_receipted_gift_to_the_values_it_already_has_is_not_a_refusal(): void
    {
        // The edit form posts every field. Re-sending unchanged values must not
        // read as an attempt to alter the receipt.
        $donation = $this->recordGift();
        $this->postJson($this->url($donation->id).'/receipt')->assertStatus(201);

        $this->putJson($this->url($donation->id), [
            'fund_id' => $this->fund->id,
            'amount' => 500.00,
            'donated_at' => '2026-08-26',
            'payment_method' => 'check',
            'check_number' => '1084',
            'note' => 'still fine',
        ])->assertStatus(200);

        $this->assertSame('still fine', $donation->refresh()->note);
    }

    #[Test]
    public function an_explicit_zakat_declaration_survives_a_fund_change(): void
    {
        // The giver said "this is zakat". Moving the gift between buckets is
        // bookkeeping and must not overwrite what the giver restricted.
        $donation = $this->recordGift(['zakat' => true]);

        $this->assertTrue((bool) $donation->is_zakat);
        $this->assertSame(ZakatDesignation::SOURCE_ADMIN, $donation->zakat_source);

        $general = $this->makeFund($this->masjid, ['name' => 'Cemetery', 'type' => 'general']);

        $this->putJson($this->url($donation->id), ['fund_id' => $general->id])->assertStatus(200);

        $this->assertTrue((bool) $donation->refresh()->is_zakat,
            'an explicit zakat declaration was silently erased by a fund change');
    }

    #[Test]
    public function editing_requires_permission_to_manage_donations(): void
    {
        $donation = $this->recordGift();

        $viewer = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);
        Sanctum::actingAs($viewer);

        $this->putJson($this->url($donation->id), ['donor_name' => 'Intruder'])
            ->assertStatus(403);

        $this->assertNull($donation->refresh()->contact_id);
    }

    // ================================ helpers ================================

    private function url(?int $donationId = null): string
    {
        $base = "/api/admin/masjids/{$this->masjid->id}/donations";

        return $donationId ? "{$base}/{$donationId}" : $base;
    }

    /** Record the production gift: $500, cheque #1084, no donor. */
    private function recordGift(array $overrides = []): Donation
    {
        $this->postJson($this->url(), array_merge([
            'fund_id' => $this->fund->id,
            'amount' => 500.00,
            'payment_method' => 'check',
            'check_number' => '1084',
            'donated_at' => '2026-08-26',
        ], $overrides))->assertStatus(201);

        return Donation::latest('id')->firstOrFail();
    }

    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Gift Test Masjid '.uniqid(),
            'email' => 'masjid-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            'stripe_account_id' => 'acct_G',
            'stripe_charges_enabled' => true,
        ], $overrides));
    }

    private function makeFund(Masjid $masjid, array $overrides = []): Fund
    {
        return Fund::create(array_merge([
            'masjid_id' => $masjid->id,
            'name' => 'General Fund',
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
        ], $overrides));
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }
}
