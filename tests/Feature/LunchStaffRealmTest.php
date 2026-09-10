<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\MealMenu;
use App\Models\MealMenuItem;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A staff login scoped to the Jummah-lunch board and NOTHING else.
 *
 * The valuable half of this suite is the refusals. A scoped role is only worth
 * having if the scope holds, so the boundary is asserted from both directions:
 * everything a lunch login MAY do, and — endpoint by endpoint, across the admin
 * API's most sensitive surfaces — everything it may not.
 *
 * The escalation guarded hardest is the one a MasjidAdmin could otherwise
 * perform on themselves: the provisioning endpoint never reads `type` from the
 * request, so no body can turn "create a volunteer" into "create an admin".
 */
class LunchStaffRealmTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private Masjid $other;

    private User $admin;

    private User $lunchStaff;

    private MealMenu $menu;

    private MealMenuItem $plate;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjid = $this->makeMasjid();
        $this->other = $this->makeMasjid();

        $this->admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $this->masjid->user_id = $this->admin->id;
        $this->masjid->save();

        $this->lunchStaff = $this->makeLunchStaffFor($this->masjid);

        app(TenantContext::class)->forgetTenant();

        $this->menu = MealMenu::factory()->forMasjid($this->masjid)->open()->create();
        $this->plate = MealMenuItem::factory()->create([
            'masjid_id' => $this->masjid->id,
            'meal_menu_id' => $this->menu->id,
            'name' => 'Kabsah Plate',
            'price_minor' => 800,
        ]);
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Masjid ' . uniqid(),
            'email' => 'm' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
        ]);
    }

    private function makeLunchStaffFor(Masjid $masjid): User
    {
        $user = User::factory()->create([
            'type' => User::TYPE_LUNCH_STAFF,
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        MasjidUser::create([
            'masjid_id' => $masjid->id,
            'user_id' => $user->id,
            'role' => 'lunch-staff',
            'is_default' => true,
        ]);

        return $user;
    }

    /** The lunch realm's own prefix — the id is CHECKED by the resolver, not trusted. */
    private function lunchBase(): string
    {
        return '/api/lunch/masjids/' . $this->masjid->id . '/jummah-lunch';
    }

    private function adminBase(): string
    {
        return '/api/admin/masjids/' . $this->masjid->id;
    }

    // ------------------------------------------------------- what they CAN do

    #[Test]
    public function lunch_staff_can_run_the_board_for_their_own_masjid(): void
    {
        Sanctum::actingAs($this->lunchStaff);

        // The menu list, resolved against a tenant bound from their membership —
        // there is no masjid id in any of these URLs to get wrong.
        $this->getJson($this->lunchBase() . '/menus')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->menu->id);

        $this->getJson($this->lunchBase() . '/menus/' . $this->menu->id)
            ->assertOk()
            ->assertJsonPath('data.id', $this->menu->id);

        $this->getJson($this->lunchBase() . '/menus/' . $this->menu->id . '/orders')
            ->assertOk()
            ->assertJsonPath('data.menu.id', $this->menu->id);
    }

    #[Test]
    public function lunch_staff_can_do_the_work_the_kitchen_actually_needs(): void
    {
        Sanctum::actingAs($this->lunchStaff);

        // Open next week's menu...
        $created = $this->postJson($this->lunchBase() . '/menus', [
            'title' => 'Jummah Lunch',
            'service_date' => '2027-05-07',
        ])->assertStatus(201);

        $menuId = $created->json('data.id');

        // ...add a dish...
        $this->postJson($this->lunchBase() . '/menus/' . $menuId . '/items', [
            'name' => 'Biryani',
            'price_minor' => 900,
        ])->assertStatus(201);

        // ...and open it for orders.
        $this->putJson($this->lunchBase() . '/menus/' . $menuId, [
            'status' => MealMenu::STATUS_OPEN,
        ])->assertOk();

        $this->assertSame(
            $this->masjid->id,
            (int) MealMenu::withoutMasjidScope()->find($menuId)->masjid_id,
            'the menu must be stamped with the tenant bound from their membership'
        );
    }

    // ---------------------------------------------------- what they CANNOT do

    #[Test]
    public function the_realm_can_re_identify_its_own_principal(): void
    {
        // The SPA re-fetches the signed-in user on EVERY page load, and the
        // admin /user route is `admin`-gated. Without a realm-local one the
        // reload 401s, boot reads that as a dead session, and the login works
        // while refreshing the page signs them straight back out.
        Sanctum::actingAs($this->lunchStaff);

        $this->getJson('/api/lunch/user')
            ->assertOk()
            ->assertJsonPath('data.type', User::TYPE_LUNCH_STAFF)
            // Their masjid rides along, because their shell names the org and
            // the store builds its URLs from it.
            ->assertJsonPath('data.masjid.id', $this->masjid->id);

        // ...and the admin one still refuses them, which is why the above exists.
        $this->getJson('/api/admin/user')->assertStatus(401);
    }

    #[Test]
    public function an_admin_cannot_re_identify_through_the_lunch_realm(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/lunch/user')->assertStatus(401);
    }

    #[Test]
    public function lunch_staff_are_refused_by_every_admin_endpoint(): void
    {
        Sanctum::actingAs($this->lunchStaff);

        // One assertion per surface, because "scoped" is a claim about all of
        // them. `admin` answers 401 with the legacy envelope the SPA switches on.
        $endpoints = [
            ['get', $this->adminBase() . '/contacts'],
            ['get', $this->adminBase() . '/donations'],
            ['get', $this->adminBase() . '/funds'],
            ['get', $this->adminBase() . '/broadcasts'],
            ['get', $this->adminBase() . '/teachers'],
            ['get', $this->adminBase() . '/administrators'],
            ['get', $this->adminBase() . '/events'],
            ['get', $this->adminBase() . '/announcements'],
            ['get', '/api/admin/masjids'],
        ];

        foreach ($endpoints as [$verb, $url]) {
            $this->json(strtoupper($verb), $url)
                ->assertStatus(401, "lunch staff reached {$url}");
        }
    }

    #[Test]
    public function lunch_staff_cannot_reach_the_jummah_lunch_ADMIN_routes_either(): void
    {
        // The same module, through the admin door. It must refuse them, because
        // that door carries a masjid id they could edit.
        Sanctum::actingAs($this->lunchStaff);

        $this->getJson($this->adminBase() . '/jummah-lunch/menus')->assertStatus(401);
        $this->getJson('/api/admin/masjids/' . $this->other->id . '/jummah-lunch/menus')->assertStatus(401);
    }

    #[Test]
    public function lunch_staff_cannot_create_more_lunch_staff(): void
    {
        // No self-replication: provisioning lives behind `admin`.
        Sanctum::actingAs($this->lunchStaff);

        $this->postJson($this->adminBase() . '/jummah-lunch/staff', [
            'name' => 'A Friend',
            'email' => 'friend@example.invalid',
        ])->assertStatus(401);

        $this->getJson($this->adminBase() . '/jummah-lunch/staff')->assertStatus(401);
    }

    #[Test]
    public function lunch_staff_cannot_reach_the_teacher_realm(): void
    {
        Sanctum::actingAs($this->lunchStaff);

        $this->getJson('/api/teacher/masjids/' . $this->masjid->id . '/behavior-skills')->assertStatus(401);
    }

    #[Test]
    public function lunch_staff_cannot_delete_a_menu(): void
    {
        // Deleting a menu takes the orders customers placed with it. That is an
        // owner's decision, so the route is simply not in their realm.
        Sanctum::actingAs($this->lunchStaff);

        $this->deleteJson($this->lunchBase() . '/menus/' . $this->menu->id)->assertStatus(405);
    }

    #[Test]
    public function lunch_staff_see_only_their_own_masjids_lunch(): void
    {
        app(TenantContext::class)->forgetTenant();
        $foreignMenu = MealMenu::factory()->forMasjid($this->other)->open()->create();

        Sanctum::actingAs($this->lunchStaff);

        // Their list contains theirs and not the other masjid's...
        $ids = collect($this->getJson($this->lunchBase() . '/menus')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->menu->id));
        $this->assertFalse($ids->contains($foreignMenu->id));

        // ...naming the foreign MENU under their own masjid is a miss...
        $this->getJson($this->lunchBase() . '/menus/' . $foreignMenu->id)->assertStatus(404);

        // ...and putting the other masjid's id in the URL is refused by the
        // resolver before any controller runs. This is the assertion that makes
        // the id in the path safe.
        $this->getJson('/api/lunch/masjids/' . $this->other->id . '/jummah-lunch/menus')
            ->assertStatus(403);
    }

    #[Test]
    public function a_lunch_login_with_no_membership_fails_closed(): void
    {
        // Never unbound — tenant-scoping.md defines unbound as NO filter, which
        // on this realm would be every masjid's orders.
        $orphan = User::factory()->create([
            'type' => User::TYPE_LUNCH_STAFF,
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        Sanctum::actingAs($orphan);

        $this->getJson($this->lunchBase() . '/menus')->assertStatus(403);
    }

    #[Test]
    public function an_admin_is_still_refused_by_the_lunch_realm(): void
    {
        // The gate is a type check in both directions; an admin uses the admin
        // door. This keeps the two realms from quietly becoming one.
        Sanctum::actingAs($this->admin);

        $this->getJson($this->lunchBase() . '/menus')->assertStatus(401);
    }

    #[Test]
    public function the_lunch_realm_refuses_an_unauthenticated_caller(): void
    {
        $this->getJson($this->lunchBase() . '/menus')->assertStatus(401);
    }

    // ------------------------------------------------------------ provisioning

    #[Test]
    public function a_masjid_admin_creates_a_lunch_login_and_it_is_scoped(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson($this->adminBase() . '/jummah-lunch/staff', [
            'name' => 'Umm Khalid',
            'email' => 'kitchen@example.invalid',
            'phone' => '+17045550123',
        ])->assertStatus(201);

        $id = $res->json('data.id');
        $created = User::find($id);

        $this->assertSame(User::TYPE_LUNCH_STAFF, $created->type);
        $this->assertSame('lunch-staff', $created->getRoleNames()->first());
        // The membership is the whole of their reach.
        $this->assertDatabaseHas('masjid_user', [
            'masjid_id' => $this->masjid->id,
            'user_id' => $created->id,
        ]);
        // And no CRM grant came with it.
        $this->assertFalse($created->hasPermissionTo('view contacts'));
    }

    #[Test]
    public function the_request_body_cannot_ask_for_a_different_type(): void
    {
        // THE ESCALATION THIS ENDPOINT EXISTS TO REFUSE. A MasjidAdmin holding
        // it must not be able to mint a second admin — or a SuperAdmin — by
        // adding one field to the body.
        Sanctum::actingAs($this->admin);

        $res = $this->postJson($this->adminBase() . '/jummah-lunch/staff', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.invalid',
            'type' => 'SuperAdmin',
        ])->assertStatus(201);

        $this->assertSame(User::TYPE_LUNCH_STAFF, User::find($res->json('data.id'))->type);
    }

    #[Test]
    public function a_masjid_admin_cannot_provision_at_another_masjid(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/masjids/' . $this->other->id . '/jummah-lunch/staff', [
            'name' => 'Elsewhere',
            'email' => 'elsewhere@example.invalid',
        ])->assertStatus(403);
    }

    #[Test]
    public function the_endpoint_will_not_edit_or_delete_an_admin_by_id(): void
    {
        // resolve() checks the TYPE as well as the membership; without it this
        // would be a rename-and-delete button pointed at the masjid's owner.
        Sanctum::actingAs($this->admin);

        $this->putJson($this->adminBase() . '/jummah-lunch/staff/' . $this->admin->id, [
            'name' => 'Renamed',
        ])->assertStatus(404);

        $this->deleteJson($this->adminBase() . '/jummah-lunch/staff/' . $this->admin->id)
            ->assertStatus(404);

        $this->assertNotNull(User::find($this->admin->id));
    }

    #[Test]
    public function another_masjids_lunch_login_is_a_404_not_a_403(): void
    {
        $foreign = $this->makeLunchStaffFor($this->other);

        Sanctum::actingAs($this->admin);

        // An admin has no business learning that some other id exists.
        $this->deleteJson($this->adminBase() . '/jummah-lunch/staff/' . $foreign->id)
            ->assertStatus(404);

        $this->assertNotNull(User::find($foreign->id));
    }

    #[Test]
    public function removing_access_kills_the_live_session_immediately(): void
    {
        $token = $this->lunchStaff->createToken('t')->plainTextToken;

        Sanctum::actingAs($this->admin);
        $this->deleteJson($this->adminBase() . '/jummah-lunch/staff/' . $this->lunchStaff->id)
            ->assertOk();

        // The credential stops EXISTING, not merely being refused — a browser
        // still holding it gets nothing.
        $this->assertSame(0, $this->lunchStaff->tokens()->count());

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson($this->lunchBase() . '/menus')
            ->assertStatus(401);
    }

    #[Test]
    public function the_permission_count_invariant_is_untouched(): void
    {
        // A whole new role, and not one new permission — the same discipline
        // the teacher role kept.
        $this->assertSame(8, Permission::count());
    }
}
