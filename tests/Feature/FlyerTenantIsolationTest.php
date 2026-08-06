<?php

namespace Tests\Feature;

use App\Models\Flyer;
use App\Models\FlyerTemplate;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant-isolation guardrail for the Flyer Studio (Flyer + FlyerTemplate).
 *
 * Same job and same shape as TenantIsolationTest: MySQL has no row-level
 * security, so App\Models\Concerns\BelongsToMasjid is the only thing keeping one
 * masjid's flyers out of another's queries, and this suite is the backstop that
 * proves it at the model layer by binding TenantContext directly (the same
 * object ResolveMasjidTenant binds per request).
 *
 * FlyerTemplate needs MORE proof than an ordinary CRM model, because it
 * deliberately WIDENS the trait's scope from "mine" to "shared OR mine" so the
 * seeded system designs (masjid_id = null) stay visible to a bound tenant. That
 * widening buys a specific risk, so it gets specific tests:
 *
 *   - a bound tenant sees shared + its own, and nothing of another masjid's;
 *   - ownedByTenant() still EXCLUDES the shared rows — visible is not editable,
 *     and this is the guard that stops one MasjidAdmin editing a system design
 *     out from under every other masjid;
 *   - withoutMasjidScope() still drops the filter entirely, even though the
 *     scope was re-registered under the trait's own identifier.
 *
 * Flyer itself keeps the plain scope, so its half of the file is the ordinary
 * cross-tenant proof: another masjid's flyer is a scoped MISS (which the
 * controllers' findOrFail turns into a 404), never a leak.
 *
 * See .claude/rules/tenant-scoping.md. Sqlite-in-memory + RefreshDatabase per
 * the testing convention.
 */
class FlyerTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    /** Ships with the product: masjid_id null, is_system true. */
    private FlyerTemplate $sharedTemplate;

    private FlyerTemplate $templateA;
    private FlyerTemplate $templateB;

    private Flyer $flyerA;
    private Flyer $flyerB;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml — this suite must
        // never need a network DB to run.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->tenant = app(TenantContext::class);
        // Every test starts UNBOUND; each binds explicitly when it needs to.
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid(['name' => 'Masjid A ' . uniqid()]);
        $this->masjidB = $this->makeMasjid(['name' => 'Masjid B ' . uniqid()]);

        // Seed while UNBOUND so the explicit masjid_id — and, for the shared
        // design, the explicit NULL — is honored. The creating hook only
        // overrides when a tenant is bound.
        $this->sharedTemplate = FlyerTemplate::factory()->system()->create([
            'key' => 'system.food.classic',
            'name' => 'Food Drive (system)',
            'kind' => 'food',
        ]);

        $this->templateA = FlyerTemplate::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'name' => 'Masjid A own design',
        ]);

        $this->templateB = FlyerTemplate::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'name' => 'Masjid B own design',
        ]);

        $this->flyerA = Flyer::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'flyer_template_id' => $this->templateA->id,
            'title' => 'A: Friday Dinner',
        ]);

        $this->flyerB = Flyer::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'flyer_template_id' => $this->templateB->id,
            'title' => 'B: Eid Night',
        ]);
    }

    /** Create a Masjid row with the minimum columns the schema requires. */
    private function makeMasjid(array $overrides = []): Masjid
    {
        return Masjid::create(array_merge([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));
    }

    // ------------------------------------------------------ flyer templates

    #[Test]
    public function bound_tenant_sees_shared_templates_and_its_own_but_not_another_masjids(): void
    {
        $this->tenant->set($this->masjidA->id);

        $ids = FlyerTemplate::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains(
            $this->sharedTemplate->id,
            $ids,
            'A shared system design must stay visible to a bound tenant — that is the whole point of the widened scope.'
        );
        $this->assertContains($this->templateA->id, $ids, 'Masjid A must see its own design.');
        $this->assertNotContains($this->templateB->id, $ids, "Masjid B's own design must never reach masjid A.");
        $this->assertCount(2, $ids, 'Shared + own, and nothing else.');
    }

    #[Test]
    public function bound_tenant_find_returns_the_shared_template(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNotNull(FlyerTemplate::find($this->sharedTemplate->id));
    }

    #[Test]
    public function bound_tenant_find_returns_null_for_another_masjids_template(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNull(FlyerTemplate::find($this->templateB->id));
    }

    #[Test]
    public function creating_a_template_stamps_the_bound_masjid(): void
    {
        $this->tenant->set($this->masjidA->id);

        $template = FlyerTemplate::create([
            'key' => 'm-a.eid-night',
            'name' => 'Eid Night',
            'kind' => 'event',
            'schema' => ['slots' => []],
        ]);

        $this->assertSame($this->masjidA->id, $template->masjid_id);
    }

    #[Test]
    public function creating_a_template_ignores_a_client_supplied_masjid_id(): void
    {
        $this->tenant->set($this->masjidA->id);

        // Client tries to plant the design in masjid B; the server-derived hook wins.
        $template = FlyerTemplate::create([
            'key' => 'm-a.malicious',
            'name' => 'Malicious Payload',
            'kind' => 'event',
            'schema' => ['slots' => []],
            'masjid_id' => $this->masjidB->id,
        ]);

        $this->assertSame($this->masjidA->id, $template->masjid_id);
        $this->assertNotSame($this->masjidB->id, $template->masjid_id);
    }

    #[Test]
    public function owned_by_tenant_excludes_the_shared_templates(): void
    {
        $this->tenant->set($this->masjidA->id);

        // The contrast IS the test: the widened read scope shows the system
        // design, but the write-side lookup must refuse to hand it over, or a
        // MasjidAdmin could edit a product design out from under every tenant.
        $this->assertNotNull(
            FlyerTemplate::find($this->sharedTemplate->id),
            'Sanity check: the shared design is readable.'
        );
        $this->assertNull(
            FlyerTemplate::ownedByTenant()->find($this->sharedTemplate->id),
            'Visible is not editable: ownedByTenant() must not resolve a shared design.'
        );

        $owned = FlyerTemplate::ownedByTenant()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([$this->templateA->id], $owned);
    }

    #[Test]
    public function owned_by_tenant_excludes_another_masjids_template(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertNull(FlyerTemplate::ownedByTenant()->find($this->templateB->id));
    }

    #[Test]
    public function shared_scope_returns_only_the_product_owned_designs(): void
    {
        $this->tenant->set($this->masjidA->id);

        $shared = FlyerTemplate::shared()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([$this->sharedTemplate->id], $shared);
    }

    #[Test]
    public function template_without_masjid_scope_bypasses_the_filter_even_when_bound(): void
    {
        $this->tenant->set($this->masjidA->id);

        // The widened scope is registered under the TRAIT's identifier precisely
        // so this documented super/system bypass still drops it. If it were
        // registered under a new key, a tenant filter would silently survive here.
        $this->assertSame(3, FlyerTemplate::withoutMasjidScope()->count());
    }

    #[Test]
    public function unbound_context_sees_every_masjids_templates(): void
    {
        // No binding: SuperAdmin / seeder / system behavior — no auto-filter.
        $this->assertFalse($this->tenant->hasTenant());

        $this->assertSame(3, FlyerTemplate::count());
    }

    #[Test]
    public function template_registers_the_masjid_tenant_global_scope(): void
    {
        $scopes = (new FlyerTemplate())->getGlobalScopes();

        $this->assertArrayHasKey(FlyerTemplate::MASJID_TENANT_SCOPE, $scopes);
    }

    // --------------------------------------------------------------- flyers

    #[Test]
    public function bound_tenant_sees_only_its_own_flyers(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(1, Flyer::count());
        $this->assertSame($this->flyerA->id, Flyer::first()->id);
    }

    #[Test]
    public function another_masjids_flyer_is_a_404_not_a_leak(): void
    {
        $this->tenant->set($this->masjidB->id);

        // findOrFail is what FlyersController's show/update/destroy call. A
        // scoped miss throws ModelNotFoundException, which Laravel renders as a
        // 404 — masjid B is told the flyer does not exist, never shown it.
        $this->expectException(ModelNotFoundException::class);

        Flyer::findOrFail($this->flyerA->id);
    }

    #[Test]
    public function another_masjids_flyer_is_filtered_out_but_not_destroyed(): void
    {
        $this->tenant->set($this->masjidB->id);

        $this->assertNull(Flyer::find($this->flyerA->id));

        // Invisible to B, still very much masjid A's row.
        $this->assertTrue(
            $this->tenant->runWithout(fn () => Flyer::whereKey($this->flyerA->id)->exists())
        );
    }

    #[Test]
    public function bound_tenant_cannot_update_another_masjids_flyer(): void
    {
        $this->tenant->set($this->masjidB->id);

        $affected = Flyer::where('id', $this->flyerA->id)->update(['title' => 'HIJACKED']);

        $this->assertSame(0, $affected, 'Masjid B must not be able to update masjid A flyers.');

        $row = $this->tenant->runWithout(fn () => Flyer::find($this->flyerA->id));
        $this->assertSame('A: Friday Dinner', $row->title);
    }

    #[Test]
    public function bound_tenant_cannot_delete_another_masjids_flyer(): void
    {
        $this->tenant->set($this->masjidB->id);

        $affected = Flyer::where('id', $this->flyerA->id)->delete();

        $this->assertSame(0, $affected, 'Masjid B must not be able to delete masjid A flyers.');

        $this->assertTrue(
            $this->tenant->runWithout(fn () => Flyer::whereKey($this->flyerA->id)->exists())
        );
    }

    #[Test]
    public function creating_a_flyer_stamps_the_bound_masjid(): void
    {
        $this->tenant->set($this->masjidA->id);

        // FlyersController::store deliberately omits masjid_id; a hand-posted
        // payload might not, so the hook is proven against the hostile case.
        $flyer = Flyer::create([
            'masjid_id' => $this->masjidB->id,
            'flyer_template_id' => $this->templateA->id,
            'title' => 'Community Iftar',
            'content' => [],
            'palette' => [],
        ]);

        $this->assertSame($this->masjidA->id, $flyer->masjid_id);
        $this->assertNotSame($this->masjidB->id, $flyer->masjid_id);
        $this->assertNotEmpty($flyer->uuid, 'The creating hook assigns the public uuid.');
    }

    #[Test]
    public function flyer_without_masjid_scope_bypasses_the_filter_even_when_bound(): void
    {
        $this->tenant->set($this->masjidA->id);

        // The cutout worker claims work across every tenant from an unbound
        // context, so this bypass has to keep working.
        $this->assertSame(2, Flyer::withoutMasjidScope()->count());
    }

    #[Test]
    public function unbound_context_sees_every_masjids_flyers(): void
    {
        $this->assertFalse($this->tenant->hasTenant());

        $this->assertSame(2, Flyer::count());
    }

    #[Test]
    public function flyer_registers_the_masjid_tenant_global_scope(): void
    {
        $scopes = (new Flyer())->getGlobalScopes();

        $this->assertArrayHasKey(Flyer::MASJID_TENANT_SCOPE, $scopes);
    }
}
