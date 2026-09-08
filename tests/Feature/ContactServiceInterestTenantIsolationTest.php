<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactServiceInterest;
use App\Models\Masjid;
use App\Models\Service;
use App\Services\Member\MemberInterestService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-TENANT isolation for `ContactServiceInterest`, required of every new
 * BelongsToMasjid model by .claude/rules/tenant-scoping.md.
 *
 * There is a second hazard here that the trait alone does NOT cover, and it is
 * the reason MemberInterestService exists rather than a `sync()` at a
 * controller: `Service` is a pre-CRM model with NO BelongsToMasjid trait, so
 * the bound tenant does not filter it and a bare `Service::find($id)` returns
 * another organisation's row quite happily. An interest is a notification
 * ROUTE, so a member who could write one against a foreign service id would be
 * subscribing themselves to another organisation's sends.
 */
class ContactServiceInterestTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $a;
    private Masjid $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeMasjid();
        $this->b = $this->makeMasjid();
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

    private function makeService(Masjid $masjid, string $title): Service
    {
        return Service::create([
            'masjid_id' => $masjid->id,
            'title' => $title,
            'summary' => $title . ' summary',
            'description' => $title . ' description',
            'text' => $title . ' body text',
        ]);
    }

    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    // ------------------------------------------- 1. the model layer (the rule)

    #[Test]
    public function a_bound_tenant_cannot_read_another_organizations_contact_service_interest(): void
    {
        $contactA = Contact::factory()->create(['masjid_id' => $this->a->id]);
        $contactB = Contact::factory()->create(['masjid_id' => $this->b->id]);
        $serviceA = $this->makeService($this->a, 'Halal Kitchen');
        $serviceB = $this->makeService($this->b, 'Food Pantry');

        $interestA = ContactServiceInterest::create([
            'masjid_id' => $this->a->id,
            'contact_id' => $contactA->id,
            'service_id' => $serviceA->id,
        ]);
        $interestB = ContactServiceInterest::create([
            'masjid_id' => $this->b->id,
            'contact_id' => $contactB->id,
            'service_id' => $serviceB->id,
        ]);

        $this->assertSame(2, ContactServiceInterest::withoutMasjidScope()->count());

        $this->tenant()->set($this->a->id);

        $this->assertSame(1, ContactServiceInterest::count());
        $this->assertNotNull(ContactServiceInterest::find($interestA->id));
        $this->assertNull(ContactServiceInterest::find($interestB->id));

        $this->assertSame(0, ContactServiceInterest::where('id', $interestB->id)->update(['service_id' => $serviceA->id]));
        $this->assertSame(0, ContactServiceInterest::where('id', $interestB->id)->delete());
        $this->assertSame($serviceB->id, (int) $interestB->fresh()->service_id);
        $this->assertNotNull(ContactServiceInterest::withoutMasjidScope()->find($interestB->id));

        // A DIFFERENT service, because (contact_id, service_id) is unique and
        // reusing $serviceA here would trip that constraint rather than test
        // the tenant stamp.
        $another = $this->makeService($this->a, 'Career Programs');

        $planted = ContactServiceInterest::create([
            'masjid_id' => $this->b->id,
            'contact_id' => $contactA->id,
            'service_id' => $another->id,
        ]);

        $this->assertSame($this->a->id, (int) $planted->masjid_id);
    }

    // ------------------- 2. the hazard the trait does not cover: Service itself

    #[Test]
    public function a_member_cannot_subscribe_to_another_organisations_service(): void
    {
        $contactA = Contact::factory()->create(['masjid_id' => $this->a->id]);
        $mine = $this->makeService($this->a, 'Shifa Clinic');
        $theirs = $this->makeService($this->b, 'Somebody Elses Clinic');

        $this->tenant()->set($this->a->id);

        $result = app(MemberInterestService::class)->set($contactA, [$mine->id, $theirs->id]);

        // The foreign id is dropped silently — a 422 naming it would confirm
        // which service ids exist in which organisation.
        $this->assertSame(1, $result['added']);

        $stored = ContactServiceInterest::withoutMasjidScope()
            ->where('contact_id', $contactA->id)
            ->pluck('service_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertSame([$mine->id], $stored);
    }

    #[Test]
    public function the_catalogue_a_member_sees_is_only_their_own_organisations(): void
    {
        $contactA = Contact::factory()->create(['masjid_id' => $this->a->id]);
        $mine = $this->makeService($this->a, 'Al-Bayan Quran Academy');
        $this->makeService($this->b, 'Another Orgs Academy');

        $this->tenant()->set($this->a->id);

        $listed = app(MemberInterestService::class)->list($contactA);

        $this->assertCount(1, $listed);
        $this->assertSame($mine->id, $listed[0]['id']);
        $this->assertFalse($listed[0]['interested']);
    }

    #[Test]
    public function setting_interests_is_a_diff_and_keeps_the_original_opt_in_timestamp(): void
    {
        // `created_at` is the only record of WHEN somebody opted in — the answer
        // a complaint about unwanted notifications needs. A delete-then-insert
        // implementation would silently rewrite it on every unrelated toggle.
        $contact = Contact::factory()->create(['masjid_id' => $this->a->id]);
        $kept = $this->makeService($this->a, 'Kept');
        $added = $this->makeService($this->a, 'Added');

        $this->tenant()->set($this->a->id);
        $interests = app(MemberInterestService::class);

        $interests->set($contact, [$kept->id]);
        $originalCreatedAt = ContactServiceInterest::where('contact_id', $contact->id)
            ->where('service_id', $kept->id)
            ->firstOrFail()
            ->created_at;

        $this->travel(2)->minutes();

        $result = $interests->set($contact, [$kept->id, $added->id]);

        $this->assertSame(1, $result['added']);
        $this->assertSame(0, $result['removed']);
        $this->assertSame(1, $result['kept']);

        $this->assertEquals(
            $originalCreatedAt->timestamp,
            ContactServiceInterest::where('contact_id', $contact->id)
                ->where('service_id', $kept->id)
                ->firstOrFail()
                ->created_at
                ->timestamp,
            'a kept interest must not have its opt-in timestamp rewritten'
        );

        // Turning everything off is as easy as turning it on.
        $off = $interests->set($contact, []);
        $this->assertSame(2, $off['removed']);
        $this->assertSame(0, ContactServiceInterest::where('contact_id', $contact->id)->count());
    }
}
