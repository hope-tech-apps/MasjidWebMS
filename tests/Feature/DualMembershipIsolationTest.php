<?php

namespace Tests\Feature;

use App\Http\Middleware\EchoResolvedTenant;
use App\Models\Contact;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * **Invariant 7** of docs/multi-tenant-admin-design.md: the `*TenantIsolationTest`
 * files still hold when the actor holds memberships in BOTH organisation A and
 * organisation B.
 *
 * ------------------------------------------------------------------------------
 * Why this is the invariant that matters most, and why it needs its own file
 * ------------------------------------------------------------------------------
 *
 * Those files are the only thing standing between one masjid and another's
 * donors, congregants and children's records — there is no row-level security
 * under MySQL. Every one of them was written against a fixture where the actor
 * belongs to exactly ONE organisation, because until the pivot existed there was
 * no other kind of actor. S3 changed where the answer comes from and S4 and S5
 * add doors that can produce the other kind, so "the isolation suite still
 * passes" has to be re-established against an actor those files could not
 * describe.
 *
 * The suite cannot be re-run from inside a test, so this file does the two
 * things that are actually possible and are worth more than a re-run would be:
 *
 *   1. It reproduces THE FIXTURE all thirty-one of them share — two
 *      organisations, an owner-administrator for each, rows in both — adds the
 *      second membership, and re-asserts the guarantees they assert, at both the
 *      layer they use (bind `TenantContext`, query the model) and the layer four
 *      of them use (drive the admin HTTP surface).
 *   2. It asks whether the dangerous actor can exist at all. With the gate shut
 *      the answer is no, and that is a property of the DOORS rather than of the
 *      resolver — so the doors are swept for it.
 *
 * ------------------------------------------------------------------------------
 * The design says twelve files; there are thirty-one
 * ------------------------------------------------------------------------------
 *
 * The design was written on 2026-08-11 and counted twelve. The roster has grown
 * with every slice since. The number is not pinned here (that would be a count to
 * bump on every new model, which .claude/rules/tenant-scoping.md deliberately
 * avoids) — the FLOOR is, so the roster can only grow, and the discovered list is
 * printed on failure.
 *
 * Sqlite-in-memory + RefreshDatabase per the testing convention.
 */
class DualMembershipIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** The count in docs/multi-tenant-admin-design.md, as of 2026-08-11. */
    private const DESIGN_ERA_ISOLATION_FILES = 12;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    /** Owns A, and — the point of this file — ALSO holds a membership in B. */
    private User $dualActor;

    private Contact $rowInA;
    private Contact $rowInB;

    protected function setUp(): void
    {
        parent::setUp();

        // Same idiom as TenantIsolationTest: this suite must never need a
        // network DB to run.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Before any user exists: UserObserver bridges `users.type` onto a spatie
        // role at save time and skips silently when the role is missing.
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid(['name' => 'Masjid A ' . uniqid()]);
        $this->masjidB = $this->makeMasjid(['name' => 'Masjid B ' . uniqid()]);

        // `makeAdminFor`, byte-for-byte the helper every one of those files
        // defines: a MasjidAdmin who OWNS the masjid, which is how the resolver
        // places them while the gate is shut.
        $this->dualActor = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        // The second membership. Neither organisation's fixture knows about it,
        // and with the gate shut neither should behave differently for it.
        $this->membership($this->dualActor, $this->masjidA, ['is_default' => true]);
        $this->membership($this->dualActor, $this->masjidB, ['is_default' => false]);

        // Seeded UNBOUND so the explicit masjid_id is honoured — the creating
        // hook only overrides when a tenant is bound.
        $this->rowInA = Contact::factory()->create(['masjid_id' => $this->masjidA->id, 'first_name' => 'Aisha']);
        $this->rowInB = Contact::factory()->create(['masjid_id' => $this->masjidB->id, 'first_name' => 'Bilal']);
    }

    // ------------------------------------------------------------ the roster

    /**
     * The roster is discovered, not listed, and it may only grow.
     *
     * A file that is renamed out of the `*TenantIsolationTest` shape stops being
     * found by `php artisan test --filter=TenantIsolation`, which is the command
     * .claude/rules/tenant-scoping.md and tests/CLAUDE.md both tell a developer
     * to run. Losing one is therefore losing a guardrail AND the instruction that
     * points at it, with nothing failing.
     */
    #[Test]
    public function every_tenant_isolation_suite_is_still_discoverable_and_the_roster_only_grows(): void
    {
        $roster = $this->isolationSuites();

        $this->assertGreaterThanOrEqual(
            self::DESIGN_ERA_ISOLATION_FILES,
            count($roster),
            'The design counted ' . self::DESIGN_ERA_ISOLATION_FILES . " *TenantIsolationTest files and the roster may only grow. Found:\n"
                . implode("\n", $roster)
        );
    }

    /**
     * ...and every one of them measures the SHIPPED configuration.
     *
     * If an isolation suite opened `tenancy.multi_membership` itself, its
     * refusals would be asserting the multi-tenant path rather than the one
     * production runs — and this file's claim to have re-established them under a
     * dual-membership actor would be about a different resolver than the one
     * serving masjid.hopetechapps.com. The open-gate variant is asserted here,
     * deliberately in one place.
     */
    #[Test]
    public function no_tenant_isolation_suite_opens_the_multi_membership_gate(): void
    {
        foreach ($this->isolationSuites() as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertStringNotContainsString(
                'tenancy.multi_membership',
                $source,
                "{$path} touches the multi-membership gate. Every isolation suite must measure the shipped configuration; "
                    . 'the open-gate variant belongs in ' . basename(__FILE__) . '.'
            );
        }
    }

    // --------------------------------------------- the model layer they use

    /**
     * The layer twenty-seven of the thirty-one files assert at: bind the context,
     * query the model. A membership changes nothing here and must not — the
     * global scope filters by the BOUND masjid, and what the actor may bind is a
     * separate question answered a layer up.
     *
     * Asserted anyway, because "memberships do not reach the global scope" is
     * exactly the kind of thing a future slice adds for a good local reason.
     */
    #[Test]
    public function a_dual_membership_actor_does_not_widen_the_model_scope(): void
    {
        $this->tenant->set($this->masjidA->id);

        $this->assertSame(
            [$this->rowInA->id],
            Contact::query()->pluck('id')->map(fn ($id): int => (int) $id)->all()
        );
        $this->assertNull(Contact::find($this->rowInB->id), 'B\'s row must be invisible from inside A, membership or not.');
        $this->assertSame(1, Contact::count());

        // The creating hook still stamps the BOUND tenant, not "one of the
        // organisations this person belongs to".
        $created = Contact::create(['first_name' => 'New', 'last_name' => 'Row', 'masjid_id' => $this->masjidB->id]);
        $this->assertSame($this->masjidA->id, (int) $created->masjid_id);

        // And the documented bypass still sees both, so the assertions above are
        // measuring a filter rather than an empty database.
        $this->assertSame(3, Contact::withoutMasjidScope()->count());
    }

    // ----------------------------------------------- the HTTP layer they use

    /**
     * The layer the other four assert at, and the one the gate actually governs:
     * with `tenancy.multi_membership` false, the second membership grants the
     * actor nothing. Their own organisation reads normally; the other
     * organisation is the same 403 it was before the pivot existed; the other
     * organisation's row id under their OWN route is still a 404, because the row
     * is invisible rather than forbidden.
     */
    #[Test]
    public function with_the_gate_shut_the_second_membership_grants_the_actor_nothing_over_http(): void
    {
        // Assert the gate SHUT explicitly rather than inheriting it. A test whose
        // name says "with the gate shut" must set that condition, not read it off
        // the ambient environment: S5 is exercised by running this whole suite
        // with TENANCY_MULTI_MEMBERSHIP=true, and a test that merely inherited
        // the default then fails for the one reason that is not a defect.
        config(['tenancy.multi_membership' => false]);

        Sanctum::actingAs($this->dualActor);

        $own = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/contacts")->assertOk();
        $own->assertHeader(EchoResolvedTenant::TENANT_HEADER, (string) $this->masjidA->id);
        $this->assertSame(
            [$this->rowInA->id],
            collect($own->json('data.data'))->pluck('id')->map(fn ($id): int => (int) $id)->all()
        );

        $this->getJson("/api/admin/masjids/{$this->masjidB->id}/contacts")
            ->assertStatus(403);

        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/contacts/{$this->rowInB->id}")
            ->assertStatus(404);

        // A write cannot cross either: the body names B, the row lands in A.
        $created = $this->postJson("/api/admin/masjids/{$this->masjidA->id}/contacts", [
            'first_name' => 'Crossed',
            'last_name' => 'Tenant',
            'masjid_id' => $this->masjidB->id,
        ])->assertCreated();

        $this->assertDatabaseHas('contacts', [
            'id' => $created->json('data.id'),
            'masjid_id' => $this->masjidA->id,
        ]);
    }

    /**
     * THE PRE-FLIGHT WARNING, and the reason this file exists rather than a note
     * in a report: with the gate OPEN the very same fixture reaches BOTH
     * organisations, so the refusals the isolation suites assert become 200s BY
     * DESIGN.
     *
     * That is not a regression — it is what multi-organisation administration
     * means — but it means invariant 7 holds only in the shipped configuration,
     * and whoever flips `tenancy.multi_membership` on staging will see isolation
     * tests fail if a dual-membership actor is ever introduced into their
     * fixtures. Written down as an executable assertion so the surprise happens
     * here, in a file whose name says so, rather than in
     * `DonationReceiptTenantIsolationTest` on the morning of the flip.
     */
    #[Test]
    public function with_the_gate_open_the_same_actor_legitimately_reaches_both_organisations(): void
    {
        config(['tenancy.multi_membership' => true]);

        Sanctum::actingAs($this->dualActor);

        $inA = $this->getJson("/api/admin/masjids/{$this->masjidA->id}/contacts")->assertOk();
        $inA->assertHeader(EchoResolvedTenant::TENANT_HEADER, (string) $this->masjidA->id);

        $inB = $this->getJson("/api/admin/masjids/{$this->masjidB->id}/contacts")->assertOk();
        $inB->assertHeader(EchoResolvedTenant::TENANT_HEADER, (string) $this->masjidB->id);

        // Reaching both is not the same as seeing both at once: each request is
        // still scoped to the ONE organisation its URL names, which is the part
        // that must survive the flip.
        $this->assertSame(
            [$this->rowInB->id],
            collect($inB->json('data.data'))->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'Even with the gate open, a request returns exactly one organisation\'s rows.'
        );
    }

    // ------------------------------------------------- can the actor exist?

    /**
     * The reachability question, swept over the doors rather than reasoned about.
     *
     * With the gate shut, `TenantResolver::grantsFor()` gives an OWNER the single
     * organisation they own — which is why the fixture above is inert — but gives
     * a NON-owner administrator every membership row they hold. Two rows for such
     * a person is therefore a real state with real consequences, and the only
     * thing keeping it out of production is that no door writes the second row:
     * every staff-provisioning path validates the email as `unique` on `users`,
     * so it can only ever mint a brand-new person with exactly one membership.
     *
     * `MasjidAdminsController` is the one deliberate exception — S4 built it
     * precisely to give an EXISTING login a second organisation — and it refuses
     * while the gate is shut. That refusal is asserted end to end in
     * tests/Feature/TenantApiSurfaceTest.php; here the point is that it is the
     * ONLY exception.
     *
     * A new door that attaches an existing login to an organisation fails this
     * test, which is the whole intent: it is a decision that has to be made
     * deliberately, not discovered later from a support ticket saying somebody is
     * 403'd in both of their organisations.
     */
    #[Test]
    public function no_door_except_the_gated_one_can_give_an_existing_login_a_second_membership(): void
    {
        $doors = $this->membershipWritingControllers();

        $this->assertNotEmpty($doors, 'Expected to find the controllers that write masjid_user rows; the sweep found none.');

        foreach ($doors as $door) {
            if (str_contains($door, 'MasjidAdminsController')) {
                // The deliberate door. Its own gate is asserted in
                // TenantApiSurfaceTest, not by reading its source here.
                continue;
            }

            $this->assertTrue(
                $this->enforcesAUniqueEmail($door),
                "{$door} writes a masjid_user row without validating the email as unique on `users`, so it can attach an "
                    . 'EXISTING login to a second organisation. With tenancy.multi_membership false that makes a non-owner '
                    . "administrator ambiguous, and TenantResolver fails closed on ambiguity — they are 403'd in BOTH "
                    . 'organisations. Route the capability through MasjidAdminsController::grantMembership, which is gated.'
            );
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Every `*TenantIsolationTest.php` under tests/, as repo-relative paths.
     *
     * Discovered rather than listed, for the reason
     * `TenantScopingCoverageTest` gives for discovering models: a list is a thing
     * to forget.
     *
     * @return list<string>
     */
    private function isolationSuites(): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('tests'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && str_ends_with($file->getFilename(), 'TenantIsolationTest.php')) {
                $found[] = ltrim(str_replace(base_path(), '', $file->getPathname()), DIRECTORY_SEPARATOR);
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Every controller that writes a `masjid_user` row, as repo-relative paths.
     *
     * @return list<string>
     */
    private function membershipWritingControllers(): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (str_contains($source, 'MasjidUser::create(') || str_contains($source, "table('masjid_user')")) {
                $found[] = ltrim(str_replace(base_path(), '', $file->getPathname()), DIRECTORY_SEPARATOR);
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Whether this controller — or any FormRequest it imports — validates the
     * email as unique on the `users` table.
     *
     * The rule is what guarantees the login being created is BRAND NEW, and
     * therefore that the membership row being written is that person's first.
     * Both spellings the codebase uses are accepted; a third spelling arriving
     * and failing here is a cheap false alarm, which is the right way round for a
     * control over this.
     */
    private function enforcesAUniqueEmail(string $controllerPath): bool
    {
        $sources = [file_get_contents(base_path($controllerPath))];

        // Follow the FormRequests it imports: the rule usually lives there.
        if (preg_match_all('/^use (App\\\\Http\\\\Requests\\\\[A-Za-z0-9_\\\\]+);$/m', $sources[0], $matches)) {
            foreach ($matches[1] as $class) {
                $path = base_path('app/' . str_replace('\\', '/', substr($class, strlen('App\\'))) . '.php');

                if (is_file($path)) {
                    $sources[] = file_get_contents($path);
                }
            }
        }

        $spellings = [
            "unique('users', 'email')",
            "unique('users','email')",
            'unique:users,email',
        ];

        foreach ($sources as $source) {
            foreach ($spellings as $spelling) {
                if (str_contains($source, $spelling)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Give $user a membership in $masjid, whatever the fixture already holds.
     *
     * `updateOrCreate` rather than `create` because the row may ALREADY exist:
     * setting `masjids.user_id` writes the owner's membership through a model
     * hook (`MasjidUser::ensureOwnerMembership`, from `Masjid::booted()`), so
     * `makeAdminFor()` above produces one before this line runs. A plain
     * `create()` would die on the `(masjid_id, user_id)` unique index in setUp,
     * which reads like a broken suite rather than a fixture that has been
     * overtaken — and it would take every test in this file down with it.
     */
    private function membership(User $user, Masjid $masjid, array $overrides = []): MasjidUser
    {
        $attributes = array_merge(['role' => 'masjid-admin', 'is_default' => false], $overrides);

        if ($attributes['is_default']) {
            MasjidUser::where('user_id', $user->id)
                ->where('masjid_id', '!=', $masjid->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        return MasjidUser::updateOrCreate(
            ['masjid_id' => $masjid->id, 'user_id' => $user->id],
            $attributes,
        );
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
            // The isolation suites that drive HTTP all enable it; the CRM feature
            // gate is a different test's subject.
            'crm_enabled' => true,
        ], $overrides));
    }

    /**
     * Create a MasjidAdmin owning $masjid (masjids.user_id -> User::masjid()),
     * which is how ResolveMasjidTenant resolves the tenant in a real request.
     * Copied verbatim from the isolation suites so this file is measuring THEIR
     * fixture and not a convenient variant of it.
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
}
