<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Group;
use App\Models\Masjid;
use App\Models\Offering;
use App\Support\ContactIdentity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `contacts.email` is written in the form sign-in looks addresses up in.
 *
 * A public registration is one of two anonymous doors that write `contacts.email`
 * (the other is the app's member sign-up, which stores the punycode address
 * `ContactIdentity::submittedAddress()` returns, so it needs nothing here). The
 * registration door used to store what was typed, lower-cased: `nadia@gmaíl.com`.
 * Sign-in converts a Unicode domain to punycode before it looks an address up, so
 * an address stored the other way could never be reached by the door that
 * matches it, and a Unicode spelling is exactly what a look-alike is made of.
 * Stored as `nadia@xn--…` it is one unambiguous ASCII mailbox, and mail goes to
 * the address that was actually typed.
 */
class OfferingRegistrationAddressTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private Offering $offering;

    private FeePlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        app(TenantContext::class)->forgetTenant();

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true,
            'stripe_account_id' => 'acct_TEST' . uniqid(),
            'stripe_charges_enabled' => true,
        ]);

        $group = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);

        $this->offering = Offering::factory()->forMasjid($this->masjid)->withRoster($group)->create(['slug' => 'quran-club']);
        $this->plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $this->offering->id,
        ]);
    }

    #[Test]
    public function a_payer_and_a_registrant_at_a_unicode_domain_are_stored_as_punycode(): void
    {
        $this->register('Nadia@Gmaíl.com', 'yusuf@gmaíl.com')->assertStatus(200);

        $payer = ContactIdentity::submittedAddress('nadia@gmaíl.com');
        $registrant = ContactIdentity::submittedAddress('yusuf@gmaíl.com');
        $this->assertStringContainsString('xn--', (string) $payer);

        $stored = Contact::withoutMasjidScope()->orderBy('id')->pluck('email')->all();

        $this->assertContains($payer, $stored, 'The payer\'s address was not stored as punycode.');
        $this->assertContains($registrant, $stored, 'The registrant\'s address was not stored as punycode.');

        foreach ($stored as $address) {
            $this->assertSame(1, preg_match('/^[\x00-\x7F]+$/', (string) $address), 'A non-ASCII address was stored: ' . $address);
        }
    }

    #[Test]
    public function registering_again_at_the_same_unicode_address_finds_the_same_payer(): void
    {
        $this->register('nadia@gmaíl.com', 'yusuf@gmaíl.com')->assertStatus(200);
        $this->register('NADIA@gmaíl.com', 'yusuf@gmaíl.com')->assertStatus(200);

        $payerAddress = ContactIdentity::submittedAddress('nadia@gmaíl.com');

        $this->assertSame(
            1,
            Contact::withoutMasjidScope()->where('email', $payerAddress)->count(),
            'A second registration at the same address made a second contact.',
        );
    }

    #[Test]
    public function an_ascii_address_is_stored_lower_cased_exactly_as_before(): void
    {
        $this->register('Nadia@Example.TEST', 'yusuf@example.test')->assertStatus(200);

        $this->assertContains('nadia@example.test', Contact::withoutMasjidScope()->pluck('email')->all());
    }

    private function register(string $payerEmail, string $registrantEmail): \Illuminate\Testing\TestResponse
    {
        app(TenantContext::class)->forgetTenant();

        return $this->postJson("/api/v1/offerings/{$this->offering->slug}/register", [
            'fee_plan_id' => $this->plan->id,
            'payer' => ['name' => 'Nadia Haq', 'email' => $payerEmail],
            'registrants' => [['name' => 'Yusuf Haq', 'email' => $registrantEmail]],
            'data' => ['full_name' => 'Nadia Haq'],
        ], ['masjid-id' => (string) $this->masjid->id]);
    }
}
