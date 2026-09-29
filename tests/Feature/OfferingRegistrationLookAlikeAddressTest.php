<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registrant;
use App\Models\Registration;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * The public offering door matches a contact by the address that was TYPED,
 * exactly.
 *
 * THE DEFECT. `OfferingRegistrationsController::findByAddress()` matched
 * `LOWER(TRIM(email)) = ?` and took `->first()`. Production's `contacts.email` is
 * utf8mb4_unicode_ci (read there 2026-09-29), where `parent@gmail.com` =
 * `parent@gmaíl.com`, so an ANONYMOUS registration typed at a look-alike address
 * attached to the REAL person's contact: the payer or a registrant became
 * somebody else's record, with the guardian edge, the roster row and the
 * registration that follow.
 *
 * THREE CALLERS, THREE PATHS. Every path below is asserted, because a fix that
 * filtered only the first call would still pass a test written for the payer:
 *  - `resolvePayerContact()`: the payer is found by address alone;
 *  - `resolveRegistrantContact()`: the registrant is found by address AND name;
 *  - `createContact()`'s guard, which blanks the new row's address when another
 *    contact already holds it (the household-mailbox rule).
 * A look-alike now finds nobody, so each of them writes a NEW contact.
 *
 * HOW THESE TESTS REACH IT ON SQLITE (Tests\Support\FoldsAccentsLikeUnicodeCi):
 * the contact on record holds the ACCENTED form and the registration types the
 * plain one, with `LOWER()` overridden to fold accents, so the shortlist query
 * returns the contact as MySQL would. The collation is symmetric and the exact
 * comparison is too; which side carries the accent is immaterial to both. (The
 * stand-in folds the stored side only, the bound parameter never passes through
 * `LOWER()`, which is why the accent is on the stored side.) Every look-alike
 * test asserts that premise first.
 */
class OfferingRegistrationLookAlikeAddressTest extends TestCase
{
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    /** What the contact on record holds: the accented form. */
    private const STORED = 'parent@gmaíl.com';

    /** What the registration types. */
    private const TYPED = 'parent@gmail.com';

    /** A payer address nothing else in the file holds. */
    private const PAYER = 'payer@example.test';

    private Masjid $masjid;

    private Offering $offering;

    private FeePlan $plan;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->foldAccentsLikeUnicodeCi();

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

        $this->group = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);

        $this->offering = Offering::factory()->forMasjid($this->masjid)->withRoster($this->group)->create(['slug' => 'quran-club']);
        $this->plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $this->offering->id,
        ]);
    }

    protected function tearDown(): void
    {
        $this->stopFoldingAccents();

        parent::tearDown();
    }

    // -------------------------------------------------------------- premise

    #[Test]
    public function the_shortlist_query_returns_the_contact_on_record_under_the_stand_in(): void
    {
        $onRecord = $this->contact(self::STORED, 'Musa', 'Salim');

        $this->assertTheShortlistStillFinds([$onRecord]);
        $this->assertNotSame(self::STORED, self::TYPED, 'The two spellings must be different mailboxes.');
    }

    // ------------------------------------------------- look-alike: no attach

    #[Test]
    public function a_payer_at_a_look_alike_address_does_not_attach_to_the_contact_on_record(): void
    {
        $onRecord = $this->contact(self::STORED, 'Musa', 'Salim');
        $this->assertTheShortlistStillFinds([$onRecord]);
        $before = $this->stored($onRecord);

        $response = $this->register(
            ['name' => 'Nadia Haq', 'email' => self::TYPED],
            [['name' => 'Yusuf Haq']],
        );
        $response->assertStatus(200);

        // The payer is a NEW contact, at the address that was typed.
        $registration = Registration::withoutMasjidScope()->sole();
        $payer = Contact::withoutMasjidScope()->findOrFail($registration->contact_id);

        $this->assertNotSame($onRecord->id, $payer->id, 'The registration was made in the name of a contact that holds only a look-alike address.');
        $this->assertSame(self::TYPED, $payer->email);
        $this->assertSame('Nadia', $payer->first_name);

        $this->assertNothingReachedTheContactOnRecord($onRecord, $before, $response);
    }

    #[Test]
    public function a_registrant_at_a_look_alike_address_does_not_attach_to_the_contact_on_record(): void
    {
        // The registrant carries the SAME NAME as the contact on record, so the
        // name clause cannot be what saves it: only the address can.
        $onRecord = $this->contact(self::STORED, 'Musa', 'Salim');
        $this->assertTheShortlistStillFinds([$onRecord]);
        $before = $this->stored($onRecord);

        $response = $this->register(
            ['name' => 'Nadia Haq', 'email' => self::PAYER],
            [['name' => 'Musa Salim', 'email' => self::TYPED]],
        );
        $response->assertStatus(200);

        $registrants = $this->registrantContacts();

        $this->assertCount(1, $registrants);
        $this->assertNotSame($onRecord->id, $registrants->first()->id, 'The registrant was resolved to a contact that holds only a look-alike address.');
        $this->assertSame(self::TYPED, $registrants->first()->email);
        $this->assertSame('Musa', $registrants->first()->first_name);

        $this->assertNothingReachedTheContactOnRecord($onRecord, $before, $response);
    }

    #[Test]
    public function a_look_alike_holder_does_not_blank_the_new_contacts_address(): void
    {
        // The guard inside `createContact()`. A registrant whose NAME differs
        // from the contact on record is written as a new contact, and the guard
        // blanks its address when another contact already holds it. A contact
        // holding only a look-alike is not a holder, so the new row keeps the
        // address that was typed. A fix that filtered the first lookup and left
        // the guard alone fails here.
        $onRecord = $this->contact(self::STORED, 'Musa', 'Salim');
        $this->assertTheShortlistStillFinds([$onRecord]);
        $before = $this->stored($onRecord);

        $response = $this->register(
            ['name' => 'Nadia Haq', 'email' => self::PAYER],
            [['name' => 'Yusuf Haq', 'email' => self::TYPED]],
        );
        $response->assertStatus(200);

        $registrants = $this->registrantContacts();

        $this->assertCount(1, $registrants);
        $this->assertSame(
            self::TYPED,
            $registrants->first()->email,
            'The address was blanked because a look-alike was read as its holder.',
        );

        $this->assertNothingReachedTheContactOnRecord($onRecord, $before, $response);
    }

    #[Test]
    public function the_oldest_rule_reads_the_exact_holders_only(): void
    {
        // The look-alike has the LOWEST id, so an unfiltered `first()` returns
        // it. The rule is unchanged, "the oldest contact holding the address",
        // and it now ranges over the exact holders alone.
        $lookAlike = $this->contact(self::STORED, 'Musa', 'Salim');
        $firstExact = $this->contact('  Parent@GMAIL.com ', 'Aisha', 'Salim');
        $secondExact = $this->contact(self::TYPED, 'Bilal', 'Salim');
        $this->assertTheShortlistStillFinds([$lookAlike, $firstExact, $secondExact]);
        $before = $this->stored($lookAlike);

        $response = $this->register(
            ['name' => 'Nadia Haq', 'email' => self::TYPED],
            [['name' => 'Yusuf Haq']],
        );
        $response->assertStatus(200);

        $registration = Registration::withoutMasjidScope()->sole();

        $this->assertSame($firstExact->id, (int) $registration->contact_id, 'The oldest EXACT holder was not the one found.');
        $this->assertSame(
            4,
            Contact::withoutMasjidScope()->count(),
            'A contact was created for an address that contacts already hold (three holders plus the registrant).',
        );

        $this->assertNothingReachedTheContactOnRecord($lookAlike, $before, $response);
    }

    // ------------------------------------- the exact address still attaches

    #[Test]
    public function a_payer_at_the_exact_address_still_attaches_to_the_same_contact(): void
    {
        // Differs from what is stored only in case and surrounding spaces.
        $holder = $this->contact('  Parent@GMAIL.com ', 'Musa', 'Salim');
        $this->assertTheShortlistStillFinds([$holder]);

        $this->register(
            ['name' => 'Nadia Haq', 'email' => 'PARENT@Gmail.com'],
            [['name' => 'Yusuf Haq']],
        )->assertStatus(200);

        $registration = Registration::withoutMasjidScope()->sole();

        $this->assertSame($holder->id, (int) $registration->contact_id, 'The payer did not attach to the contact holding the address.');
        $this->assertSame(2, Contact::withoutMasjidScope()->count(), 'A second contact was made for the same address.');
    }

    #[Test]
    public function a_registrant_at_the_exact_address_still_attaches_to_the_same_contact(): void
    {
        $holder = $this->contact('  Parent@GMAIL.com ', 'Musa', 'Salim');
        $this->assertTheShortlistStillFinds([$holder]);

        $this->register(
            ['name' => 'Nadia Haq', 'email' => self::PAYER],
            [['name' => 'Musa Salim', 'email' => 'PARENT@Gmail.com']],
        )->assertStatus(200);

        $registrants = $this->registrantContacts();

        $this->assertSame([$holder->id], $registrants->pluck('id')->all(), 'The registrant did not attach to the contact holding the address.');
        $this->assertSame(2, Contact::withoutMasjidScope()->count(), 'A second contact was made for the same person (the holder plus the payer).');
    }

    #[Test]
    public function an_exact_holder_still_blanks_the_new_contacts_address(): void
    {
        // The household-mailbox rule, unchanged: a second person on an address
        // somebody already holds is written with no address.
        $holder = $this->contact('  Parent@GMAIL.com ', 'Musa', 'Salim');
        $this->assertTheShortlistStillFinds([$holder]);
        $before = $this->stored($holder);

        $this->register(
            ['name' => 'Nadia Haq', 'email' => self::PAYER],
            [['name' => 'Yusuf Haq', 'email' => self::TYPED]],
        )->assertStatus(200);

        $registrants = $this->registrantContacts();

        $this->assertCount(1, $registrants);
        $this->assertNotSame($holder->id, $registrants->first()->id);
        $this->assertNull($registrants->first()->email, 'The household-mailbox rule no longer blanks the second person\'s address.');
        $this->assertSame($before, $this->stored($holder), 'The holder was written to.');
    }

    // ---------------------------------------------------------------- helpers

    private function contact(string $email, string $first, string $last): Contact
    {
        $contact = new Contact();
        $contact->forceFill([
            'masjid_id' => $this->masjid->id,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
        ])->save();

        return $contact->refresh();
    }

    /**
     * The exact query `findByAddress()` shortlists with.
     *
     * @param  list<Contact>  $expected
     */
    private function assertTheShortlistStillFinds(array $expected): void
    {
        $this->assertSame(
            array_map(fn (Contact $c) => $c->id, $expected),
            Contact::withoutMasjidScope()
                ->where('masjid_id', $this->masjid->id)
                ->whereNotNull('email')
                ->whereRaw('LOWER(TRIM(email)) = ?', [self::TYPED])
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            'PREMISE: LOWER(TRIM(email)) = the typed address must return the contact on record too, or this test proves nothing.',
        );
    }

    /** @return array<string, mixed> */
    private function stored(Contact $contact): array
    {
        return Contact::withoutMasjidScope()->withTrashed()->whereKey($contact->getKey())->firstOrFail()->getAttributes();
    }

    /** The contacts the one registration is FOR. */
    private function registrantContacts(): Collection
    {
        $registration = Registration::withoutMasjidScope()->sole();

        return Contact::withoutMasjidScope()
            ->whereIn('id', Registrant::withoutMasjidScope()->where('registration_id', $registration->id)->pluck('contact_id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * Not attached to, not modified, not revealed: the contact holds no
     * registrant row, no roster row and no guardian edge, is byte for byte what
     * it was, and the public response carries nothing of it.
     *
     * @param  array<string, mixed>  $before
     */
    private function assertNothingReachedTheContactOnRecord(Contact $onRecord, array $before, TestResponse $response): void
    {
        $this->assertSame(0, Registration::withoutMasjidScope()->where('contact_id', $onRecord->id)->count(), 'The contact on record is the payer of a registration.');
        $this->assertSame(0, Registrant::withoutMasjidScope()->where('contact_id', $onRecord->id)->count(), 'The contact on record is a registrant.');
        $this->assertSame(0, GroupMembership::withoutMasjidScope()->where('contact_id', $onRecord->id)->count(), 'The contact on record was put on a roster or made a guardian.');
        $this->assertSame(0, GroupMembership::withoutMasjidScope()->where('guardian_of_contact_id', $onRecord->id)->count(), 'The contact on record was made a ward.');
        $this->assertSame($before, $this->stored($onRecord), 'The contact on record was written to.');

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString((string) $onRecord->last_name, $body, 'The response revealed the contact on record.');
        $this->assertStringNotContainsString((string) $onRecord->first_name, $body, 'The response revealed the contact on record.');
    }

    /**
     * @param  array{name:string,email:string}  $payer
     * @param  list<array<string, string>>  $registrants
     */
    private function register(array $payer, array $registrants): TestResponse
    {
        app(TenantContext::class)->forgetTenant();

        return $this->postJson("/api/v1/offerings/{$this->offering->slug}/register", [
            'fee_plan_id' => $this->plan->id,
            'payer' => $payer,
            'registrants' => $registrants,
            'data' => ['full_name' => $payer['name']],
        ], ['masjid-id' => (string) $this->masjid->id]);
    }
}
