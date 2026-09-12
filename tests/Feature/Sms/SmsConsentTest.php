<?php

namespace Tests\Feature\Sms;

use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\Masjid;
use App\Models\SmsSuppression;
use App\Models\User;
use App\Services\Broadcast\BroadcastAudienceResolver;
use App\Services\Sms\SmsConsentService;
use App\Support\TenantContext;
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
 *
 * Two more claims of the same kind were added once the admin panel made these
 * columns writable from a screen, because a screen that can write a legal record
 * is a screen that can destroy one:
 *
 *  - THE RECORD CANNOT BE REWRITTEN. A second "Record consent" is refused, and
 *    the date, source and evidence of the first one are still there afterwards.
 *  - THE RECORD DIES WITH THE NUMBER IT WAS GIVEN FOR. Saving a different phone
 *    number clears the consent claim — asserted on the columns, on the response
 *    the screen renders, and on the audience resolver that would otherwise have
 *    put that number on a message. Re-punctuating the SAME number changes
 *    nothing, which is the half that keeps the rule from being destructive.
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

    /** The ORDINARY contact endpoint — the one an office edits a phone number on. */
    private function contactUrl(Contact $contact): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/contacts/{$contact->id}";
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

    // ---------- the record cannot be rewritten ----------

    /**
     * THE ORIGINAL CONSENT SURVIVES A SECOND "Record consent".
     *
     * This is the whole point of the record. `sms_consent_at` is what proves the
     * person agreed BEFORE the messages that have already gone out; the source
     * and the evidence are what make that provable to somebody who was not
     * there. `grant()` used to forceFill all five columns unconditionally, so a
     * staff member pressing the button a year later to "fix the source" — with
     * the optional evidence box empty, which is how it is normally pressed —
     * re-dated the consent to today, replaced the source and set the evidence to
     * NULL. Nothing appended the prior values anywhere, and this panel is the
     * only writer in the application, so the act that actually happened simply
     * ceased to exist.
     *
     * The verb is refused. What is asserted below is not "the response is 422" —
     * it is that all four recorded values are still the ones that were recorded.
     */
    #[Test]
    public function a_second_grant_is_refused_and_the_first_consent_record_survives_it_intact(): void
    {
        $contact = $this->contact();

        Sanctum::actingAs($this->admin);

        $this->travelTo('2025-03-04 09:00:00');
        $this->postJson($this->consentUrl($contact), [
            'source' => 'paper_form',
            'evidence' => 'signed registration packet #118',
        ])->assertStatus(200);
        $this->travelBack();

        $original = $contact->fresh();

        // A year later, the same button, the evidence box left empty.
        $response = $this->postJson($this->consentUrl($contact), ['source' => 'in_person'])
            ->assertStatus(422);

        $this->assertStringContainsString('already on record', $response->json('message'));

        $after = $contact->fresh();

        $this->assertSame(
            $original->sms_consent_at->toDateTimeString(),
            $after->sms_consent_at->toDateTimeString(),
            'the date the person actually consented must not move',
        );
        $this->assertSame('paper_form', $after->sms_consent_source);
        $this->assertSame('signed registration packet #118', $after->sms_consent_evidence);
        $this->assertTrue($after->hasSmsConsent());
    }

    /**
     * The refusal is not a dead end, and this is the way through it.
     *
     * "Consent is already on record" would be an unhelpful wall if the honest
     * cases had nowhere to go. They do: a member who asked to stop is an
     * opt-out (a different verb, always available), and consent for a DIFFERENT
     * number is a first grant for that number — which is exactly what it
     * becomes once the new number is saved, because saving it retracts the claim
     * made for the old one.
     */
    #[Test]
    public function consent_can_be_recorded_again_once_the_member_carries_a_new_number(): void
    {
        $contact = $this->contact(['phone' => '+16135550111']);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->consentUrl($contact), ['source' => 'paper_form'])->assertStatus(200);
        $this->postJson($this->consentUrl($contact), ['source' => 'paper_form'])->assertStatus(422);

        $this->putJson($this->contactUrl($contact), [
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'phone' => '+16135550222',
        ])->assertStatus(200);

        $this->postJson($this->consentUrl($contact), [
            'source' => 'in_person',
            'evidence' => 'asked at the desk when they gave the new number',
        ])->assertStatus(200);

        $fresh = $contact->fresh();
        $this->assertTrue($fresh->hasSmsConsent());
        $this->assertSame('in_person', $fresh->sms_consent_source);
    }

    // ---------- consent belongs to a NUMBER ----------

    /**
     * EDITING THE PHONE NUMBER RETRACTS THE CONSENT RECORDED FOR THE OLD ONE.
     *
     * `SmsConsentService`'s fourth rule — consent belongs to a number, not to a
     * name — was enforced only on the merge path. The ordinary edit path, which
     * is the one an office uses every week, left all four columns in place: the
     * panel went on reading "Consented — Paper form they signed" while the row
     * carried a number that had never agreed to anything, and the resolver texted
     * it (it gates on `hasSmsConsent()` and never compares it to the number now
     * on the row).
     *
     * Pinned through the ordinary contacts endpoint, because that is the writer
     * the hazard travels on — but the guard itself is on the model, so an import
     * or a data fix that changes the same column is covered by the same rule.
     */
    #[Test]
    public function changing_a_members_phone_number_clears_the_consent_recorded_for_the_old_one(): void
    {
        $contact = $this->contact(['phone' => '+16135550142']);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->consentUrl($contact), [
            'source' => 'paper_form',
            'evidence' => 'signed registration packet #118',
        ])->assertStatus(200);

        $this->putJson($this->contactUrl($contact), [
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'phone' => '+16135559999',
        ])->assertStatus(200);

        $fresh = $contact->fresh();

        $this->assertFalse($fresh->hasSmsConsent(), 'the new number has consented to nothing');
        $this->assertFalse((bool) $fresh->sms_opt_in);
        $this->assertNull($fresh->sms_consent_at);
        $this->assertNull($fresh->sms_consent_source);
        $this->assertNull($fresh->sms_consent_evidence);
    }

    /**
     * …and the response the SPA renders says so in the same breath.
     *
     * `ContactsController::update` answers with the model it just saved, so a
     * screen that trusts the response cannot keep showing a consent record the
     * save has already retracted. If the hook ever moves to a second write, this
     * is the assertion that notices.
     */
    #[Test]
    public function the_update_response_shows_the_consent_already_cleared(): void
    {
        $contact = $this->contact(['phone' => '+16135550142']);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->consentUrl($contact), ['source' => 'web_form'])->assertStatus(200);

        $this->putJson($this->contactUrl($contact), [
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'phone' => '+16135559999',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.sms_opt_in', false)
            ->assertJsonPath('data.sms_consent_at', null)
            ->assertJsonPath('data.sms_consent_source', null)
            ->assertJsonPath('data.sms_consent_evidence', null);
    }

    /**
     * RE-PUNCTUATING THE SAME NUMBER IS NOT A CHANGE.
     *
     * The other half of the rule, and the reason the comparison is made on the
     * E.164 form rather than on the raw string. "(613) 555-0142" and
     * "+16135550142" reach the same handset; a guard that read the typed text
     * would throw away a real consent record every time somebody tidied the
     * directory's formatting, which would quietly shrink the audience and look
     * like nothing at all.
     */
    #[Test]
    public function reformatting_the_same_number_keeps_the_consent_record(): void
    {
        $contact = $this->contact(['phone' => '+16135550142']);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->consentUrl($contact), [
            'source' => 'paper_form',
            'evidence' => 'signed registration packet #118',
        ])->assertStatus(200);

        $recordedAt = $contact->fresh()->sms_consent_at->toDateTimeString();

        $this->putJson($this->contactUrl($contact), [
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'phone' => '(613) 555-0142',
        ])->assertStatus(200);

        $fresh = $contact->fresh();

        $this->assertTrue($fresh->hasSmsConsent());
        $this->assertSame($recordedAt, $fresh->sms_consent_at->toDateTimeString());
        $this->assertSame('signed registration packet #118', $fresh->sms_consent_evidence);
    }

    /**
     * An edit that does not touch the number cannot touch the consent either.
     *
     * The narrow version of the same guard: correcting a spelling must not cost
     * a member their place in the audience.
     */
    #[Test]
    public function editing_a_members_name_leaves_their_consent_alone(): void
    {
        $contact = $this->contact(['phone' => '+16135550142']);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->consentUrl($contact), ['source' => 'web_form'])->assertStatus(200);

        $this->putJson($this->contactUrl($contact), [
            'first_name' => 'Corrected',
            'last_name' => 'Spelling',
        ])->assertStatus(200);

        $this->assertTrue($contact->fresh()->hasSmsConsent());
    }

    /**
     * AND THE BROADCAST DOES NOT TEXT THE NEW NUMBER.
     *
     * The column-level assertions above are the mechanism; this is the harm.
     * `BroadcastAudienceResolver` is what actually puts a number on a message,
     * so the guarantee is only real if the resolver stops including the member —
     * and it must count them as "no recorded consent" rather than dropping them
     * silently, because the composer reports that number to the admin.
     */
    #[Test]
    public function a_broadcast_does_not_text_a_number_the_consent_was_not_given_for(): void
    {
        $contact = $this->contact(['phone' => '+16135550142']);

        Sanctum::actingAs($this->admin);

        $this->postJson($this->consentUrl($contact), ['source' => 'web_form'])->assertStatus(200);

        app(TenantContext::class)->set($this->masjid->id);

        $broadcast = (new Broadcast())->forceFill([
            'masjid_id' => $this->masjid->id,
            'audience' => 'everyone',
        ]);

        $before = app(BroadcastAudienceResolver::class)->smsRecipients($broadcast);
        $this->assertSame(
            [$contact->id],
            array_map(fn (array $row) => $row['contact']->id, $before->recipients),
        );

        Sanctum::actingAs($this->admin);
        $this->putJson($this->contactUrl($contact), [
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'phone' => '+16135559999',
        ])->assertStatus(200);

        app(TenantContext::class)->set($this->masjid->id);
        $after = app(BroadcastAudienceResolver::class)->smsRecipients($broadcast);

        $this->assertSame([], $after->recipients, 'the number nobody consented for is not texted');
        $this->assertSame(1, $after->withoutConsent, 'and the member is counted, not silently dropped');
    }

    /**
     * The member-directory screen renders the consent panel — the badge, the
     * date, the source and the evidence — out of the plain contact payload,
     * because `Contact::$hidden` is `['password']` only and the show endpoint
     * answers with the model's own `toArray()`.
     *
     * That is an INVISIBLE dependency: adding one of these columns to `$hidden`
     * would blank the panel without failing anything, and a consent record that
     * silently stops displaying reads on screen exactly like a consent record
     * that was never made. This pins the four keys the panel needs.
     */
    #[Test]
    public function the_contact_payload_the_directory_screen_reads_carries_the_consent_provenance(): void
    {
        $contact = $this->contact();

        Sanctum::actingAs($this->admin);

        $this->postJson($this->consentUrl($contact), [
            'source' => 'in_person',
            'evidence' => 'asked at the Friday registration desk',
        ])->assertStatus(200);

        $this->getJson("/api/admin/masjids/{$this->masjid->id}/contacts/{$contact->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.sms_opt_in', true)
            ->assertJsonPath('data.sms_consent_source', 'in_person')
            ->assertJsonPath('data.sms_consent_evidence', 'asked at the Friday registration desk')
            ->assertJsonPath('data.sms_opted_out_at', null)
            ->assertJsonStructure(['data' => ['sms_consent_at']]);
    }

    /**
     * And the OPT-OUT half of the same payload, which is the state the panel
     * must never render as "no consent": "never asked" and "asked us to stop"
     * are different facts, and only the second one disables the grant button.
     */
    #[Test]
    public function the_contact_payload_says_when_a_member_asked_to_stop(): void
    {
        $contact = $this->contact();

        Sanctum::actingAs($this->admin);

        $this->postJson($this->consentUrl($contact), ['source' => 'web_form'])->assertStatus(200);
        $this->deleteJson($this->consentUrl($contact))->assertStatus(200);

        $payload = $this->getJson("/api/admin/masjids/{$this->masjid->id}/contacts/{$contact->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.sms_opt_in', false)
            ->json('data');

        $this->assertNotNull($payload['sms_opted_out_at']);
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

    /**
     * …and the response SAYS the durable half happened.
     *
     * The endpoint answers 200 whether or not the suppression row was written,
     * so 200 alone has never been evidence of anything. `meta.durable` is what
     * the screen is allowed to base "this number will not receive text messages
     * from this organization again" on.
     */
    #[Test]
    public function a_withdrawal_reports_that_it_reached_the_durable_list(): void
    {
        $contact = $this->contact();

        Sanctum::actingAs($this->admin);

        $this->deleteJson($this->consentUrl($contact))
            ->assertStatus(200)
            ->assertJsonPath('meta.durable', true)
            ->assertJsonPath('meta.message', null);
    }

    /**
     * A WITHDRAWAL THAT COULD NOT BE MADE PERMANENT DOES NOT CLAIM TO BE.
     *
     * `withdraw()` writes the durable row only `if ($number = $contact
     * ->smsNumber())`, and `PhoneNumber` refuses to guess at a number carrying
     * letters, a missing area code or an unknown country — so for those contacts
     * the whole withdrawal lived on the contact row, which a merge force-deletes,
     * a re-import recreates and a delete-and-re-add loses. The endpoint answered
     * 200 and the screen said the number was on a permanent do-not-text list
     * that had never heard of it: the exact failure rule 3 exists to prevent,
     * announced as a success to the person recording it.
     *
     * The withdrawal is still RECORDED — refusing to hear "stop" because of a
     * malformed phone number would be worse. What changes is that the response
     * tells the truth about which half happened, and names the field to fix.
     */
    #[Test]
    public function an_opt_out_that_could_not_be_made_permanent_says_so_instead_of_claiming_it_was(): void
    {
        // Letters: PhoneNumber refuses rather than stripping it down to
        // something dialable, so there is no E.164 key to suppress.
        $contact = $this->contact(['phone' => '613-555-0142 ext 4']);
        $this->assertNull($contact->smsNumber());

        Sanctum::actingAs($this->admin);

        $response = $this->deleteJson($this->consentUrl($contact))
            ->assertStatus(200)
            ->assertJsonPath('meta.durable', false);

        $this->assertStringContainsString('do-not-text', $response->json('meta.message'));
        $this->assertStringContainsString('area code', $response->json('meta.message'));

        // The member's own record still carries the withdrawal…
        $fresh = $contact->fresh();
        $this->assertFalse((bool) $fresh->sms_opt_in);
        $this->assertNotNull($fresh->sms_opted_out_at);

        // …and nothing durable was written, which is the thing that was being
        // claimed and is now reported.
        $this->assertDatabaseCount('sms_suppressions', 0);
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
