<?php

namespace Tests\Feature\Sms;

use App\Models\Contact;
use App\Models\Masjid;
use App\Models\MasjidSmsSender;
use App\Models\SmsSuppression;
use App\Services\Sms\TwilioSignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The inbound STOP/START webhook (T-009).
 *
 * Two properties, and the second is the one that is easy to get wrong:
 *
 *  1. STOP is honoured immediately and permanently, and it is written to a table
 *     that outlives the contact row.
 *  2. The endpoint FAILS CLOSED. With no signing secret configured it accepts
 *     nothing at all — not even an opt-out. That asymmetry looks unhelpful until
 *     you notice the endpoint also accepts opt-IN keywords: an unverified
 *     version of it would let anybody re-subscribe a number that opted out,
 *     turning our own compliance machinery into the violation.
 */
class SmsWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-auth-token';
    private const URL = 'https://manara.test/api/sms/webhook';

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

        config([
            'services.twilio.auth_token' => self::TOKEN,
            // Pin the URL the signature is computed against, the way a
            // deployment behind a TLS-terminating proxy has to.
            'services.twilio.webhook_url' => self::URL,
        ]);

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        MasjidSmsSender::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'phone_number' => '+16135550100',
            'registration_status' => MasjidSmsSender::STATUS_APPROVED,
            'approved_at' => Carbon::now(),
        ]);
    }

    /** @param array<string, string> $params */
    private function deliver(array $params, ?string $signature = null)
    {
        $signature = $signature ?? app(TwilioSignatureVerifier::class)
            ->expected(self::URL, $params, self::TOKEN);

        return $this->call(
            'POST',
            '/api/sms/webhook',
            $params,
            [],
            [],
            ['HTTP_X_TWILIO_SIGNATURE' => $signature],
        );
    }

    /** @return array<string, string> */
    private function inbound(string $body, string $from = '+16135550111'): array
    {
        return [
            'MessageSid' => 'SM' . uniqid(),
            'From' => $from,
            'To' => '+16135550100',
            'Body' => $body,
        ];
    }

    private function consentingContact(string $phone = '+16135550111'): Contact
    {
        return Contact::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'phone' => $phone,
            'sms_opt_in' => true,
            'sms_consent_at' => Carbon::now()->subDay(),
            'sms_consent_source' => 'web_form',
        ]);
    }

    // ---------- fail closed ----------

    #[Test]
    public function it_rejects_everything_when_no_signing_secret_is_configured(): void
    {
        config(['services.twilio.auth_token' => null]);

        $this->deliver($this->inbound('STOP'), signature: 'anything')->assertStatus(401);

        $this->assertDatabaseCount('sms_suppressions', 0);
    }

    #[Test]
    public function it_rejects_an_unsigned_request(): void
    {
        $this->call('POST', '/api/sms/webhook', $this->inbound('STOP'))
            ->assertStatus(401);
    }

    #[Test]
    public function it_rejects_a_tampered_payload(): void
    {
        $params = $this->inbound('STOP');

        // A signature computed for a DIFFERENT body than the one delivered.
        $signature = app(TwilioSignatureVerifier::class)
            ->expected(self::URL, $this->inbound('HELP'), self::TOKEN);

        $this->deliver($params, $signature)->assertStatus(401);

        $this->assertDatabaseCount('sms_suppressions', 0);
    }

    #[Test]
    public function it_rejects_a_signature_made_with_the_wrong_secret(): void
    {
        $params = $this->inbound('STOP');

        $this->deliver($params, app(TwilioSignatureVerifier::class)
            ->expected(self::URL, $params, 'not-our-token'))->assertStatus(401);
    }

    // ---------- STOP ----------

    #[Test]
    public function a_stop_keyword_suppresses_the_number_and_opts_the_contact_out(): void
    {
        $contact = $this->consentingContact();

        $this->deliver($this->inbound('STOP'))->assertStatus(200);

        $this->assertDatabaseHas('sms_suppressions', [
            'masjid_id' => $this->masjid->id,
            'phone_e164' => '+16135550111',
            'keyword' => 'STOP',
            'released_at' => null,
        ]);

        $contact->refresh();
        $this->assertFalse($contact->sms_opt_in);
        $this->assertNotNull($contact->sms_opted_out_at);
        $this->assertFalse($contact->hasSmsConsent());
    }

    #[Test]
    public function every_carrier_stop_keyword_is_honoured(): void
    {
        foreach (SmsSuppression::STOP_KEYWORDS as $index => $keyword) {
            $number = '+161355502' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);

            $this->deliver($this->inbound($keyword, $number))->assertStatus(200);

            $this->assertDatabaseHas('sms_suppressions', [
                'phone_e164' => $number,
                'keyword' => $keyword,
            ]);
        }
    }

    #[Test]
    public function the_keyword_is_matched_on_the_whole_message_not_as_a_substring(): void
    {
        $this->consentingContact();

        // Enthusiasm, not a control keyword. Suppressing here would silence
        // somebody who asked for MORE messages.
        $this->deliver($this->inbound('please do not stop the announcements'))->assertStatus(200);

        $this->assertDatabaseCount('sms_suppressions', 0);
    }

    #[Test]
    public function a_repeated_stop_updates_the_one_row_rather_than_duplicating_it(): void
    {
        $this->deliver($this->inbound('STOP'))->assertStatus(200);
        $this->deliver($this->inbound('CANCEL'))->assertStatus(200);

        // Provider retries and second thoughts both land on the same row.
        $this->assertDatabaseCount('sms_suppressions', 1);
        $this->assertDatabaseHas('sms_suppressions', ['keyword' => 'CANCEL', 'released_at' => null]);
    }

    #[Test]
    public function a_stop_is_recorded_even_when_no_contact_carries_that_number(): void
    {
        // Nobody in the directory has this number — they may have been deleted,
        // or the number may have been forwarded. The opt-out still stands.
        $this->deliver($this->inbound('STOP', '+16135559999'))->assertStatus(200);

        $this->assertDatabaseHas('sms_suppressions', ['phone_e164' => '+16135559999']);
    }

    // ---------- START ----------

    #[Test]
    public function a_start_keyword_releases_the_suppression_and_records_fresh_consent(): void
    {
        $contact = $this->consentingContact();

        $this->deliver($this->inbound('STOP'))->assertStatus(200);
        $this->deliver($this->inbound('START'))->assertStatus(200);

        // The row is kept and stamped, never deleted: it is the evidence that
        // the opt-out existed and that the SUBSCRIBER withdrew it.
        $this->assertDatabaseCount('sms_suppressions', 1);
        $suppression = SmsSuppression::withoutMasjidScope()->first();
        $this->assertNotNull($suppression->released_at);
        $this->assertSame('START', $suppression->released_keyword);

        $contact->refresh();
        $this->assertTrue($contact->hasSmsConsent());
        // Written in the subscriber's own hand — the one source no admin can claim.
        $this->assertSame('sms_reply_start', $contact->sms_consent_source);
    }

    // ---------- everything else ----------

    #[Test]
    public function help_changes_no_state(): void
    {
        $contact = $this->consentingContact();

        $this->deliver($this->inbound('HELP'))->assertStatus(200);

        $this->assertDatabaseCount('sms_suppressions', 0);
        $this->assertTrue($contact->fresh()->hasSmsConsent());
    }

    #[Test]
    public function an_ordinary_inbound_message_is_never_treated_as_consent(): void
    {
        $contact = Contact::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'No',
            'last_name' => 'Consent',
            'phone' => '+16135550111',
        ]);

        $this->deliver($this->inbound('what time is jumuah?'))->assertStatus(200);

        $this->assertFalse($contact->fresh()->hasSmsConsent());
    }

    #[Test]
    public function a_message_to_a_number_we_no_longer_own_is_acked_and_ignored(): void
    {
        $params = $this->inbound('STOP');
        $params['To'] = '+16135557777';

        $this->deliver($params)->assertStatus(200);

        $this->assertDatabaseCount('sms_suppressions', 0);
    }

    #[Test]
    public function it_answers_an_empty_twiml_document_and_composes_no_reply(): void
    {
        $response = $this->deliver($this->inbound('STOP'))->assertStatus(200);

        // The carrier-mandated STOP/HELP auto-replies are the provider's
        // Advanced Opt-Out; this application never composes an outbound message
        // here, which is why no test can emit one.
        $this->assertStringContainsString('<Response></Response>', $response->getContent());
    }

    #[Test]
    public function the_signature_is_verified_against_the_requests_own_url_when_no_override_is_set(): void
    {
        config(['services.twilio.webhook_url' => null]);

        $params = $this->inbound('STOP');

        $signature = app(TwilioSignatureVerifier::class)
            ->expected('http://localhost/api/sms/webhook', $params, self::TOKEN);

        $this->deliver($params, $signature)->assertStatus(200);

        $this->assertDatabaseCount('sms_suppressions', 1);
    }
}
