<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a linked org and its holder see on the Connect and forms surfaces
 * (DECISIONS.md 2026-09-15, D14, D15). The child is told WHO charges its forms
 * and whether that works, never the holder's account id; it cannot start its
 * own onboarding while linked.
 */
class ConnectLinkedStatusTest extends TestCase
{
    use RefreshDatabase;

    private const HOLDER_ACCOUNT = 'acct_1TestHOLD';

    private Masjid $holder;

    private Masjid $child;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->holder = $this->makeOrg('Burlington Masjid Status', 'masjid', true, [
            'stripe_account_id' => self::HOLDER_ACCOUNT,
            'stripe_charges_enabled' => true,
        ]);
        $this->child = $this->makeOrg('BISS Status', 'school', true, [
            'parent_id' => $this->holder->id,
            'forms_card_via_masjid_id' => $this->holder->id,
        ]);

        // No test here may reach the live Stripe API; the holder's own status
        // refresh is answered from this double.
        $service = Mockery::mock(StripeConnectService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('retrieveAccount')->andReturnUsing(
            fn (string $id) => ['id' => $id, 'charges_enabled' => true, 'payouts_enabled' => true]
        );
        $service->shouldNotReceive('createAccount');
        $service->shouldNotReceive('createAccountLink');
        $this->app->instance(StripeConnectService::class, $service);
    }

    // ------------------------------------------------------------- onboarding

    #[Test]
    public function a_linked_org_cannot_start_onboarding(): void
    {
        Sanctum::actingAs($this->admin($this->child));

        $this->postJson("/api/admin/masjids/{$this->child->id}/connect/onboarding")
            ->assertStatus(409)
            ->assertJsonPath('status', 'failed');

        $this->assertNull($this->child->fresh()->stripe_account_id);
    }

    #[Test]
    public function the_service_refuses_to_create_an_account_for_a_linked_org(): void
    {
        // A partial double with NO StripeClient behind it: if the guard ever
        // regresses, the call dies on the loud double below, never on a live
        // HTTPS request to api.stripe.com from the CI host.
        $service = Mockery::mock(StripeConnectService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        foreach (['createAccount', 'createAccountLink', 'retrieveAccount'] as $method) {
            $service->shouldReceive($method)->never()->andReturnUsing(
                fn () => throw new \RuntimeException("The linked-org guard was bypassed: {$method}() reached the Stripe seam.")
            );
        }

        try {
            $service->ensureConnectedAccount($this->child->fresh());
            $this->fail('ensureConnectedAccount() returned for a linked org.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('another organisation', $e->getMessage());
        }

        $this->assertNull($this->child->fresh()->stripe_account_id);
    }

    #[Test]
    public function the_holders_status_still_lists_an_archived_child(): void
    {
        // An archived child's link comes back with it on restore, so the holder
        // must keep seeing it (and its Stop button) while it is archived.
        $this->child->delete();
        Sanctum::actingAs($this->admin($this->holder));

        $this->getJson("/api/admin/masjids/{$this->holder->id}/connect/status")
            ->assertOk()
            ->assertJsonPath('data.forms_card_for', [['id' => $this->child->id, 'name' => $this->child->name]]);
    }

    // ----------------------------------------------------------------- status

    #[Test]
    public function the_childs_status_names_the_holder_and_never_an_account_id(): void
    {
        Sanctum::actingAs($this->admin($this->child));

        $response = $this->getJson("/api/admin/masjids/{$this->child->id}/connect/status")->assertOk();

        $response->assertJsonPath('data.stripe_account_id', null)
            ->assertJsonPath('data.forms_card_via.holder.id', $this->holder->id)
            ->assertJsonPath('data.forms_card_via.holder.name', $this->holder->name)
            ->assertJsonPath('data.forms_card_via.ready', true)
            ->assertJsonPath('data.forms_card_via.problem', null)
            ->assertJsonPath('data.forms_card_for', []);
        $this->assertStringNotContainsString('acct_', $response->getContent());
    }

    #[Test]
    public function a_link_that_cannot_charge_says_why_and_still_hides_the_account(): void
    {
        $this->holder->forceFill(['stripe_charges_enabled' => false])->save();
        Sanctum::actingAs($this->admin($this->child));

        $response = $this->getJson("/api/admin/masjids/{$this->child->id}/connect/status")->assertOk();

        $response->assertJsonPath('data.forms_card_via.ready', false)
            ->assertJsonPath('data.forms_card_via.problem', 'holder_charges_disabled');
        $this->assertStringNotContainsString('acct_', $response->getContent());
    }

    #[Test]
    public function the_holders_status_lists_who_charges_through_it(): void
    {
        Sanctum::actingAs($this->admin($this->holder));

        $this->getJson("/api/admin/masjids/{$this->holder->id}/connect/status")
            ->assertOk()
            ->assertJsonPath('data.forms_card_via', null)
            ->assertJsonPath('data.forms_card_for', [['id' => $this->child->id, 'name' => $this->child->name]]);
    }

    #[Test]
    public function an_unlinked_orgs_status_is_unchanged_apart_from_the_empty_blocks(): void
    {
        $plain = $this->makeOrg('Plain Masjid Status', 'masjid', true);
        Sanctum::actingAs($this->admin($plain));

        $this->getJson("/api/admin/masjids/{$plain->id}/connect/status")
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => [
                    'stripe_account_id' => null,
                    'charges_enabled' => false,
                    'payouts_enabled' => false,
                    'forms_card_via' => null,
                    'forms_card_for' => [],
                ],
            ]);
    }

    // --------------------------------------------------- forms/card-account

    #[Test]
    public function forms_card_account_is_linked_for_the_child_and_names_the_holder(): void
    {
        Sanctum::actingAs($this->admin($this->child));

        $response = $this->getJson("/api/admin/masjids/{$this->child->id}/forms/card-account")->assertOk();

        $response->assertExactJson([
            'status' => 'success',
            'data' => [
                'state' => 'linked',
                'holder' => ['id' => $this->holder->id, 'name' => $this->holder->name],
                'problem' => null,
            ],
        ]);
        $this->assertStringNotContainsString('acct_', $response->getContent());
    }

    #[Test]
    public function forms_card_account_is_own_for_an_onboarded_org(): void
    {
        Sanctum::actingAs($this->admin($this->holder));

        $this->getJson("/api/admin/masjids/{$this->holder->id}/forms/card-account")
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => ['state' => 'own', 'holder' => null, 'problem' => null],
            ]);
    }

    #[Test]
    public function forms_card_account_is_unavailable_with_a_reason(): void
    {
        // Unlinked, never connected, and without the CRM or the manage-donations
        // permission path: the form builder can still read it.
        $plain = $this->makeOrg('Plain School Forms', 'school', false);
        Sanctum::actingAs($this->admin($plain));

        $this->getJson("/api/admin/masjids/{$plain->id}/forms/card-account")
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => ['state' => 'unavailable', 'holder' => null, 'problem' => 'not_connected'],
            ]);

        // Linked, but the holder cannot take charges: the holder is still named.
        $this->holder->forceFill(['stripe_charges_enabled' => false])->save();
        Sanctum::actingAs($this->admin($this->child));

        $response = $this->getJson("/api/admin/masjids/{$this->child->id}/forms/card-account")->assertOk();
        $response->assertJsonPath('data.state', 'unavailable')
            ->assertJsonPath('data.holder.id', $this->holder->id)
            ->assertJsonPath('data.problem', 'holder_charges_disabled');
        $this->assertStringNotContainsString('acct_', $response->getContent());
    }

    #[Test]
    public function forms_card_account_is_tenant_bound(): void
    {
        Sanctum::actingAs($this->admin($this->child));

        $this->getJson("/api/admin/masjids/{$this->holder->id}/forms/card-account")->assertStatus(403);
    }

    // ------------------------------------------------------------------ helpers

    private function makeOrg(string $name, string $orgType, bool $crm, array $forced = []): Masjid
    {
        $org = Masjid::create([
            'name' => $name . ' ' . uniqid(),
            'email' => 'status' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => $crm, 'org_type' => $orgType,
        ]);

        if ($forced !== []) {
            $org->forceFill($forced)->save();
        }

        return $org->fresh();
    }

    private function admin(Masjid $masjid): User
    {
        $user = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        MasjidUser::create([
            'masjid_id' => $masjid->id, 'user_id' => $user->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        return $user->fresh();
    }
}
