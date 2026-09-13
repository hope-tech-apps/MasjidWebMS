<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidFormsCardLinkLog;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Stripe\FormChargeAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Every change to a forms card link leaves a row in the append-only
 * masjid_forms_card_links_log, in the same transaction as the change
 * (DECISIONS.md 2026-09-15, D12, D13, D17).
 */
class FormsCardAccountAuditTest extends TestCase
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

        $this->holder = $this->makeOrg('Burlington Masjid Audit', 'masjid', [
            'stripe_account_id' => self::HOLDER_ACCOUNT,
            'stripe_charges_enabled' => true,
        ]);
        $this->child = $this->makeOrg('BISS Audit', 'school', ['parent_id' => $this->holder->id]);
    }

    #[Test]
    public function a_link_writes_one_audit_row_naming_the_act(): void
    {
        $super = $this->superAdmin();
        $this->linkAs($super)->assertOk();

        $row = MasjidFormsCardLinkLog::sole();
        $this->assertSame($this->child->id, (int) $row->child_masjid_id);
        $this->assertSame($this->holder->id, (int) $row->holder_masjid_id);
        $this->assertSame('link', $row->action);
        $this->assertSame($super->id, (int) $row->actor_user_id);
        $this->assertSame($this->holder->name, $row->typed_name);
        $this->assertSame('Owner decision 2026-09-13', $row->consent_reference);
        // The last four only, never the account id.
        $this->assertSame('HOLD', $row->holder_account_suffix);
        $this->assertNotNull($row->created_at);
    }

    #[Test]
    public function the_link_and_its_audit_row_commit_together_or_not_at_all(): void
    {
        // Make the audit insert fail AFTER the link column was written inside the
        // transaction: the link must roll back with it.
        MasjidFormsCardLinkLog::creating(function () {
            throw new RuntimeException('audit store unavailable');
        });

        $this->linkAs($this->superAdmin())->assertStatus(500);

        $this->assertNull($this->child->fresh()->forms_card_via_masjid_id);
        $this->assertSame(0, MasjidFormsCardLinkLog::count());
    }

    #[Test]
    public function a_super_admin_unlink_writes_an_unlink_row(): void
    {
        $super = $this->superAdmin();
        $this->linkAs($super)->assertOk();

        $this->patchJson("/api/admin/masjids/{$this->child->id}/forms-card-account", [
            'via_masjid_id' => null,
            'consent_reference' => 'Owner asked to stop 2026-10-01',
        ])->assertOk();

        $row = MasjidFormsCardLinkLog::where('action', 'unlink')->sole();
        $this->assertSame($this->child->id, (int) $row->child_masjid_id);
        $this->assertSame($this->holder->id, (int) $row->holder_masjid_id);
        $this->assertSame($super->id, (int) $row->actor_user_id);
        $this->assertNull($row->typed_name);
        $this->assertSame('Owner asked to stop 2026-10-01', $row->consent_reference);
        $this->assertSame('HOLD', $row->holder_account_suffix);
        $this->assertSame(2, MasjidFormsCardLinkLog::count());
    }

    #[Test]
    public function the_holders_admin_revokes_the_link_and_it_is_audited(): void
    {
        $this->linkAs($this->superAdmin())->assertOk();

        $holderAdmin = $this->admin($this->holder);
        Sanctum::actingAs($holderAdmin);

        $this->deleteJson("/api/admin/masjids/{$this->holder->id}/connect/forms-card-for/{$this->child->id}")
            ->assertOk()
            ->assertExactJson(['status' => 'success']);

        $this->assertNull($this->child->fresh()->forms_card_via_masjid_id);
        $this->assertNull(FormChargeAccount::for($this->child->fresh()));

        $row = MasjidFormsCardLinkLog::where('action', 'revoke')->sole();
        $this->assertSame($this->child->id, (int) $row->child_masjid_id);
        $this->assertSame($this->holder->id, (int) $row->holder_masjid_id);
        $this->assertSame($holderAdmin->id, (int) $row->actor_user_id);
        $this->assertSame('HOLD', $row->holder_account_suffix);
    }

    #[Test]
    public function revoke_is_bound_to_the_holder_tenant(): void
    {
        $this->linkAs($this->superAdmin())->assertOk();

        // The child's own admin names the holder in the route: refused by the
        // tenant middleware.
        Sanctum::actingAs($this->admin($this->child));
        $this->deleteJson("/api/admin/masjids/{$this->holder->id}/connect/forms-card-for/{$this->child->id}")
            ->assertStatus(403);

        // Another organisation's admin, on their OWN holder route: the link does
        // not point at them, so it is a 404 and nothing moves.
        $other = $this->makeOrg('Other Masjid Audit', 'masjid', [
            'stripe_account_id' => 'acct_1TestOTHR',
            'stripe_charges_enabled' => true,
        ]);
        Sanctum::actingAs($this->admin($other));
        $this->deleteJson("/api/admin/masjids/{$other->id}/connect/forms-card-for/{$this->child->id}")
            ->assertStatus(404);

        // And that admin cannot reach the real holder's route at all.
        $this->deleteJson("/api/admin/masjids/{$this->holder->id}/connect/forms-card-for/{$this->child->id}")
            ->assertStatus(403);

        $this->assertSame($this->holder->id, (int) $this->child->fresh()->forms_card_via_masjid_id);
        $this->assertSame(0, MasjidFormsCardLinkLog::where('action', 'revoke')->count());
    }

    #[Test]
    public function revoke_needs_the_manage_donations_permission(): void
    {
        $this->linkAs($this->superAdmin())->assertOk();

        $holderAdmin = $this->admin($this->holder);
        $holderAdmin->revokePermissionTo('manage donations');
        $holderAdmin->syncRoles([]);
        Sanctum::actingAs($holderAdmin->fresh());

        $this->deleteJson("/api/admin/masjids/{$this->holder->id}/connect/forms-card-for/{$this->child->id}")
            ->assertStatus(403);

        $this->assertSame($this->holder->id, (int) $this->child->fresh()->forms_card_via_masjid_id);
    }

    #[Test]
    public function the_holder_can_revoke_while_its_crm_is_switched_off(): void
    {
        // The link keeps charging whatever the holder's CRM switch says, so the
        // holder's way to withdraw consent must not depend on it.
        $this->linkAs($this->superAdmin())->assertOk();
        $this->holder->forceFill(['crm_enabled' => false])->save();

        Sanctum::actingAs($this->admin($this->holder));

        $this->deleteJson("/api/admin/masjids/{$this->holder->id}/connect/forms-card-for/{$this->child->id}")
            ->assertOk()
            ->assertExactJson(['status' => 'success']);

        $this->assertNull($this->child->fresh()->forms_card_via_masjid_id);
        $this->assertSame(1, MasjidFormsCardLinkLog::where('action', 'revoke')->count());
    }

    #[Test]
    public function the_revoke_route_is_gated_like_connect_but_not_by_crm(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->first(fn ($r) => in_array('DELETE', $r->methods(), true)
                && $r->uri() === 'api/admin/masjids/{masjid_id}/connect/forms-card-for/{child_id}');

        $this->assertNotNull($route, 'The holder revoke route is not registered.');
        $middleware = $route->gatherMiddleware();

        foreach (['auth:sanctum', 'admin', 'tenant', 'permission:manage donations'] as $layer) {
            $this->assertContains($layer, $middleware);
        }
        $this->assertNotContains('crm', $middleware);
    }

    #[Test]
    public function the_holder_can_revoke_the_link_of_an_archived_child(): void
    {
        $this->linkAs($this->superAdmin())->assertOk();
        $this->child->delete();

        Sanctum::actingAs($this->admin($this->holder));

        $this->deleteJson("/api/admin/masjids/{$this->holder->id}/connect/forms-card-for/{$this->child->id}")
            ->assertOk();

        $this->assertNull(Masjid::withTrashed()->find($this->child->id)->forms_card_via_masjid_id);
        $this->assertSame(1, MasjidFormsCardLinkLog::where('action', 'revoke')->count());
    }

    #[Test]
    public function force_deleting_the_holder_nulls_the_link_and_audits_it(): void
    {
        $this->linkAs($this->superAdmin())->assertOk();

        $holderId = $this->holder->id;
        $this->holder->forceDelete();

        $child = $this->child->fresh();
        $this->assertNull($child->forms_card_via_masjid_id);
        $this->assertNull(FormChargeAccount::for($child));

        $row = MasjidFormsCardLinkLog::where('action', 'unlink')->sole();
        $this->assertSame($child->id, (int) $row->child_masjid_id);
        $this->assertSame($holderId, (int) $row->holder_masjid_id);
        $this->assertSame('HOLD', $row->holder_account_suffix);
    }

    #[Test]
    public function soft_deleting_the_holder_keeps_the_link_but_card_is_unavailable(): void
    {
        $this->linkAs($this->superAdmin())->assertOk();

        $this->holder->delete();

        $child = $this->child->fresh();
        $this->assertSame($this->holder->id, (int) $child->forms_card_via_masjid_id);
        $this->assertNull(FormChargeAccount::for($child));
        $this->assertSame(0, MasjidFormsCardLinkLog::where('action', 'unlink')->count());
    }

    #[Test]
    public function audit_rows_cannot_be_modified_or_deleted(): void
    {
        $this->linkAs($this->superAdmin())->assertOk();
        $row = MasjidFormsCardLinkLog::sole();

        try {
            $row->update(['consent_reference' => 'rewritten']);
            $this->fail('An audit row was modified.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $row->delete();
            $this->fail('An audit row was deleted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame('Owner decision 2026-09-13', MasjidFormsCardLinkLog::sole()->consent_reference);
    }

    #[Test]
    public function an_unknown_action_is_never_recorded(): void
    {
        $this->expectException(RuntimeException::class);

        MasjidFormsCardLinkLog::record($this->child->id, $this->holder->id, 'transfer', null, self::HOLDER_ACCOUNT);
    }

    // ------------------------------------------------------------------ helpers

    private function linkAs(User $super)
    {
        Sanctum::actingAs($super);

        return $this->patchJson("/api/admin/masjids/{$this->child->id}/forms-card-account", [
            'via_masjid_id' => $this->holder->id,
            'typed_holder_name' => $this->holder->name,
            'consent_reference' => 'Owner decision 2026-09-13',
        ]);
    }

    private function makeOrg(string $name, string $orgType = 'masjid', array $forced = []): Masjid
    {
        $org = Masjid::create([
            'name' => $name . ' ' . uniqid(),
            'email' => 'audit' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => $orgType,
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

    private function superAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ])->fresh();
    }
}
