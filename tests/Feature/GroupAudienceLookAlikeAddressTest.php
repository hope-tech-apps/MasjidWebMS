<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Masjid;
use App\Models\User;
use App\Support\GroupAudience;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * The staff-to-contact bridge matches the address EXACTLY.
 *
 * `GroupAudience::identitiesFor()` resolves a staff login to the contact holding
 * the same email, and what it returns is an AUTHORIZATION: that contact's wards
 * and group standing. `contacts.email` and `users.email` are
 * utf8mb4_unicode_ci on production (read 2026-09-29), where `parent@gmail.com`
 * = `parent@gmaíl.com`, and `/profile` used to let any admin-realm user set
 * their own `users.email` (ProfileEmailChangeTest). So a staff login at a
 * look-alike spelling read a real parent's children's group records.
 *
 * HOW THESE TESTS REACH IT ON SQLITE (Tests\Support\FoldsAccentsLikeUnicodeCi):
 * the contact's address is stored ACCENTED and the staff login holds the plain
 * one, with `LOWER()` overridden to fold accents, so the SQL returns the
 * contact as MySQL would. The collation is symmetric; which side carries the
 * accent is immaterial to the database and to the exact comparison alike.
 */
class GroupAudienceLookAlikeAddressTest extends TestCase
{
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    /** What the contact holds: the accented form. */
    private const CONTACT = 'parent@gmaíl.com';

    /** What the staff login holds. */
    private const STAFF = 'parent@gmail.com';

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->foldAccentsLikeUnicodeCi();

        $this->masjid = Masjid::create([
            'name' => 'School ' . uniqid(),
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true, 'org_type' => 'school',
        ]);

        app(TenantContext::class)->set($this->masjid->id);
    }

    protected function tearDown(): void
    {
        $this->stopFoldingAccents();

        parent::tearDown();
    }

    #[Test]
    public function a_staff_login_at_a_look_alike_address_is_not_the_parent_contact(): void
    {
        $parent = $this->contact(self::CONTACT);
        $this->assertTheSqlStillFinds([$parent]);

        $this->assertSame([], app(GroupAudience::class)->identitiesFor($this->staff(self::STAFF)));
    }

    #[Test]
    public function a_look_alike_contact_does_not_make_the_real_address_ambiguous_or_stand_in_for_it(): void
    {
        // Counted through the collation these were "two rows" and the real
        // parent resolved to nobody; counted as addresses there is one.
        $real = $this->contact(self::STAFF);
        $lookAlike = $this->contact(self::CONTACT);
        $this->assertTheSqlStillFinds([$real, $lookAlike]);

        $this->assertSame([$real->id], app(GroupAudience::class)->identitiesFor($this->staff(self::STAFF)));
    }

    #[Test]
    public function two_contacts_holding_the_exact_address_are_still_ambiguous(): void
    {
        $this->contact(self::STAFF);
        $this->contact('Parent@Gmail.com');

        $this->assertSame([], app(GroupAudience::class)->identitiesFor($this->staff(self::STAFF)));
    }

    #[Test]
    public function an_address_that_differs_only_in_case_or_spaces_still_bridges(): void
    {
        $parent = $this->contact('Parent@GMAIL.com');

        $this->assertSame([$parent->id], app(GroupAudience::class)->identitiesFor($this->staff(' parent@gmail.com ')));
    }

    private function contact(string $email): Contact
    {
        $contact = new Contact();
        $contact->forceFill([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'On',
            'last_name' => 'File',
            'email' => $email,
        ])->save();

        return $contact->refresh();
    }

    private function staff(string $email): User
    {
        return User::factory()->create([
            'type' => 'MasjidAdmin',
            'email' => $email,
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }

    /** @param  list<Contact>  $expected */
    private function assertTheSqlStillFinds(array $expected): void
    {
        $this->assertSame(
            array_map(fn (Contact $c) => $c->id, $expected),
            Contact::withoutMasjidScope()->whereRaw('LOWER(email) = ?', [self::STAFF])->orderBy('id')->pluck('id')->all(),
            'PREMISE: LOWER(email) = the staff login\'s address must return the look-alike too, or this test proves nothing.',
        );
    }
}
