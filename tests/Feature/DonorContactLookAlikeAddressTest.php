<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Masjid;
use App\Services\Crm\DonorContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * A donor is the contact holding their address EXACTLY.
 *
 * THE DEFECT (the point's review of b9f11d4c, 2026-09-29). `findOrCreateForMasjid()`
 * matched a Stripe customer's email with `where('email', $email)->first()`.
 * `contacts.email` is utf8mb4_unicode_ci on production (read 2026-09-29), where
 * `donor@gmail.com` = `donor@gmaíl.com`. A contact registered through a PUBLIC
 * door with the look-alike address therefore captured the real donor's later
 * gifts, and their receipts were mailed to the look-alike.
 *
 * HOW THESE TESTS REACH IT ON SQLITE (Tests\Support\FoldsAccentsLikeUnicodeCi):
 * this lookup compares with `where('email', ...)` and never calls LOWER, so the
 * column itself is given the collation. The look-alike's address is stored
 * ACCENTED and the plain one arrives from Stripe; every test asserts that the raw
 * query returns the look-alike before asserting the service refuses it.
 */
class DonorContactLookAlikeAddressTest extends TestCase
{
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    /** What the look-alike contact holds: the accented form. */
    private const STORED = 'donor@gmaíl.com';

    /** What Stripe reports for the real donor. */
    private const TYPED = 'donor@gmail.com';

    private Masjid $masjid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collateColumnLikeUnicodeCi('contacts', 'email');

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true,
        ]);
    }

    #[Test]
    public function a_look_alike_contact_does_not_capture_the_real_donors_gift(): void
    {
        $lookAlike = $this->contact(self::STORED);
        $this->assertTheSqlStillFinds([$lookAlike]);
        $before = $this->stored($lookAlike);

        $donor = $this->service()->findOrCreateForMasjid($this->masjid->id, [
            'email' => self::TYPED,
            'name' => 'Real Donor',
        ]);

        $this->assertNotNull($donor);
        $this->assertNotSame($lookAlike->id, $donor->id, 'The look-alike contact was taken for the donor.');
        $this->assertSame(self::TYPED, $donor->email, 'Receipts go to the address the donor gave.');
        $this->assertSame($before, $this->stored($lookAlike), 'The look-alike contact was written to.');
    }

    #[Test]
    public function the_oldest_contact_wins_among_the_exact_holders_only(): void
    {
        // The look-alike has the LOWEST id, so an unfiltered `first()` returns it.
        // The tie rule is unchanged, oldest first, and ranges over the exact
        // holders alone.
        $lookAlike = $this->contact(self::STORED);
        $oldestExact = $this->contact('Donor@Gmail.com');
        $newerExact = $this->contact(self::TYPED);
        $this->assertTheSqlStillFinds([$lookAlike, $oldestExact, $newerExact]);

        $donor = $this->service()->findOrCreateForMasjid($this->masjid->id, ['email' => self::TYPED]);

        $this->assertSame($oldestExact->id, $donor?->id);
        $this->assertSame(3, Contact::withoutMasjidScope()->count(), 'A contact was created for an address that contacts hold.');
    }

    #[Test]
    public function an_address_that_differs_only_in_case_or_spaces_is_still_the_same_donor(): void
    {
        $donor = $this->contact('Donor@GMAIL.com');

        $found = $this->service()->findOrCreateForMasjid($this->masjid->id, ['email' => '  donor@gmail.com ']);

        $this->assertSame($donor->id, $found?->id);
        $this->assertSame(1, Contact::withoutMasjidScope()->count());
    }

    private function service(): DonorContactService
    {
        return app(DonorContactService::class);
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

    /** @return array<string, mixed> */
    private function stored(Contact $contact): array
    {
        return Contact::withoutMasjidScope()->withTrashed()->whereKey($contact->getKey())->firstOrFail()->getAttributes();
    }

    /** @param  list<Contact>  $expected */
    private function assertTheSqlStillFinds(array $expected): void
    {
        $this->assertSame(
            array_map(fn (Contact $c) => $c->id, $expected),
            Contact::withoutMasjidScope()->where('email', self::TYPED)->orderBy('id')->pluck('id')->all(),
            'PREMISE: where(email) = the donor\'s address must return the look-alike too, or this test proves nothing.',
        );
    }
}
