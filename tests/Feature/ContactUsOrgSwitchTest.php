<?php

namespace Tests\Feature;

use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Contacting a child organisation from an app that is registered with its
 * parent.
 *
 * ## The defect
 *
 * The apps ship an organisation switcher. `MasjidsController::orgs` offers a
 * device its HOME organisation plus that organisation's LISTED children, and a
 * member who walks into a child sends Contact Us at the CHILD's masjid id — but
 * their device row is still pinned to home, because switching organisations
 * re-registers nothing. The lookup was `where('masjid_id', $routeMasjidId)`, so
 * every one of those messages came back "This device is not registered with
 * this organization." on iOS and Android alike, and the member had no way to
 * reach the school they were looking at.
 *
 * ## What must NOT be given up to fix it
 *
 * That masjid clause is a security fix (`ContactUsIdentityTest`): `device_id` is
 * an unverified claim from an unauthenticated caller, and an unscoped lookup let
 * a device id from one organisation resolve to another organisation's record.
 * The widening is therefore exactly the switcher's own set and no wider, which
 * is what the four refusals below pin — an unrelated organisation, an UNLISTED
 * child (nothing offers it, so nobody can legitimately be standing in it), a
 * device that does not exist, and, in `ContactUsIdentityTest`, the original
 * cross-tenant case.
 */
class ContactUsOrgSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $home;

    private Masjid $listedChild;

    private Masjid $unlistedChild;

    private Masjid $stranger;

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

        $this->home = $this->org('Muslim Education Center');
        $this->listedChild = $this->org('IntelliCor Academy', listed: true);
        $this->unlistedChild = $this->org('Al-Bayan', listed: false);
        $this->stranger = $this->org('Somewhere Else', listed: true);

        $this->listedChild->setParent($this->home);
        $this->unlistedChild->setParent($this->home);

        $this->device('home-device', $this->home);
    }

    #[Test]
    public function the_home_organisation_still_accepts_its_own_device(): void
    {
        // The unchanged path. If this ever stops working the fix has replaced
        // the rule instead of widening it.
        $this->post("/api/mobile/masjids/{$this->home->id}/contact-us", $this->payload())
            ->assertOk();

        $this->assertSame(1, ContactUsMessage::count());
    }

    #[Test]
    public function a_listed_child_accepts_a_device_registered_with_its_parent(): void
    {
        $response = $this->post(
            "/api/mobile/masjids/{$this->listedChild->id}/contact-us",
            $this->payload()
        );

        $response->assertOk();
        $this->assertSame(1, ContactUsMessage::count());

        // Filed under the CHILD — the organisation the notifier emails — and
        // not under the device's home. The two used to be decided separately;
        // now one variable decides both. See ContactUsMessageOrgTest.
        $this->assertSame($this->listedChild->id, ContactUsMessage::first()->masjid_id);

        // No second registration was minted for the child. The message hangs off
        // the ONE device row the handset has always had.
        $this->assertSame(1, MobileAppUser::count());
    }

    #[Test]
    public function a_message_sent_into_a_child_is_listable_there_and_not_at_the_parent(): void
    {
        // The half that makes the fix worth shipping. Accepting the message is
        // not enough: it has to land in the inbox of the organisation whose
        // staff were emailed about it. Filed by the device instead, it was
        // emailed to the child and listable only by the parent's admins —
        // delivered and unfindable at the same time.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $childAdmin = $this->adminFor($this->listedChild);
        $homeAdmin = $this->adminFor($this->home);

        $needle = 'When does registration open?';

        $this->post(
            "/api/mobile/masjids/{$this->listedChild->id}/contact-us",
            $this->payload(['message' => $needle])
        )->assertOk();

        Sanctum::actingAs($childAdmin);
        $this->assertStringContainsString(
            $needle,
            $this->getJson("/api/admin/masjids/{$this->listedChild->id}/contact-requests")->assertOk()->getContent(),
            "the child's own admins cannot open a message their office was emailed about"
        );

        Sanctum::actingAs($homeAdmin);
        $this->assertStringNotContainsString(
            $needle,
            $this->getJson("/api/admin/masjids/{$this->home->id}/contact-requests")->assertOk()->getContent(),
            'the parent is listing a message that was never addressed to it'
        );
    }

    #[Test]
    public function an_unlisted_child_is_still_refused(): void
    {
        // `listed_at` is the act of publishing an organisation. An unlisted one
        // appears in no switcher, so no member can legitimately be standing in
        // it, and accepting here would quietly make an unpublished organisation
        // writable from the outside.
        $this->post("/api/mobile/masjids/{$this->unlistedChild->id}/contact-us", $this->payload())
            ->assertStatus(404);

        $this->assertSame(0, ContactUsMessage::count());
    }

    #[Test]
    public function an_unrelated_organisation_is_still_refused(): void
    {
        // The original security case, restated against the new lookup: no parent
        // link in either direction, so the device is a stranger here.
        $this->post("/api/mobile/masjids/{$this->stranger->id}/contact-us", $this->payload())
            ->assertStatus(404);

        $this->assertSame(0, ContactUsMessage::count());
    }

    #[Test]
    public function a_child_device_cannot_write_to_the_parent(): void
    {
        // The switcher offers parent -> child, not child -> parent, and the
        // acceptance must not be symmetric by accident: a device registered with
        // the child is not thereby entitled to file into the parent's inbox.
        $this->device('child-device', $this->listedChild);

        $this->post(
            "/api/mobile/masjids/{$this->home->id}/contact-us",
            $this->payload(['device_id' => 'child-device'])
        )->assertStatus(404);

        $this->assertSame(0, ContactUsMessage::count());
    }

    #[Test]
    public function a_device_that_does_not_exist_is_refused_and_writes_nothing(): void
    {
        $response = $this->post(
            "/api/mobile/masjids/{$this->listedChild->id}/contact-us",
            $this->payload(['device_id' => 'no-such-device'])
        );

        // 422, not the controller's 404: `device_id` carries
        // `exists:mobile_app_users,device_id`, so validation refuses an unknown
        // id before storeMessage() runs. Both are accepted here because the
        // guarantee this test owns is "refused, and nothing was written" — the
        // status is the FormRequest's business, and pinning it to one value
        // would fail this test for a change that is not a regression. What is
        // pinned hard is that it is neither a success nor a 500: the pre-fix
        // code dereferenced null here and returned a device_id existence oracle.
        $this->assertContains(
            $response->status(),
            [404, 422],
            'expected a refusal, got '.$response->status()
        );

        $this->assertSame(0, ContactUsMessage::count());
        $this->assertSame(0, ContactUsAccount::count());
    }

    #[Test]
    public function the_switcher_and_this_endpoint_agree_on_which_children_count(): void
    {
        // The two halves of one rule, asserted against each other rather than
        // both against a hardcoded list. `orgs` is what the handset was looking
        // at when the member tapped Contact Us; if it offers an organisation
        // this endpoint refuses, the member meets a dead end again.
        $offered = collect(
            $this->getJson("/api/mobile/masjids/{$this->home->id}/orgs")
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->home->id, $this->listedChild->id], $offered);

        foreach ($offered as $orgId) {
            $this->post("/api/mobile/masjids/{$orgId}/contact-us", $this->payload())
                ->assertOk();
        }
    }

    // ------------------------------------------------------------- helpers

    /**
     * Posted FORM-ENCODED, not as JSON.
     *
     * The apps post this form the way a form is posted, and .claude/rules/
     * shipping.md is the record of what a suite that only ever used `postJson`
     * failed to see. Nothing here is a boolean, so the encoding costs nothing —
     * but it is the encoding the endpoint actually meets.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'device_id' => 'home-device',
            'name' => 'A Member',
            'email' => 'member@example.com',
            'phone' => '+15550000001',
            'reason_text' => 'Admissions',
            'message' => 'Assalamu alaikum, when does registration open?',
        ], $overrides);
    }

    /**
     * A MasjidAdmin who OWNS this organisation.
     *
     * Ownership (`masjids.user_id`), not membership: that is what
     * ResolveMasjidTenant reads, and an admin minted the other way logs in and
     * then 403s on its own organisation.
     */
    private function adminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1'.random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function device(string $deviceId, Masjid $masjid): MobileAppUser
    {
        return MobileAppUser::create([
            'device_id' => $deviceId,
            'masjid_id' => $masjid->id,
            'user_agent' => 'test',
        ]);
    }

    /**
     * `listed_at` and `parent_id` are both deliberately NOT fillable on Masjid —
     * publishing an organisation and re-parenting one are their own acts, not
     * side effects of creating a row — so the fixture forces one and calls
     * `setParent()` for the other. Passing either to `create()` silently does
     * nothing, which is how a test of this shape can "prove" an unlisted child
     * is hidden while in fact nothing was ever published (OrgHierarchyTest).
     */
    private function org(string $name, bool $listed = true): Masjid
    {
        $org = Masjid::create([
            'name' => $name,
            'email' => 'org-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        if ($listed) {
            $org->forceFill(['listed_at' => now()])->save();
        }

        return $org->fresh();
    }
}
