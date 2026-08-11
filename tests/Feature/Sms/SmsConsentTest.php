<?php

namespace Tests\Feature\Sms;

use App\Models\Contact;
use App\Models\Masjid;
use App\Models\SmsSuppression;
use App\Models\User;
use App\Services\Sms\SmsConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Consent: how it is recorded, how it is withdrawn, and the two ways it is NOT
 * allowed to be resurrected (T-009).
 *
 * The suppression list is the part worth reading. Everything in this file is
 * ultimately one claim: an opt-out cannot be defeated by editing the contact
 * directory. Not by deleting the contact, not by re-importing them, not by
 * merging them into a fresh record, and not by an admin ticking a box.
 */
class SmsConsentTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
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

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();
    }

    private function contact(array $attributes = []): Contact
    {
        return Contact::withoutMasjidScope()->create(array_merge([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'phone' => '+16135550111',
        ], $attributes));
    }

    private function consentUrl(Contact $contact): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/contacts/{$contact->id}/sms-consent";
    }

    // ---------- recording consent ----------

    #[Test]
    public function recording_consent_stores_when_how_and_on_what_evidence(): void
    {
        $contact = $this->contact();

        Sanctum::actingAs($this->admin);

        $this->postJson($this->consentUrl($contact), [
            'source' => 'paper_form',
            'evidence' => 'signed 2026-03-04 registration packet',
        ])->assertStatus(200);

        $contact->refresh();

        $this->assertTrue($contact->sms_opt_in);
        $this->assertNotNull($contact->sms_consent_at);
        $this->assertSame('paper_form', $contact->sms_consent_source);
        $this->assertSame('signed 2026-03-04 registration packet', $contact->sms_consent_evidence);
        $this->assertTrue($contact->hasSmsConsent());
    }

    #[Test]
    public function consent_cannot_be_recorded_without_saying_how_it_was_obtained(): void
    {
        $contact = $this->contact();

        Sanctum::actingAs($this->admin);

        $this->postJson($this->consentUrl($contact), [])->assertStatus(422);

        $this->assertFalse($contact->fresh()->hasSmsConsent());
    }

    #[Test]
    public function an_admin_cannot_claim_the_subscriber_texted_start(): void
    {
        $contact = $this->contact();

        Sanctum::actingAs($this->admin);

        // Only the inbound webhook may write this source: it means the person
        // texted START from their own handset.
        $this->postJson($this->consentUrl($contact), ['source' => 'sms_reply_start'])
            ->assertStatus(422);
    }

    #[Test]
    public function consent_cannot_be_recorded_for_a_contact_with_no_usable_number(): void
    {
        $contact = $this->contact(['phone' => null]);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->consentUrl($contact), ['source' => 'in_person'])
            ->assertStatus(422);
    }

    #[Test]
    public function another_tenants_contact_is_a_404_not_a_403(): void
    {
        $otherMasjid = Masjid::create([
            'name' => 'Other Masjid',
            'email' => 'other-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '2 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        $theirs = Contact::withoutMasjidScope()->create([
            'masjid_id' => $otherMasjid->id,
            'first_name' => 'Their',
            'last_name' => 'Contact',
            'phone' => '+16135559999',
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/contacts/{$theirs->id}/sms-consent",
            ['source' => 'web_form'],
        )->assertStatus(404);
    }

    // ---------- withdrawal, and the durable list ----------

    #[Test]
    public function withdrawing_consent_writes_the_durable_suppression_list(): void
    {
        $contact = $this->contact([
            'sms_opt_in' => true,
            'sms_consent_at' => Carbon::now(),
            'sms_consent_source' => 'web_form',
        ]);

        Sanctum::actingAs($this->admin);

        $this->deleteJson($this->consentUrl($contact))->assertStatus(200);

        $contact->refresh();
        $this->assertFalse($contact->sms_opt_in);
        $this->assertNotNull($contact->sms_opted_out_at);

        // The point: it is also on a row that does not belong to the contact.
        $this->assertDatabaseHas('sms_suppressions', [
            'masjid_id' => $this->masjid->id,
            'phone_e164' => '+16135550111',
            'released_at' => null,
        ]);
    }

    #[Test]
    public function the_suppression_outlives_the_contact_being_force_deleted(): void
    {
        $contact = $this->contact();
        app(SmsConsentService::class)->withdraw($contact);

        // The merge flow's force-delete, or a hard purge.
        $contact->forceDelete();

        $this->assertDatabaseCount('sms_suppressions', 1);
        $this->assertTrue(
            app(SmsConsentService::class)->isSuppressed($this->masjid->id, '+16135550111')
        );
    }

    #[Test]
    public function a_re_imported_contact_with_the_same_number_still_cannot_be_consented(): void
    {
        $original = $this->contact();
        app(SmsConsentService::class)->withdraw($original);
        $original->forceDelete();

        // Somebody re-adds the same person next month, differently punctuated.
        $reimported = $this->contact(['phone' => '(613) 555-0111']);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->consentUrl($reimported), ['source' => 'web_form'])
            ->assertStatus(422)
            ->assertJsonFragment([
                'status' => 'error',
            ]);

        $this->assertFalse($reimported->fresh()->hasSmsConsent());
    }

    #[Test]
    public function only_the_subscriber_can_undo_their_own_opt_out(): void
    {
        $contact = $this->contact();
        app(SmsConsentService::class)->withdraw($contact);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson($this->consentUrl($contact), ['source' => 'in_person'])
            ->assertStatus(422);

        $this->assertStringContainsString('texting START', $response->json('message'));
    }

    // ---------- merge ----------

    #[Test]
    public function merging_carries_the_opt_out_onto_the_survivor_when_the_number_matches(): void
    {
        $target = $this->contact([
            'first_name' => 'Real',
            'phone' => '+16135550111',
            'sms_opt_in' => true,
            'sms_consent_at' => Carbon::now(),
            'sms_consent_source' => 'web_form',
        ]);

        $source = $this->contact([
            'first_name' => 'Placeholder',
            'phone' => '(613) 555-0111',
            'sms_opted_out_at' => Carbon::now()->subMonth(),
            'is_placeholder' => true,
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/contacts/{$source->id}/merge",
            ['target_contact_id' => $target->id],
        )->assertStatus(200);

        $target->refresh();

        // The more restrictive state won, even though the survivor had consent.
        $this->assertFalse($target->sms_opt_in);
        $this->assertNotNull($target->sms_opted_out_at);
        $this->assertFalse($target->hasSmsConsent());
    }

    #[Test]
    public function merging_does_not_transplant_consent_onto_a_different_number(): void
    {
        $target = $this->contact(['first_name' => 'Real', 'phone' => '+16135550222']);

        $source = $this->contact([
            'first_name' => 'Placeholder',
            'phone' => '+16135550111',
            'sms_opt_in' => true,
            'sms_consent_at' => Carbon::now(),
            'sms_consent_source' => 'web_form',
            'is_placeholder' => true,
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/contacts/{$source->id}/merge",
            ['target_contact_id' => $target->id],
        )->assertStatus(200);

        // Consent was given for the SOURCE's number. The survivor carries a
        // different one, so it did not move — texting it would be permission
        // nobody gave.
        $this->assertFalse($target->fresh()->hasSmsConsent());
    }

    #[Test]
    public function merging_moves_consent_with_its_original_provenance_when_the_number_matches(): void
    {
        $consentedAt = Carbon::now()->subMonths(6)->startOfSecond();

        $target = $this->contact(['first_name' => 'Real', 'phone' => '+16135550111']);

        $source = $this->contact([
            'first_name' => 'Placeholder',
            'phone' => '+1 613-555-0111',
            'sms_opt_in' => true,
            'sms_consent_at' => $consentedAt,
            'sms_consent_source' => 'paper_form',
            'sms_consent_evidence' => 'packet #7',
            'is_placeholder' => true,
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/contacts/{$source->id}/merge",
            ['target_contact_id' => $target->id],
        )->assertStatus(200);

        $target->refresh();

        $this->assertTrue($target->hasSmsConsent());
        // A merge is not a new act of consent: the original date and source
        // survive rather than being re-stamped "now".
        $this->assertSame($consentedAt->toDateTimeString(), $target->sms_consent_at->toDateTimeString());
        $this->assertSame('paper_form', $target->sms_consent_source);
        $this->assertSame('packet #7', $target->sms_consent_evidence);
    }

    #[Test]
    public function a_merge_can_never_produce_a_messageable_record_for_a_suppressed_number(): void
    {
        SmsSuppression::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'phone_e164' => '+16135550111',
            'suppressed_at' => Carbon::now(),
        ]);

        $target = $this->contact([
            'first_name' => 'Real',
            'phone' => '+16135550111',
            'sms_opt_in' => true,
            'sms_consent_at' => Carbon::now(),
            'sms_consent_source' => 'web_form',
        ]);

        $source = $this->contact(['first_name' => 'Placeholder', 'phone' => '+16135550111']);

        Sanctum::actingAs($this->admin);

        $this->postJson(
            "/api/admin/masjids/{$this->masjid->id}/contacts/{$source->id}/merge",
            ['target_contact_id' => $target->id],
        )->assertStatus(200);

        $this->assertFalse($target->fresh()->hasSmsConsent());
    }
}
