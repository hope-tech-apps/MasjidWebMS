<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactLoginEvent;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * "Who else holds this sign-in address?" answers for the address that was
 * TYPED, not for whatever the collation calls equal.
 *
 * FamilyAccessService::currentHolderOf() finds the contact an operator's new
 * address would collide with, and a confirmed `reassign_address` then RELEASES
 * the address from that holder. `contacts.login_email` is `utf8mb4_unicode_ci`
 * on production, so a look-alike (`parent@gmail.com` against a stored
 * `parent@gmaíl.com`) was found as the holder and had its real address
 * stripped on the strength of a different one.
 *
 * Built with the same premise as the sign-in tests (Tests\Support\
 * FoldsAccentsLikeUnicodeCi): the holder's address is stored ACCENTED, the plain
 * one is submitted, and `LOWER()` folds accents. Where the test needs the unique
 * index to refuse the second address, as production's does, the index is
 * collated too.
 */
class FamilyAddressHolderLookAlikeTest extends TestCase
{
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    /** What the holder has: the accented form. */
    private const STORED = 'parent@gmaíl.com';

    /** What the operator types: the plain one. */
    private const TYPED = 'parent@gmail.com';

    private Masjid $masjid;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->foldAccentsLikeUnicodeCi();

        $this->masjid = $this->makeMasjid();
        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();
    }

    protected function tearDown(): void
    {
        $this->stopFoldingAccents();

        parent::tearDown();
    }

    #[Test]
    public function a_confirmed_reassignment_does_not_strip_the_address_from_a_look_alike_holder(): void
    {
        $holder = $this->guardian(self::STORED, revoked: true);
        $claimant = $this->guardian(null);
        $this->assertTheSqlStillFindsTheHolder($holder);
        $before = $this->raw($holder)->getAttributes();

        $this->asAdmin();
        $this->postJson($this->url($claimant), [
            'login_email' => self::TYPED,
            'reassign_address' => true,
        ])->assertOk();

        // The claimant got the address they typed, and the holder kept theirs:
        // there is nothing to release, because nobody holds THAT address.
        $this->assertSame(self::TYPED, $this->raw($claimant)->login_email);
        $this->assertSame($before, $this->raw($holder)->getAttributes(), 'The look-alike holder was written to.');
        $this->assertSame(self::STORED, $this->raw($holder)->login_email);
        $this->assertSame(0, $this->events($holder)->count(), 'An address_released row was written for the holder.');
    }

    #[Test]
    public function against_the_production_index_a_look_alike_is_a_clean_refusal_and_the_holder_keeps_the_address(): void
    {
        // Production's unique index is collation-equal too, so storing the typed
        // address would collide with the holder's.
        $this->collateContactLoginEmailIndexLikeUnicodeCi();

        $holder = $this->guardian(self::STORED, revoked: true);
        $claimant = $this->guardian(null);
        $this->assertTheSqlStillFindsTheHolder($holder);
        $holderBefore = $this->raw($holder)->getAttributes();
        $claimantBefore = $this->raw($claimant)->getAttributes();

        $this->asAdmin();
        $response = $this->postJson($this->url($claimant), [
            'login_email' => self::TYPED,
            'reassign_address' => true,
        ]);

        // A refusal in the service's own voice, not a 500 and not a release.
        $response->assertStatus(422)->assertJsonPath('status', 'error')->assertJsonPath('reassignable', false);
        $this->assertSame($holderBefore, $this->raw($holder)->getAttributes(), 'The holder lost or changed their address.');
        $this->assertSame($claimantBefore, $this->raw($claimant)->getAttributes(), 'The claimant was written to.');
        $this->assertSame(0, $this->events($holder)->count());
        $this->assertSame(0, $this->events($claimant)->count());
    }

    #[Test]
    public function a_holder_whose_address_differs_only_in_case_is_still_found(): void
    {
        $holder = $this->guardian('Parent@Gmail.com', revoked: true);
        $claimant = $this->guardian(null);

        $this->asAdmin();
        $this->postJson($this->url($claimant), ['login_email' => 'PARENT@gmail.com'])
            ->assertStatus(422)
            ->assertJsonPath('reassignable', true);

        $this->assertNull($this->raw($claimant)->login_email);
        $this->assertSame('Parent@Gmail.com', $this->raw($holder)->login_email);
    }

    // ---------------------------------------------------------------- helpers

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

    /**
     * A contact who is somebody's guardian, the only kind that may hold a family
     * login, optionally already holding `$loginEmail` with the access ended.
     */
    private function guardian(?string $loginEmail, bool $revoked = false): Contact
    {
        $guardian = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        $name = 'Class ' . uniqid();
        $group = Group::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'kind' => 'class',
        ]);
        $ward = Contact::factory()->create(['masjid_id' => $this->masjid->id]);

        GroupMembership::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $group->id,
            'contact_id' => $ward->id,
            'role' => GroupMembership::ROLE_MEMBER,
            'joined_at' => now(),
        ]);
        GroupMembership::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $group->id,
            'contact_id' => $guardian->id,
            'role' => GroupMembership::ROLE_GUARDIAN,
            'guardian_of_contact_id' => $ward->id,
            'joined_at' => now(),
        ]);

        if ($loginEmail !== null) {
            $guardian->forceFill([
                'login_email' => $loginEmail,
                'login_enabled_at' => now(),
                'login_revoked_at' => $revoked ? now() : null,
            ])->save();
        }

        return $guardian->refresh();
    }

    private function url(Contact $contact): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/contacts/{$contact->id}/family-login";
    }

    private function asAdmin(): void
    {
        Auth::forgetGuards();
        app(TenantContext::class)->forgetTenant();
        Sanctum::actingAs($this->admin);
    }

    private function raw(Contact $contact): Contact
    {
        return Contact::withoutMasjidScope()->withTrashed()->findOrFail($contact->id);
    }

    private function events(Contact $contact)
    {
        return ContactLoginEvent::withoutMasjidScope()->where('contact_id', $contact->id)->get();
    }

    /** PREMISE: the collation-equal query returns the holder, as MySQL would. */
    private function assertTheSqlStillFindsTheHolder(Contact $holder): void
    {
        $this->assertSame(
            [$holder->id],
            Contact::withoutMasjidScope()->withTrashed()->whereRaw('LOWER(login_email) = ?', [self::TYPED])->pluck('id')->all(),
            'PREMISE: LOWER(login_email) = the typed address must return the holder, or this test proves nothing.',
        );
    }
}
