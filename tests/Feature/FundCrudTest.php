<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\Donation;
use App\Models\Masjid;
use App\Models\User;
use App\Support\Errors;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Support\SeedsDonationSubscriptions;

/**
 * Donation-funds (Fund CRUD) endpoint tests for
 * /api/admin/masjids/{masjid_id}/funds.
 *
 * Mirrors ContactCrudTest: exercise the tenant guardrail END TO END over HTTP.
 * As a MasjidAdmin of masjid A, every method works and is scoped to A, while A
 * can never reach B's data. Two paths keep A out of B:
 *   - targeting B's masjid in the route  -> 403 (ResolveMasjidTenant confines a
 *     MasjidAdmin to their own masjid);
 *   - B's fund id under A's own route     -> 404 (the BelongsToMasjid scope makes
 *     findOrFail miss the row).
 *
 * Also proves the two additional gates layered on these routes: the CRM feature
 * flag (`crm` middleware, 403 while crm_enabled is false) and the spatie
 * `permission:` middleware (403 without `manage funds`).
 *
 * Sqlite-in-memory is forced in setUp (mirrors ContactCrudTest). Funds for each
 * masjid are seeded while the tenant context is UNBOUND, so the explicit
 * masjid_id is honored.
 */
class FundCrudTest extends TestCase
{
    use RefreshDatabase, SeedsDonationSubscriptions;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Seed the additive spatie roles/permissions BEFORE creating the admins,
        // so each MasjidAdmin is bridged to the masjid-admin role (with the full
        // CRM permission set) on save — exactly as production does.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        // Seeded while the context is UNBOUND (no request yet), so the explicit
        // masjid_id is honored rather than overridden by the creating hook.
        Fund::factory()->count(3)->create(['masjid_id' => $this->masjidA->id]);
        Fund::factory()->count(2)->create(['masjid_id' => $this->masjidB->id]);
    }

    /** Create a Masjid row with CRM enabled (these tests assume access). */
    private function makeMasjid(bool $crmEnabled = true): Masjid
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
            'crm_enabled' => $crmEnabled,
        ]);
    }

    /**
     * Create a MasjidAdmin owning $masjid (masjids.user_id -> User::masjid()),
     * which is how ResolveMasjidTenant resolves the tenant in a real request.
     */
    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    /** Id of a fund belonging to $masjid (read without the tenant scope). */
    private function fundIdFor(Masjid $masjid): int
    {
        return Fund::withoutMasjidScope()->where('masjid_id', $masjid->id)->value('id');
    }

    // ---------- auth ----------

    #[Test]
    public function index_rejects_unauthenticated_requests(): void
    {
        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/funds")
            ->assertStatus(401);
    }

    // ---------- index ----------

    #[Test]
    public function index_returns_only_the_admins_own_masjid_funds(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/funds")
            ->assertOk();

        // The funds index returns a plain (non-paginated) array.
        $this->assertCount(3, $response->json('data'));
    }

    // ---------- store ----------

    #[Test]
    public function capitalized_option_labels_do_not_change_fund_keys_in_storage_or_the_api(): void
    {
        Sanctum::actingAs($this->adminA);
        foreach (Fund::TYPES as $type) {
            $response = $this->postJson("/api/admin/masjids/{$this->masjidA->id}/funds", [
                'name' => 'Sample ' . $type, 'type' => $type,
            ])->assertCreated()->assertJsonPath('data.type', $type);
            $id = $response->json('data.id');
            $this->assertDatabaseHas('funds', ['id' => $id, 'type' => $type]);
            $this->getJson("/api/admin/masjids/{$this->masjidA->id}/funds/{$id}")
                ->assertOk()->assertJsonPath('data.type', $type);
        }
    }

    #[Test]
    public function store_creates_a_fund_scoped_to_the_admins_masjid(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/funds", [
            'name' => 'Ramadan Zakat',
            'type' => 'zakat',
            'receiptable' => true,
            'is_active' => true,
        ])->assertStatus(201);

        $this->assertDatabaseHas('funds', [
            'name' => 'Ramadan Zakat',
            'type' => 'zakat',
            'masjid_id' => $this->masjidA->id,
        ]);
    }

    #[Test]
    public function store_requires_a_name(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/funds", [
            'type' => 'general',
        ])->assertStatus(422);
    }

    #[Test]
    public function store_rejects_an_invalid_type(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/funds", [
            'name' => 'Bad Type',
            'type' => 'not-a-real-type',
        ])->assertStatus(422);
    }

    #[Test]
    public function store_ignores_a_client_supplied_masjid_id(): void
    {
        Sanctum::actingAs($this->adminA);

        // The client tries to plant the row in masjid B; the trait stamps A.
        $response = $this->postJson("/api/admin/masjids/{$this->masjidA->id}/funds", [
            'masjid_id' => $this->masjidB->id,
            'name' => 'Malicious Fund',
            'type' => 'general',
        ])->assertStatus(201);

        $this->assertSame($this->masjidA->id, $response->json('data.masjid_id'));
        $this->assertDatabaseHas('funds', [
            'name' => 'Malicious Fund',
            'masjid_id' => $this->masjidA->id,
        ]);
        $this->assertDatabaseMissing('funds', [
            'name' => 'Malicious Fund',
            'masjid_id' => $this->masjidB->id,
        ]);
    }

    // ---------- show ----------

    #[Test]
    public function show_returns_the_admins_own_fund(): void
    {
        $ownId = $this->fundIdFor($this->masjidA);

        Sanctum::actingAs($this->adminA);

        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/funds/{$ownId}")
            ->assertOk()
            ->assertJsonPath('data.id', $ownId);
    }

    #[Test]
    public function show_cannot_read_another_masjids_fund_via_own_route(): void
    {
        $otherId = $this->fundIdFor($this->masjidB);

        Sanctum::actingAs($this->adminA);

        // Own masjid in the URL, but the id belongs to B -> scoped miss -> 404.
        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/funds/{$otherId}")
            ->assertStatus(404);
    }

    #[Test]
    public function admin_cannot_target_another_masjid_in_the_route(): void
    {
        $otherId = $this->fundIdFor($this->masjidB);

        Sanctum::actingAs($this->adminA);

        // Targeting B's masjid in the URL is forbidden by ResolveMasjidTenant.
        $this->getJson("/api/admin/masjids/{$this->masjidB->id}/funds/{$otherId}")
            ->assertStatus(403);
    }

    // ---------- update ----------

    #[Test]
    public function update_changes_the_admins_own_fund(): void
    {
        $ownId = $this->fundIdFor($this->masjidA);

        Sanctum::actingAs($this->adminA);

        $this->putJson("/api/admin/masjids/{$this->masjidA->id}/funds/{$ownId}", [
            'name' => 'Renamed Fund',
            'type' => 'sadaqah',
            'receiptable' => false,
            'is_active' => false,
        ])->assertOk();

        $this->assertDatabaseHas('funds', [
            'id' => $ownId,
            'name' => 'Renamed Fund',
            'type' => 'sadaqah',
            'receiptable' => false,
            'is_active' => false,
        ]);
    }

    #[Test]
    public function update_rejects_an_invalid_type(): void
    {
        $ownId = $this->fundIdFor($this->masjidA);

        Sanctum::actingAs($this->adminA);

        $this->putJson("/api/admin/masjids/{$this->masjidA->id}/funds/{$ownId}", [
            'name' => 'Still Named',
            'type' => 'bogus',
        ])->assertStatus(422);
    }

    #[Test]
    public function update_cannot_touch_another_masjids_fund(): void
    {
        $otherId = $this->fundIdFor($this->masjidB);

        Sanctum::actingAs($this->adminA);

        // Own masjid route + B's id -> scoped miss -> 404, and B is untouched.
        $this->putJson("/api/admin/masjids/{$this->masjidA->id}/funds/{$otherId}", [
            'name' => 'HIJACKED',
            'type' => 'general',
        ])->assertStatus(404);

        $this->assertDatabaseMissing('funds', [
            'id' => $otherId,
            'name' => 'HIJACKED',
        ]);
    }

    // ---------- destroy ----------

    private const GIFT_REFUSAL = 'This fund has gifts recorded in it, so it cannot be deleted. Switch it to inactive instead: it is then hidden from new donations and its history is kept.';
    private const RECURRING_REFUSAL = 'This fund has recurring gifts linked to it, so it cannot be deleted. Switch it to inactive instead: it is then hidden from new donations and its history is kept.';

    public static function giftStatuses(): array
    {
        return array_map(fn ($status) => [$status], ['pending', 'succeeded', 'failed', 'refunded']);
    }

    public static function recurringStatuses(): array
    {
        return array_map(fn ($status) => [$status], self::storableSubscriptionStatuses());
    }

    private function assertDeletionRefused(int $fundId, string $sentence): void
    {
        Sanctum::actingAs($this->adminA);
        config(['app.debug' => true]);
        $deletes = [];
        DB::listen(function ($query) use (&$deletes): void {
            if (preg_match('/^delete from ["`]funds["`]/i', $query->sql)) {
                $deletes[] = $query->sql;
            }
        });

        $this->deleteJson("/api/admin/masjids/{$this->masjidA->id}/funds/{$fundId}")
            ->assertStatus(409)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('data', $sentence);
        $this->assertDatabaseHas('funds', ['id' => $fundId]);
        $this->assertSame([], $deletes, 'Refusal must precede any DELETE statement.');
    }

    #[Test]
    #[DataProvider('giftStatuses')]
    public function a_fund_with_a_gift_is_refused_before_a_delete_is_attempted(string $status): void
    {
        $id = $this->fundIdFor($this->masjidA);
        $gift = Donation::factory()->create(['masjid_id' => $this->masjidA->id, 'fund_id' => $id, 'status' => $status]);

        $this->assertDeletionRefused($id, self::GIFT_REFUSAL);
        $this->assertDatabaseHas('donations', ['id' => $gift->id, 'fund_id' => $id]);
    }

    #[Test]
    #[DataProvider('recurringStatuses')]
    public function a_fund_with_a_recurring_commitment_is_refused_even_without_a_charge(string $status): void
    {
        $fund = Fund::findOrFail($this->fundIdFor($this->masjidA));
        $gift = $this->seedDonationSubscription($this->masjidA, $fund, ['status' => $status]);

        $this->assertDeletionRefused($fund->id, self::RECURRING_REFUSAL);
        $this->assertDatabaseHas('donation_subscriptions', ['id' => $gift->id, 'fund_id' => $fund->id]);
    }

    #[Test]
    public function every_foreign_key_to_funds_is_accounted_for(): void
    {
        $references = [];
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $key) {
                if ($key['foreign_table'] === 'funds') {
                    $references[] = [$table['name'], $key['columns'], strtolower($key['on_delete'])];
                }
            }
        }
        sort($references);
        $this->assertSame([
            ['donation_subscriptions', ['fund_id'], 'no action'],
            ['donations', ['fund_id'], 'no action'],
        ], $references);
    }

    private function rawFundException(int $id): QueryException
    {
        return new QueryException('mysql', 'delete from `funds` where `id` = ?', [$id],
            new \PDOException('SQLSTATE[23000]: Integrity constraint violation in private_database'));
    }

    #[Test]
    public function production_public_message_for_a_database_exception_is_generic(): void
    {
        config(['app.debug' => false]);
        $this->assertSame('An error occurred while processing your request.', Errors::publicMessage($this->rawFundException(1)));
    }

    #[Test]
    public function the_delete_backstop_never_returns_sql_even_with_debug_on(): void
    {
        $id = $this->fundIdFor($this->masjidA);
        config(['app.debug' => true]);
        Event::listen('eloquent.deleting: ' . Fund::class, function () use ($id): void {
            throw $this->rawFundException($id);
        });
        Sanctum::actingAs($this->adminA);

        $this->deleteJson("/api/admin/masjids/{$this->masjidA->id}/funds/{$id}")
            ->assertStatus(500)
            ->assertJsonPath('data', 'An error occurred while processing your request.');
        $this->assertDatabaseHas('funds', ['id' => $id]);
    }

    #[Test]
    public function deletion_locks_the_fund_before_reading_any_references_in_its_transaction(): void
    {
        $id = $this->fundIdFor($this->masjidA);
        $level = DB::transactionLevel();
        $inside = [];
        DB::listen(function ($query) use (&$inside, $level): void {
            if (DB::transactionLevel() > $level) {
                $inside[] = $query->sql;
            }
        });
        Sanctum::actingAs($this->adminA);
        $this->deleteJson("/api/admin/masjids/{$this->masjidA->id}/funds/{$id}")->assertOk();

        $this->assertNotEmpty($inside);
        $this->assertStringStartsWith('select * from "funds" where "funds"."id" = ?', $inside[0]);
    }

    #[Test]
    public function destroy_deletes_the_admins_own_fund(): void
    {
        $ownId = $this->fundIdFor($this->masjidA);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson("/api/admin/masjids/{$this->masjidA->id}/funds/{$ownId}")
            ->assertOk();

        $this->assertDatabaseMissing('funds', ['id' => $ownId]);
    }

    #[Test]
    public function destroy_cannot_delete_another_masjids_fund(): void
    {
        $otherId = $this->fundIdFor($this->masjidB);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson("/api/admin/masjids/{$this->masjidA->id}/funds/{$otherId}")
            ->assertStatus(404);

        // B's fund still exists.
        $this->assertDatabaseHas('funds', ['id' => $otherId]);
    }

    // ---------- CRM feature gate ----------

    #[Test]
    public function funds_endpoints_are_forbidden_while_crm_is_disabled(): void
    {
        // A masjid with CRM off, owned by its own (fully-permissioned) admin.
        $offMasjid = $this->makeMasjid(crmEnabled: false);
        $offAdmin = $this->makeAdminFor($offMasjid);

        Sanctum::actingAs($offAdmin);

        // The `crm` middleware 403s even a permissioned admin while the flag is off.
        $this->getJson("/api/admin/masjids/{$offMasjid->id}/funds")->assertStatus(403);
    }

    // ---------- permission gate ----------

    #[Test]
    public function managing_a_fund_requires_the_manage_funds_permission(): void
    {
        // Strip the bridged role, then grant ONLY the read permission so the admin
        // can view funds but not mutate them. syncRoles/givePermissionTo touch
        // pivots only (no user save), so the observer does not re-bridge the role.
        $this->adminA->syncRoles([]);
        $this->adminA->givePermissionTo('view donations');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->adminA);

        // Reading is allowed (view donations gates the funds index)...
        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/funds")->assertOk();

        // ...but creating one needs `manage funds` -> 403.
        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/funds", [
            'name' => 'Blocked Fund',
            'type' => 'general',
        ])->assertStatus(403);
    }
}
