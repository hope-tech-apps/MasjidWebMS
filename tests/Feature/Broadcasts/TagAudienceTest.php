<?php

namespace Tests\Feature\Broadcasts;

use App\Enums\BroadcastChannel;
use App\Mail\BroadcastMail;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\ContactTag;
use App\Models\Masjid;
use App\Models\MasjidSmsSender;
use App\Models\MobileAppUser;
use App\Models\User;
use App\Services\Broadcast\BroadcastAudienceResolver;
use App\Services\Broadcast\BroadcastChannelDriver;
use App\Services\Broadcast\BroadcastComposer;
use App\Services\Broadcast\BroadcastDispatcher;
use App\Services\Broadcast\ChannelResult;
use App\Services\Broadcast\Channels\EmailChannel;
use App\Services\Broadcast\EmailSuppressionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A broadcast addressed to a contact TAG: email and SMS reach only the people
 * carrying the tag, through the same opt-out list and consent record as
 * "everyone"; push to a tag is refused; a tag audience whose tag is gone
 * addresses nobody, never everyone.
 */
class TagAudienceTest extends TestCase
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

        Storage::fake('public');
        Http::fake(['*' => Http::response(['id' => 'onesignal-message-id'], 200)]);
        Mail::fake();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->makeMasjid();
        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();
    }

    private function makeMasjid(): Masjid
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
            'crm_enabled' => true,
        ]);
    }

    private function tag(string $name, ?Masjid $masjid = null): ContactTag
    {
        return ContactTag::withoutMasjidScope()->create(['masjid_id' => ($masjid ?? $this->masjid)->id, 'name' => $name]);
    }

    private function contact(string $email, string $phone, ?ContactTag $tag = null, bool $smsConsent = true): Contact
    {
        $contact = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => $email, 'phone' => $phone]);

        if ($smsConsent) {
            $contact->forceFill(['sms_opt_in' => true, 'sms_consent_at' => now(), 'sms_consent_source' => 'web_form'])->save();
        }

        $tag?->contacts()->attach([$contact->id]);

        return $contact->fresh();
    }

    private function broadcast(?int $tagId): Broadcast
    {
        return (new Broadcast())->forceFill([
            'masjid_id' => $this->masjid->id,
            'audience' => 'tag',
            'audience_tag_id' => $tagId,
        ]);
    }

    private function resolver(): BroadcastAudienceResolver
    {
        app(TenantContext::class)->set($this->masjid->id);

        return app(BroadcastAudienceResolver::class);
    }

    private function submit(array $payload)
    {
        return $this->call('POST', "/api/admin/masjids/{$this->masjid->id}/broadcasts", $payload, [], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    #[Test]
    public function a_tag_audience_emails_only_the_tagged_people_who_have_not_opted_out(): void
    {
        $volunteer = $this->tag('Volunteer');
        $tagged = $this->contact('tagged@example.test', '+13365550101', $volunteer);
        $this->contact('optedout@example.test', '+13365550102', $volunteer);
        $this->contact('untagged@example.test', '+13365550103');
        app(EmailSuppressionService::class)->suppress($this->masjid->id, 'optedout@example.test');

        $audience = $this->resolver()->emailAudience($this->broadcast($volunteer->id));

        $this->assertSame([$tagged->id], $audience->recipients->pluck('id')->all());
        $this->assertSame(1, $audience->suppressed);
    }

    #[Test]
    public function a_tag_audience_texts_only_the_consenting_tagged_people(): void
    {
        $volunteer = $this->tag('Volunteer');
        $tagged = $this->contact('tagged@example.test', '+13365550101', $volunteer);
        $this->contact('noconsent@example.test', '+13365550104', $volunteer, smsConsent: false);
        $this->contact('untagged@example.test', '+13365550105');

        $audience = $this->resolver()->smsRecipients($this->broadcast($volunteer->id));

        $this->assertSame([$tagged->id], array_map(fn ($row) => $row['contact']->id, $audience->recipients));
        $this->assertSame(1, $audience->withoutConsent);
    }

    #[Test]
    public function a_tag_audience_with_no_tag_or_another_organisations_tag_addresses_nobody(): void
    {
        $this->contact('someone@example.test', '+13365550106');
        $other = $this->makeMasjid();
        $theirs = $this->tag('Theirs', $other);

        foreach ([null, 0, $theirs->id] as $tagId) {
            $this->assertCount(0, $this->resolver()->emailRecipients($this->broadcast($tagId)));
            $this->assertSame([], $this->resolver()->smsRecipients($this->broadcast($tagId))->recipients);
        }
    }

    #[Test]
    public function a_tag_audience_never_falls_back_to_every_device(): void
    {
        MobileAppUser::create([
            'masjid_id' => $this->masjid->id,
            'device_id' => 'device-guest',
            'onesignal_subscription_id' => 'sub-guest',
            'user_agent' => 'test-device',
        ]);

        $this->assertSame([], $this->resolver()->pushSubscriptionIds($this->masjid, $this->broadcast($this->tag('Volunteer')->id)));
    }

    #[Test]
    public function the_composer_sends_email_to_a_tag_and_stores_the_tag_on_the_broadcast(): void
    {
        $volunteer = $this->tag('Volunteer');
        $this->contact('tagged@example.test', '+13365550101', $volunteer);
        $this->contact('untagged@example.test', '+13365550103');
        Sanctum::actingAs($this->admin);

        $this->submit([
            'title' => 'Iftar rota',
            'body' => 'See you Friday.',
            'channels' => ['email'],
            'audience' => 'tag',
            'tag_id' => $volunteer->id,
        ])->assertStatus(202);

        $broadcast = Broadcast::withoutMasjidScope()->firstOrFail();
        $this->assertSame('tag', $broadcast->audience);
        $this->assertSame($volunteer->id, (int) $broadcast->audience_tag_id);
        $this->assertNull($broadcast->audience_service_id);

        $delivery = $broadcast->deliveries()->withoutGlobalScopes()->where('channel', 'email')->firstOrFail();
        $this->assertSame(1, (int) $delivery->target_count);
    }

    #[Test]
    public function deleting_the_tag_between_email_and_sms_is_refused_and_sms_keeps_its_audience(): void
    {
        Queue::fake();
        config(['services.sms.driver' => 'log']);
        $tag = $this->tag('Volunteer');
        $contact = $this->contact('tagged@example.test', '+13365550101', $tag);
        MasjidSmsSender::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id, 'provider' => 'twilio',
            'phone_number' => '+16135550100', 'sender_label' => 'Test Masjid',
            'registration_status' => MasjidSmsSender::STATUS_APPROVED, 'approved_at' => now(),
        ]);
        Sanctum::actingAs($this->admin);
        $broadcast = app(BroadcastComposer::class)->send($this->masjid, [
            'title' => 'Iftar rota', 'body' => 'See you Friday.',
            'audience' => 'tag', 'tag_id' => $tag->id, 'scheduled_at' => now()->addHour(),
        ], [BroadcastChannel::EMAIL, BroadcastChannel::SMS], authorId: $this->admin->id);
        $email = app(EmailChannel::class);
        $seen = (object) ['response' => null, 'status' => null];
        $test = $this;
        $this->app->bind(EmailChannel::class, fn () => new class($email, $test, $tag->id, $seen) implements BroadcastChannelDriver {
            public function __construct(private EmailChannel $email, private object $test, private int $tagId, private object $seen) {}
            public function channel(): BroadcastChannel { return BroadcastChannel::EMAIL; }
            public function deliver(Broadcast $broadcast, Masjid $masjid): ChannelResult
            {
                $result = $this->email->deliver($broadcast, $masjid);
                $this->seen->status = $broadcast->fresh()->status;
                $this->seen->response = $this->test->deleteJson("/api/admin/masjids/{$masjid->id}/contact-tags/{$this->tagId}");
                return $result;
            }
        });

        $result = app(BroadcastDispatcher::class)->dispatch($broadcast);

        $this->assertSame('sending', $seen->status);
        $seen->response->assertStatus(422);
        $this->assertNotNull($tag->fresh());
        Mail::assertQueued(BroadcastMail::class, fn ($mail) => $mail->hasTo($contact->email));
        $sms = $result->deliveries->firstWhere('channel', 'sms');
        $this->assertSame('sent', $sms->status);
        $this->assertSame(1, $sms->target_count);
        $this->assertSame('sent', $result->status);
    }

    #[Test]
    public function push_to_a_tag_is_refused_and_a_tag_audience_needs_a_tag(): void
    {
        $volunteer = $this->tag('Volunteer');
        Sanctum::actingAs($this->admin);

        $this->submit([
            'title' => 'Iftar rota', 'body' => 'See you Friday.',
            'channels' => ['push'], 'audience' => 'tag', 'tag_id' => $volunteer->id,
        ])->assertStatus(422)->assertJsonStructure(['data' => ['channels']]);

        $this->submit([
            'title' => 'Iftar rota', 'body' => 'See you Friday.',
            'channels' => ['email'], 'audience' => 'tag',
        ])->assertStatus(422)->assertJsonStructure(['data' => ['tag_id']]);

        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
    }

    #[Test]
    public function another_organisations_tag_cannot_be_addressed(): void
    {
        $theirs = $this->tag('Theirs', $this->makeMasjid());
        Sanctum::actingAs($this->admin);

        $this->submit([
            'title' => 'Iftar rota', 'body' => 'See you Friday.',
            'channels' => ['email'], 'audience' => 'tag', 'tag_id' => $theirs->id,
        ])->assertStatus(422)->assertJsonStructure(['data' => ['tag_id']]);

        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_tag_audience_is_closed_without_the_crm_even_on_a_channel_that_reads_no_contacts(): void
    {
        $volunteer = $this->tag('Volunteer');
        $this->masjid->forceFill(['crm_enabled' => false])->save();
        Sanctum::actingAs($this->admin);

        $this->submit([
            'title' => 'Iftar rota', 'body' => 'See you Friday.',
            'channels' => ['signage'], 'audience' => 'tag', 'tag_id' => $volunteer->id,
        ])->assertStatus(403);

        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
    }
}
