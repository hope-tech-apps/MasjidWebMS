<?php

namespace Tests\Feature\Broadcasts;

use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\Contact;
use App\Models\ContactServiceInterest;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The `service` audience over HTTP — the exact request the admin composer sends.
 *
 * BroadcastAudienceResolver is already unit-covered by
 * ServiceAudiencePushRoutingTest. What is NOT covered there, and is what the new
 * admin screen depends on, is the whole request surviving the round trip:
 * `StoreBroadcastRequest` accepting `audience=service` + `service_id` (a
 * combination it used to reject for push), the authorization gate letting a
 * contact-DERIVED audience through on a channel that reads no contacts, the
 * composer snapshotting the service, and the delivery reporting a target count
 * that is the interested subset rather than every registered handset.
 *
 * If the composer view is ever rewritten, this is the contract it must keep.
 */
class ServiceAudienceCompositionTest extends TestCase
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
        ]);

        Storage::fake('public');
        Http::fake(['*' => Http::response(['id' => 'onesignal-message-id'], 200)]);
        Mail::fake();

        // A service audience is contact-DERIVED, so it inherits the same
        // `crm_enabled` + `view contacts` gate the email channel carries.
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

    private function makeService(string $title): Service
    {
        return Service::create([
            'masjid_id' => $this->masjid->id,
            'title' => $title,
            'summary' => $title,
            'description' => $title,
            'text' => $title,
        ]);
    }

    /** A member who wants `$service`, signed in on one handset. */
    private function interestedMemberWithPhone(Service $service, string $subId): Contact
    {
        $contact = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        ContactServiceInterest::create([
            'masjid_id' => $this->masjid->id,
            'contact_id' => $contact->id,
            'service_id' => $service->id,
        ]);

        MobileAppUser::create([
            'masjid_id' => $this->masjid->id,
            'contact_id' => $contact->id,
            'device_id' => 'device-' . $subId,
            'onesignal_subscription_id' => $subId,
            'user_agent' => 'test-device',
        ]);

        return $contact;
    }

    /**
     * Validation failures are asserted against `data`, NOT the framework's
     * `errors` key: this application renders 422s as
     * `{status: "failed", data: {field: [messages]}}`, so
     * `assertJsonValidationErrors` looks in a place that is always empty and
     * passes/fails for the wrong reason.
     */

    /** The composer posts multipart, because the announcement leg can carry an image. */
    private function submit(array $payload)
    {
        return $this->call(
            'POST',
            "/api/admin/masjids/{$this->masjid->id}/broadcasts",
            $payload,
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );
    }

    #[Test]
    public function the_composer_can_send_push_to_one_services_interested_members(): void
    {
        $kitchen = $this->makeService('Halal Kitchen');
        $clinic = $this->makeService('Shifa Clinic');

        $this->interestedMemberWithPhone($kitchen, 'sub-wants');
        $this->interestedMemberWithPhone($clinic, 'sub-other');

        // An unclaimed handset — a guest, who hears `everyone` and nothing narrower.
        MobileAppUser::create([
            'masjid_id' => $this->masjid->id,
            'device_id' => 'device-guest',
            'onesignal_subscription_id' => 'sub-guest',
            'user_agent' => 'test-device',
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->submit([
            'title' => 'Kitchen is open',
            'body' => 'Come by after Maghrib.',
            'channels' => ['push'],
            'audience' => 'service',
            'service_id' => $kitchen->id,
        ])->assertStatus(202);

        // The SERVICE is snapshotted onto the broadcast; its people are not.
        $broadcast = Broadcast::withoutMasjidScope()->firstOrFail();
        $this->assertSame('service', $broadcast->audience);
        $this->assertSame($kitchen->id, (int) $broadcast->audience_service_id);
        $this->assertNull($broadcast->audience_contact_ids);

        // ONE device — not the other service's member, and not the guest.
        $delivery = BroadcastDelivery::withoutMasjidScope()->where('channel', 'push')->firstOrFail();
        $this->assertSame(BroadcastDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame(1, (int) $delivery->target_count);

        $this->assertSame('sent', $response->json('data.status'));
    }

    #[Test]
    public function a_service_audience_still_requires_a_service(): void
    {
        Sanctum::actingAs($this->admin);

        $this->submit([
            'title' => 'Kitchen is open',
            'body' => 'Come by after Maghrib.',
            'channels' => ['push'],
            'audience' => 'service',
        ])->assertStatus(422)->assertJsonStructure(['data' => ['service_id']]);
    }

    #[Test]
    public function another_organisations_service_cannot_be_addressed(): void
    {
        // `services` carries no BelongsToMasjid trait, so nothing downstream
        // would notice — the rule on the request is the whole defence.
        $other = Masjid::create([
            'name' => 'Other Masjid ' . uniqid(),
            'email' => 'other-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '2 Other St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        $theirs = Service::create([
            'masjid_id' => $other->id,
            'title' => 'Their Kitchen',
            'summary' => 'x',
            'description' => 'x',
            'text' => 'x',
        ]);

        Sanctum::actingAs($this->admin);

        $this->submit([
            'title' => 'Kitchen is open',
            'body' => 'Come by after Maghrib.',
            'channels' => ['push'],
            'audience' => 'service',
            'service_id' => $theirs->id,
        ])->assertStatus(422)->assertJsonStructure(['data' => ['service_id']]);

        $this->assertSame(0, Broadcast::withoutMasjidScope()->count());
    }

    #[Test]
    public function push_to_a_hand_picked_contact_list_is_still_refused(): void
    {
        // Devices now carry a contact_id, but most are never signed in — so a
        // chosen list would silently reach a fraction of the people picked.
        $contact = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        Sanctum::actingAs($this->admin);

        $this->submit([
            'title' => 'Kitchen is open',
            'body' => 'Come by after Maghrib.',
            'channels' => ['push'],
            'audience' => 'contacts',
            'contact_ids' => [$contact->id],
        ])->assertStatus(422)->assertJsonStructure(['data' => ['channels']]);
    }
}
