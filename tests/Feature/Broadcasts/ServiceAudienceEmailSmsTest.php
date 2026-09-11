<?php

namespace Tests\Feature\Broadcasts;

use App\Enums\BroadcastAudience;
use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\ContactServiceInterest;
use App\Models\Masjid;
use App\Models\Service;
use App\Services\Broadcast\BroadcastAudienceResolver;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A service audience ("people interested in IntelliCor") narrows EMAIL and SMS,
 * not only push. It used to fall through to every contact on those channels
 * while the composer's confirmation named the service. LunchOpeningNotifier's
 * "text me when lunch opens" depends on the same narrowing.
 */
class ServiceAudienceEmailSmsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->masjid = Masjid::create([
            'name' => 'Audience Org ' . uniqid(),
            'email' => 'aud' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true,
        ]);

        // As in the send job: the organisation is bound.
        app(TenantContext::class)->set($this->masjid->id);
    }

    private function service(string $title): Service
    {
        return Service::create(['masjid_id' => $this->masjid->id, 'title' => $title, 'summary' => $title, 'description' => $title, 'text' => $title]);
    }

    private function contact(string $email, string $phone, ?Service $interestedIn, bool $smsConsent = true): Contact
    {
        $contact = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => $email, 'phone' => $phone]);
        if ($smsConsent) {
            $contact->forceFill(['sms_opt_in' => true, 'sms_consent_at' => now(), 'sms_consent_source' => 'test'])->save();
        }
        if ($interestedIn) {
            ContactServiceInterest::create(['masjid_id' => $this->masjid->id, 'contact_id' => $contact->id, 'service_id' => $interestedIn->id]);
        }

        return $contact->fresh();
    }

    private function broadcast(?int $serviceId, string $audience = 'service'): Broadcast
    {
        return (new Broadcast())->forceFill([
            'masjid_id' => $this->masjid->id,
            'audience' => $audience,
            'audience_service_id' => $serviceId,
        ]);
    }

    private function resolver(): BroadcastAudienceResolver
    {
        return app(BroadcastAudienceResolver::class);
    }

    #[Test]
    public function a_service_audience_emails_only_the_people_interested_in_it(): void
    {
        $kitchen = $this->service('Halal Kitchen');
        $clinic = $this->service('Shifa Clinic');
        $wants = $this->contact('wants@test.local', '+13365550101', $kitchen);
        $other = $this->contact('other@test.local', '+13365550102', $clinic);
        $nobody = $this->contact('nobody@test.local', '+13365550103', null);

        $this->assertSame([$wants->id], $this->resolver()->emailRecipients($this->broadcast($kitchen->id))->pluck('id')->all());

        // "Everyone" still means everyone.
        $all = $this->resolver()->emailRecipients($this->broadcast(null, 'everyone'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$wants->id, $other->id, $nobody->id])->sort()->values()->all(), $all);
    }

    #[Test]
    public function a_service_audience_texts_only_the_consenting_people_interested_in_it(): void
    {
        $kitchen = $this->service('Halal Kitchen');
        $wants = $this->contact('wants@test.local', '+13365550101', $kitchen);
        $this->contact('noconsent@test.local', '+13365550104', $kitchen, smsConsent: false);
        $this->contact('elsewhere@test.local', '+13365550105', null);

        $audience = $this->resolver()->smsRecipients($this->broadcast($kitchen->id));

        $this->assertSame([$wants->id], array_map(fn ($row) => $row['contact']->id, $audience->recipients));
        $this->assertSame(1, $audience->withoutConsent, 'the interested contact without consent is counted, not texted');
    }

    #[Test]
    public function a_service_audience_without_a_service_addresses_nobody(): void
    {
        $this->contact('someone@test.local', '+13365550106', null);

        $this->assertCount(0, $this->resolver()->emailRecipients($this->broadcast(0)));
        $this->assertSame([], $this->resolver()->smsRecipients($this->broadcast(0))->recipients);
    }
}
