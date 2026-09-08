<?php

namespace Tests\Feature;

use App\Enums\BroadcastAudience;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\ContactServiceInterest;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\Service;
use App\Services\Broadcast\BroadcastAudienceResolver;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An interest actually narrows a push, and each way of not being a recipient is
 * honoured.
 *
 * This is the payoff for giving devices an identity, and the thing that would
 * silently regress: every filter below removes somebody, so a resolver that
 * dropped any one of them would still look like it worked — it would just reach
 * more phones than it should, which is the exact failure
 * .claude/rules/broadcasts.md refused to accept when it rejected push + a
 * narrowed audience.
 */
class ServiceAudiencePushRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->masjid = $this->makeMasjid();
        app(TenantContext::class)->set($this->masjid->id);
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

    private function makeDevice(?Contact $contact, string $subId): MobileAppUser
    {
        return MobileAppUser::create([
            'masjid_id' => $this->masjid->id,
            'contact_id' => $contact?->id,
            'device_id' => 'dev-' . uniqid(),
            'onesignal_subscription_id' => $subId,
            'user_agent' => 'test',
        ]);
    }

    private function serviceBroadcast(Service $service): Broadcast
    {
        return Broadcast::create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Kitchen is open',
            'body' => 'Come by after Maghrib.',
            'audience' => BroadcastAudience::SERVICE->value,
            'audience_service_id' => $service->id,
            'status' => Broadcast::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function a_service_audience_reaches_only_the_phones_of_members_who_asked(): void
    {
        $kitchen = $this->makeService('Halal Kitchen');
        $clinic = $this->makeService('Shifa Clinic');

        $wants = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $wantsSomethingElse = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $revoked = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $deleted = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        $revoked->forceFill(['login_revoked_at' => now()])->save();

        foreach ([$wants, $revoked, $deleted] as $c) {
            ContactServiceInterest::create([
                'masjid_id' => $this->masjid->id,
                'contact_id' => $c->id,
                'service_id' => $kitchen->id,
            ]);
        }
        ContactServiceInterest::create([
            'masjid_id' => $this->masjid->id,
            'contact_id' => $wantsSomethingElse->id,
            'service_id' => $clinic->id,
        ]);

        $this->makeDevice($wants, 'sub-wants');
        $this->makeDevice($wantsSomethingElse, 'sub-other-service');
        $this->makeDevice($revoked, 'sub-revoked');
        $this->makeDevice($deleted, 'sub-deleted');
        $this->makeDevice(null, 'sub-guest');

        // Soft-deleted AFTER the device exists: contact_id is nullOnDelete, but a
        // soft delete leaves it pointing at a hidden row, so only an explicit
        // check keeps this phone out.
        $deleted->delete();

        $resolver = app(BroadcastAudienceResolver::class);

        $this->assertSame(
            ['sub-wants'],
            $resolver->pushSubscriptionIds($this->masjid, $this->serviceBroadcast($kitchen)),
        );
    }

    #[Test]
    public function everyone_still_means_every_device_including_guests(): void
    {
        // Non-vacuity for the test above: the narrowing is the audience's doing,
        // not a broken fixture. An unclaimed handset is a first-class recipient
        // of an `everyone` broadcast — the app works without an account.
        $member = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->makeDevice($member, 'sub-member');
        $this->makeDevice(null, 'sub-guest');

        $ids = app(BroadcastAudienceResolver::class)->pushSubscriptionIds($this->masjid);

        sort($ids);
        $this->assertSame(['sub-guest', 'sub-member'], $ids);
    }

    #[Test]
    public function withdrawing_an_interest_is_honoured_at_send_time(): void
    {
        // The reason the recipients are NOT snapshotted onto the broadcast the
        // way `audience_contact_ids` is: an interest is an opt-in, and one
        // withdrawn between composing and dispatching must not still be reached.
        $kitchen = $this->makeService('Halal Kitchen');
        $member = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $this->makeDevice($member, 'sub-member');

        $interest = ContactServiceInterest::create([
            'masjid_id' => $this->masjid->id,
            'contact_id' => $member->id,
            'service_id' => $kitchen->id,
        ]);

        $broadcast = $this->serviceBroadcast($kitchen);
        $resolver = app(BroadcastAudienceResolver::class);

        $this->assertSame(['sub-member'], $resolver->pushSubscriptionIds($this->masjid, $broadcast));

        $interest->delete();

        $this->assertSame([], $resolver->pushSubscriptionIds($this->masjid, $broadcast));
    }

    #[Test]
    public function a_signed_out_device_stops_receiving_interest_routed_push(): void
    {
        // Sign-out is not cosmetic: a handed-down phone must stop hearing what
        // the previous member signed up for.
        $kitchen = $this->makeService('Halal Kitchen');
        $member = Contact::factory()->create(['masjid_id' => $this->masjid->id]);
        $device = $this->makeDevice($member, 'sub-member');

        ContactServiceInterest::create([
            'masjid_id' => $this->masjid->id,
            'contact_id' => $member->id,
            'service_id' => $kitchen->id,
        ]);

        $broadcast = $this->serviceBroadcast($kitchen);
        $resolver = app(BroadcastAudienceResolver::class);

        $this->assertSame(['sub-member'], $resolver->pushSubscriptionIds($this->masjid, $broadcast));

        $device->forceFill(['contact_id' => null])->save();

        $this->assertSame([], $resolver->pushSubscriptionIds($this->masjid, $broadcast));
    }
}
