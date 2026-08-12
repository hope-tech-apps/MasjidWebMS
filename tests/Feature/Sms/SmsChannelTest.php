<?php

namespace Tests\Feature\Sms;

use App\Enums\BroadcastChannel;
use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Contact;
use App\Models\Masjid;
use App\Models\MasjidSmsSender;
use App\Models\SmsSuppression;
use App\Models\User;
use App\Services\Broadcast\BroadcastDispatcher;
use App\Services\Sms\SmsMessage;
use App\Services\Sms\SmsProvider;
use App\Services\Sms\SmsProviderFactory;
use App\Services\Sms\SmsSendResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The SMS channel of the unified composer (T-009).
 *
 * The behaviours this suite exists to hold — all five of them being obligations
 * .claude/rules/broadcasts.md named when T-008 refused to ship this channel:
 *
 *  1. The audience is resolved on CONSENT, never on `phone IS NOT NULL`. A
 *     contact with a number and no consent record is `skipped` from the count,
 *     not texted.
 *  2. A number on the suppression list is never texted, whatever the contact row
 *     says — the durable list wins over the mortal columns.
 *  3. A tenant with no APPROVED A2P 10DLC sender cannot send, and is refused in
 *     words rather than falling back to a shared number.
 *  4. An unconfigured provider fails soft and clearly at send time; it never
 *     reports "sent", and it never touches a network.
 *  5. Sender identity and opt-out language are in every outbound body, put there
 *     by code that an admin cannot compose away.
 *
 * NOTHING in this file can reach a carrier. The provider is either the `log`
 * adapter (accepts, writes a line, sends nothing) or a recording double bound
 * into the container; SMS_DRIVER is pinned to `none` by phpunit.xml so even a
 * mistake here degrades to the refusing adapter rather than to a real message.
 */
class SmsChannelTest extends TestCase
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

        config([
            'onesignal.api_url' => 'https://onesignal.com/api/v1/notifications',
            'onesignal.app_id' => 'app-id-test',
            'onesignal.app_rest_api_key' => 'rest-key-test',
            // The local-development adapter: accepts and logs, sends nothing.
            'services.sms.driver' => 'log',
        ]);

        Http::fake(['*' => Http::response(['id' => 'onesignal-message-id'], 200)]);
        Mail::fake();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->makeMasjid();
        $this->admin = $this->makeAdminFor($this->masjid);
    }

    // ---------- fixtures ----------

    private function makeMasjid(bool $crmEnabled = true): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => $crmEnabled,
        ]);
    }

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function approvedSender(?Masjid $masjid = null, array $overrides = []): MasjidSmsSender
    {
        return MasjidSmsSender::withoutMasjidScope()->create(array_merge([
            'masjid_id' => ($masjid ?? $this->masjid)->id,
            'provider' => 'twilio',
            'phone_number' => '+16135550100',
            'sender_label' => 'Test Masjid',
            'registration_status' => MasjidSmsSender::STATUS_APPROVED,
            'approved_at' => Carbon::now(),
        ], $overrides));
    }

    /** A contact with a full, dated, sourced consent record. */
    private function consentingContact(string $phone, ?Masjid $masjid = null): Contact
    {
        return Contact::withoutMasjidScope()->create([
            'masjid_id' => ($masjid ?? $this->masjid)->id,
            'first_name' => 'Consenting',
            'last_name' => 'Congregant',
            'phone' => $phone,
            'sms_opt_in' => true,
            'sms_consent_at' => Carbon::now()->subDay(),
            'sms_consent_source' => 'web_form',
            'sms_consent_evidence' => 'web form response #1',
        ]);
    }

    /** A contact with a phone number and no consent whatsoever — the default. */
    private function unconsentingContact(string $phone, ?Masjid $masjid = null): Contact
    {
        return Contact::withoutMasjidScope()->create([
            'masjid_id' => ($masjid ?? $this->masjid)->id,
            'first_name' => 'Merely',
            'last_name' => 'Listed',
            'phone' => $phone,
        ]);
    }

    private function compose(array $channels = ['sms'], array $overrides = []): Broadcast
    {
        $broadcast = Broadcast::withoutMasjidScope()->create(array_merge([
            'masjid_id' => $this->masjid->id,
            'title' => 'Snow closure',
            'body' => 'All programs are cancelled today because of the storm.',
            'status' => Broadcast::STATUS_PENDING,
        ], $overrides));

        foreach ($channels as $channel) {
            BroadcastDelivery::withoutMasjidScope()->create([
                'broadcast_id' => $broadcast->id,
                'masjid_id' => $this->masjid->id,
                'channel' => $channel,
                'status' => BroadcastDelivery::STATUS_PENDING,
            ]);
        }

        return $broadcast;
    }

    private function dispatch(Broadcast $broadcast): BroadcastDelivery
    {
        app(BroadcastDispatcher::class)->dispatch($broadcast);

        return BroadcastDelivery::withoutMasjidScope()
            ->where('broadcast_id', $broadcast->id)
            ->where('channel', 'sms')
            ->first();
    }

    /**
     * Bind a recording provider so a test can read the exact body that would
     * have gone out. Still no network: this double is the whole provider.
     */
    private function recordingProvider(): object
    {
        $recorder = new class implements SmsProvider {
            /** @var array<int, SmsMessage> */
            public array $sent = [];

            public function name(): string
            {
                return 'recording';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(SmsMessage $message): SmsSendResult
            {
                $this->sent[] = $message;

                return new SmsSendResult('SM' . count($this->sent), 'recording');
            }
        };

        $this->app->instance(SmsProviderFactory::class, new class($recorder) extends SmsProviderFactory {
            public function __construct(private readonly SmsProvider $provider)
            {
            }

            public function make(): SmsProvider
            {
                return $this->provider;
            }
        });

        return $recorder;
    }

    // ---------- 1. consent, not "has a phone number" ----------

    #[Test]
    public function it_texts_only_the_contacts_who_actually_consented(): void
    {
        $this->approvedSender();

        $this->consentingContact('+16135550111');
        $this->unconsentingContact('+16135550222');
        $this->unconsentingContact('+16135550333');

        $delivery = $this->dispatch($this->compose());

        $this->assertSame(BroadcastDelivery::STATUS_SENT, $delivery->status);
        // One recipient out of three contacts who all have phone numbers.
        $this->assertSame(1, $delivery->target_count);
        $this->assertStringContainsString('2 had no recorded consent', $delivery->note);
    }

    #[Test]
    public function a_consent_flag_without_provenance_is_not_consent(): void
    {
        $this->approvedSender();

        // The shape a careless bulk UPDATE produces: the flag, and nothing that
        // makes it defensible. hasSmsConsent() requires all three.
        Contact::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Flag',
            'last_name' => 'Only',
            'phone' => '+16135550444',
            'sms_opt_in' => true,
        ]);

        $delivery = $this->dispatch($this->compose());

        $this->assertSame(BroadcastDelivery::STATUS_SKIPPED, $delivery->status);
        $this->assertSame(0, $delivery->target_count);
    }

    #[Test]
    public function nobody_consenting_is_a_skip_and_not_a_failure(): void
    {
        $this->approvedSender();
        $this->unconsentingContact('+16135550555');

        $delivery = $this->dispatch($this->compose());

        $this->assertSame(BroadcastDelivery::STATUS_SKIPPED, $delivery->status);
        $this->assertNull($delivery->error);
        $this->assertStringContainsString('no recorded consent', $delivery->note);

        // A broadcast whose only outcome was "nobody to send to" is not failed.
        $this->assertSame(Broadcast::STATUS_SENT, Broadcast::withoutMasjidScope()->first()->status);
    }

    #[Test]
    public function a_number_that_cannot_be_normalised_is_never_guessed_at(): void
    {
        $this->approvedSender();

        // Consent recorded, but the number cannot be resolved to E.164 with
        // confidence. Refused rather than guessed — a wrong normalisation texts
        // a stranger and matches no suppression row.
        Contact::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Ambiguous',
            'last_name' => 'Number',
            'phone' => '5550142',
            'sms_opt_in' => true,
            'sms_consent_at' => Carbon::now(),
            'sms_consent_source' => 'paper_form',
        ]);

        $delivery = $this->dispatch($this->compose());

        $this->assertSame(BroadcastDelivery::STATUS_SKIPPED, $delivery->status);
        $this->assertStringContainsString('could not be read as a real number', $delivery->note);
    }

    // ---------- 2. the suppression list wins ----------

    #[Test]
    public function a_suppressed_number_is_never_texted_even_with_a_full_consent_record(): void
    {
        $this->approvedSender();

        $this->consentingContact('+16135550111');

        // The durable list says no. The contact row says yes. The list wins.
        SmsSuppression::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'phone_e164' => '+16135550111',
            'reason' => SmsSuppression::REASON_STOP_KEYWORD,
            'keyword' => 'STOP',
            'suppressed_at' => Carbon::now(),
        ]);

        $delivery = $this->dispatch($this->compose());

        $this->assertSame(BroadcastDelivery::STATUS_SKIPPED, $delivery->status);
        $this->assertStringContainsString('1 had opted out', $delivery->note);
    }

    #[Test]
    public function a_released_suppression_stops_blocking(): void
    {
        $this->approvedSender();
        $this->consentingContact('+16135550111');

        SmsSuppression::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'phone_e164' => '+16135550111',
            'suppressed_at' => Carbon::now()->subMonth(),
            // The subscriber texted START back.
            'released_at' => Carbon::now()->subDay(),
            'released_keyword' => 'START',
        ]);

        $delivery = $this->dispatch($this->compose());

        $this->assertSame(BroadcastDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame(1, $delivery->target_count);
    }

    // ---------- 3. no registered sender, no send ----------

    #[Test]
    public function a_tenant_with_no_registered_sender_cannot_send(): void
    {
        $this->consentingContact('+16135550111');

        $delivery = $this->dispatch($this->compose());

        $this->assertSame(BroadcastDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(0, $delivery->target_count);
        $this->assertStringContainsString('10DLC', $delivery->error);
    }

    #[Test]
    public function a_pending_registration_cannot_send_either(): void
    {
        $this->approvedSender(overrides: [
            'registration_status' => MasjidSmsSender::STATUS_PENDING,
            'approved_at' => null,
        ]);
        $this->consentingContact('+16135550111');

        $delivery = $this->dispatch($this->compose());

        $this->assertSame(BroadcastDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('pending', $delivery->error);
    }

    #[Test]
    public function an_approved_sender_with_no_number_cannot_send(): void
    {
        $this->approvedSender(overrides: ['phone_number' => null, 'messaging_service_sid' => null]);
        $this->consentingContact('+16135550111');

        $delivery = $this->dispatch($this->compose());

        $this->assertSame(BroadcastDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('no phone number or messaging service', $delivery->error);
    }

    #[Test]
    public function one_tenant_can_never_borrow_another_tenants_sender(): void
    {
        $other = $this->makeMasjid();
        $this->approvedSender($other);

        $this->consentingContact('+16135550111');

        $delivery = $this->dispatch($this->compose());

        $this->assertSame(BroadcastDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('no registered text-message sender', $delivery->error);
    }

    // ---------- 4. unconfigured provider fails soft, never "sent" ----------

    #[Test]
    public function an_unconfigured_provider_fails_clearly_instead_of_reporting_sent(): void
    {
        // The default state of every deployment until an operator provisions
        // credentials — and of the whole test suite.
        config(['services.sms.driver' => null, 'services.twilio.account_sid' => null]);

        $this->approvedSender();
        $this->consentingContact('+16135550111');

        $delivery = $this->dispatch($this->compose());

        $this->assertSame(BroadcastDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('not configured', $delivery->error);
    }

    // ---------- 5. identity + opt-out language are in the body ----------

    #[Test]
    public function every_message_carries_the_sender_identity_and_the_opt_out_line(): void
    {
        $recorder = $this->recordingProvider();

        $this->approvedSender(overrides: ['sender_label' => 'Masjid an-Nur']);
        $this->consentingContact('+16135550111');

        $this->dispatch($this->compose());

        $this->assertCount(1, $recorder->sent);
        $body = $recorder->sent[0]->body;

        $this->assertStringStartsWith('Masjid an-Nur: ', $body);
        $this->assertStringContainsString('Snow closure', $body);
        $this->assertStringContainsString('Reply STOP to unsubscribe.', $body);
        $this->assertSame('+16135550111', $recorder->sent[0]->to);
        $this->assertSame('+16135550100', $recorder->sent[0]->from);
    }

    #[Test]
    public function a_long_message_loses_the_admins_words_and_never_the_opt_out_line(): void
    {
        $recorder = $this->recordingProvider();

        $this->approvedSender(overrides: ['sender_label' => 'Masjid an-Nur']);
        $this->consentingContact('+16135550111');

        config(['services.sms.max_body_length' => 200]);

        $this->dispatch($this->compose(overrides: [
            'body' => str_repeat('This is a very long announcement body. ', 40),
        ]));

        $body = $recorder->sent[0]->body;

        $this->assertLessThanOrEqual(200, mb_strlen($body));
        $this->assertStringStartsWith('Masjid an-Nur: ', $body);
        $this->assertStringEndsWith('Reply STOP to unsubscribe.', $body);
    }

    #[Test]
    public function a_messaging_service_is_preferred_over_a_bare_number(): void
    {
        $recorder = $this->recordingProvider();

        $this->approvedSender(overrides: ['messaging_service_sid' => 'MG0000000000']);
        $this->consentingContact('+16135550111');

        $this->dispatch($this->compose());

        $this->assertSame('MG0000000000', $recorder->sent[0]->messagingServiceSid);
        $this->assertNull($recorder->sent[0]->from);
    }

    // ---------- T-008's invariants, inherited ----------

    #[Test]
    public function a_failing_sms_channel_never_rolls_back_a_successful_one(): void
    {
        // No sender: SMS will fail. The announcement must still go out.
        $this->consentingContact('+16135550111');

        $broadcast = $this->compose(['announcement', 'sms'], [
            'starts_on' => Carbon::now()->toDateString(),
            'ends_on' => Carbon::now()->addDay()->toDateString(),
        ]);

        app(BroadcastDispatcher::class)->dispatch($broadcast);

        $deliveries = BroadcastDelivery::withoutMasjidScope()
            ->where('broadcast_id', $broadcast->id)
            ->pluck('status', 'channel');

        $this->assertSame(BroadcastDelivery::STATUS_SENT, $deliveries['announcement']);
        $this->assertSame(BroadcastDelivery::STATUS_FAILED, $deliveries['sms']);
        $this->assertSame(Broadcast::STATUS_PARTIAL, $broadcast->fresh()->status);

        // The announcement row survived the sibling's failure.
        $this->assertSame(1, \App\Models\Announcement::where('masjid_id', $this->masjid->id)->count());
    }

    #[Test]
    public function re_dispatching_never_texts_anybody_twice(): void
    {
        $recorder = $this->recordingProvider();

        $this->approvedSender();
        $this->consentingContact('+16135550111');

        $broadcast = $this->compose();

        app(BroadcastDispatcher::class)->dispatch($broadcast);
        $this->assertCount(1, $recorder->sent);

        // A retry after a partial send re-attempts only pending/failed rows.
        app(BroadcastDispatcher::class)->dispatch($broadcast->fresh());

        $this->assertCount(1, $recorder->sent);
    }

    // ---------- authorization, inherited from readsContacts() ----------

    #[Test]
    public function selecting_sms_without_the_crm_is_refused_up_front(): void
    {
        $masjid = $this->makeMasjid(crmEnabled: false);
        $admin = $this->makeAdminFor($masjid);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/masjids/{$masjid->id}/broadcasts", [
            'title' => 'Snow closure',
            'body' => 'Cancelled.',
            'audience' => 'everyone',
            'channels' => ['sms'],
        ])->assertStatus(403);

        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_composer_accepts_sms_as_an_ordinary_channel(): void
    {
        $this->approvedSender();
        $this->consentingContact('+16135550111');

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/admin/masjids/{$this->masjid->id}/broadcasts", [
            'title' => 'Snow closure',
            'body' => 'All programs are cancelled today.',
            'audience' => 'everyone',
            'channels' => ['sms'],
        ])->assertStatus(202);

        $this->assertSame('sent', $response->json('data.deliveries.0.status'));
        $this->assertSame(1, $response->json('data.deliveries.0.target_count'));
        $this->assertSame(BroadcastChannel::SMS->value, $response->json('data.deliveries.0.channel'));
    }
}
