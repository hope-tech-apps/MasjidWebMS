<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminDashboard\MasjidAdminsController;
use App\Http\Middleware\EchoResolvedTenant;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * S4's API surface and the two contracts S5 is built on top of it — the
 * `memberships[]` list, the per-response tenant echo, and the one door that can
 * give an existing login a second organisation.
 *
 * ------------------------------------------------------------------------------
 * What each of these is actually protecting
 * ------------------------------------------------------------------------------
 *
 * `memberships[]` is what the organisation switcher is built from, so the whole
 * question is whether it can ever offer an organisation the very next request
 * will refuse. It cannot, because it is not a reading of `masjid_user`: each
 * candidate is put to `App\Support\TenantResolver` exactly as a route would put
 * it and only the verdicts that BIND are published. That is the property pinned
 * below on both sides of the gate — a switcher entry the resolver refuses is not
 * a cosmetic bug, it is the state where organisation A's name sits above
 * organisation B's data with a spinner in between.
 *
 * The echo (`X-Tenant-Id`) is the other half of the same defence: the SPA renders
 * the tenant the SERVER bound instead of the one its own store believes in. Its
 * NAME is a contract spanning PHP and TypeScript, and breaking it is silent —
 * the SPA finds no header, falls back to the store, and quietly resumes painting
 * the organisation it believes it is in over whatever rows arrived. So the two
 * spellings are compared to each other here, because nothing else can.
 *
 * ------------------------------------------------------------------------------
 * The SPA assertions are source-level, and that is a limitation, not a choice
 * ------------------------------------------------------------------------------
 *
 * There is no JavaScript test runner in this repository — package.json's scripts
 * are `dev`, `build` and `build:prod`, and there is no vitest/jest config — so the
 * epoch, the store sweep and the mismatch notice cannot be executed from here.
 * CI compiles the SPA (`npm run build`) and nothing more. Three orderings the
 * code's own comments call load-bearing are therefore asserted against the
 * source text, in the same spirit
 * as `TenantResolverTest::the_gate_is_read_in_exactly_one_place`. They are a
 * floor: they catch a deletion or a reordering, not a subtle behaviour change.
 * Everything else about S5's client half is unverified and is reported as such.
 *
 * Sqlite-in-memory + RefreshDatabase per the testing convention.
 */
class TenantApiSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_REQUESTS_TS = 'resources/vue-app/core/tenancy/tenantRequests.ts';
    private const API_SERVICE_TS = 'resources/vue-app/core/services/ApiService.ts';
    private const SWITCH_STORE_TS = 'resources/vue-app/stores/tenantSwitchStore.ts';

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

        // Before any user exists — UserObserver bridges `users.type` onto a
        // spatie role at save time and skips silently when the role is missing.
        $this->seed(RolesAndPermissionsSeeder::class);

        app(TenantContext::class)->forgetTenant();
    }

    // ------------------------------------------------------------ memberships[]

    /**
     * The ordinary production payload: one organisation, named well enough for a
     * menu row, with no membership `id`.
     *
     * The absent `id` is deliberate and is asserted rather than assumed. A grant
     * is sometimes an UNSAVED `MasjidUser` — `TenantResolver::
     * membershipFromOwnership()` synthesises one for an owner the S2 backfill
     * never reached — so the id would be a real number for some administrators
     * and null for others, and anything keying a switcher on it would collapse
     * every one of the latter onto the single key `null`.
     */
    #[Test]
    public function an_admin_account_payload_names_the_one_organisation_they_administer(): void
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id, 'name' => 'Burlington Masjid']);
        $this->membership($admin, $owned, ['is_default' => true]);

        Sanctum::actingAs($admin);

        $memberships = $this->getJson('/api/admin/user')->assertOk()->json('data.memberships');

        $this->assertIsArray($memberships);
        $this->assertCount(1, $memberships);

        $this->assertSame($owned->id, (int) $memberships[0]['masjid_id']);
        $this->assertSame('masjid-admin', $memberships[0]['role']);
        $this->assertTrue($memberships[0]['is_default']);

        // Nested, because that is what NAMES the organisation in the switcher.
        // A flat payload renders every menu row as "Organisation #14".
        $this->assertSame($owned->id, (int) $memberships[0]['masjid']['id']);
        $this->assertSame('Burlington Masjid', $memberships[0]['masjid']['name']);
        $this->assertArrayHasKey('org_type', $memberships[0]['masjid']);

        $this->assertArrayNotHasKey(
            'id',
            $memberships[0],
            'the membership id must not be published: it is null for every owner the S2 backfill never reached.'
        );
    }

    /**
     * An owner whose `masjid_user` row was never written still appears in their
     * own switcher.
     *
     * Every organisation provisioned since S2's backfill has an owner in exactly
     * this state — nothing writes the row on the provisioning paths — and they
     * are kept working by `soleOwnedMembership()`'s ownership fallback. If the
     * list were a reading of the pivot instead of a resolver verdict, these
     * administrators would open the dashboard to an empty switcher on the very
     * screen that is supposed to name their organisation.
     */
    #[Test]
    public function an_owner_with_no_pivot_row_is_still_listed(): void
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id]);

        $this->stripMembershipRows($admin);
        $this->assertSame(0, $admin->memberships()->count(), 'fixture: this owner deliberately has no pivot row');

        Sanctum::actingAs($admin);

        $memberships = $this->getJson('/api/admin/user')->assertOk()->json('data.memberships');

        $this->assertCount(1, $memberships);
        $this->assertSame($owned->id, (int) $memberships[0]['masjid_id']);
        $this->assertArrayNotHasKey('id', $memberships[0], 'an ownership-derived grant has no row, so it can publish no id.');
    }

    /**
     * THE PROPERTY THE SWITCHER RESTS ON: the list is the resolver's verdict, not
     * the pivot's contents.
     *
     * With the gate shut, an owner's second membership row is INERT — naming its
     * masjid in a URL is the same 403 it was before the pivot existed. So it must
     * not be listed. A switcher that offered it would flip its chrome to
     * organisation B and discover the refusal one request later, which is exactly
     * the state this whole slice exists to remove.
     */
    #[Test]
    public function an_inert_second_membership_is_not_offered_while_the_gate_is_shut(): void
    {
        // Assert the gate SHUT explicitly rather than inheriting it. A test whose
        // name says "with the gate shut" must set that condition, not read it off
        // the ambient environment: S5 is exercised by running this whole suite
        // with TENANCY_MULTI_MEMBERSHIP=true, and a test that merely inherited
        // the default then fails for the one reason that is not a defect.
        config(['tenancy.multi_membership' => false]);

        [$admin, $owned, $other] = $this->adminHoldingTwoMembershipRows();

        Sanctum::actingAs($admin);

        $memberships = $this->getJson('/api/admin/user')->assertOk()->json('data.memberships');

        $this->assertCount(1, $memberships, 'the row in the other organisation exists but is not a GRANT, so it must not be offered.');
        $this->assertSame($owned->id, (int) $memberships[0]['masjid_id']);
        $this->assertNotContains(
            $other->id,
            collect($memberships)->pluck('masjid_id')->map(fn ($id): int => (int) $id)->all()
        );
    }

    /**
     * ...and the flag is the only thing holding it back, which is what proves the
     * list is tracking the resolver rather than a second rule that happens to
     * agree with it today.
     */
    #[Test]
    public function the_same_second_membership_is_offered_once_the_gate_opens(): void
    {
        [$admin, $owned, $other] = $this->adminHoldingTwoMembershipRows();

        config(['tenancy.multi_membership' => true]);

        Sanctum::actingAs($admin);

        $listed = collect($this->getJson('/api/admin/user')->assertOk()->json('data.memberships'))
            ->pluck('masjid_id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $expected = [$owned->id, $other->id];
        sort($expected);

        $this->assertSame($expected, $listed);
    }

    /**
     * A SuperAdmin gets NO `memberships` key at all — absent, not empty — and the
     * difference is a visible bug rather than a nicety.
     *
     * They hold no memberships (S2 gave them none on purpose) and reach every
     * organisation through the masjid picker. But the switcher store reads
     * `memberships: []` as "the server described this principal and granted them
     * nothing" and, since a SuperAdmin also has `user.masjid === null`, would put
     * every Manara operator behind a notice saying they belong to no
     * organisation. An ABSENT key is the one the SPA reads as "this principal
     * switches nothing", which is exactly true of them.
     */
    #[Test]
    public function a_super_admin_payload_carries_no_memberships_key_at_all(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $data = $this->getJson('/api/admin/user')->assertOk()->json('data');

        $this->assertIsArray($data);
        $this->assertArrayNotHasKey(
            'memberships',
            $data,
            'An empty array would render the "you belong to no organisation" notice for every Manara SuperAdmin.'
        );
    }

    // ----------------------------------------------------- the grant/revoke door

    /**
     * The only door that can give an EXISTING login a second organisation is shut
     * while the gate is, and the refusal EXPLAINS itself.
     *
     * The wording matters enough to assert (by reflection, so the test tracks the
     * sentence rather than pinning the prose): a SuperAdmin told only "not
     * allowed" would reasonably reach for the database instead, and a
     * hand-written INSERT does the same damage with no message at all. The damage
     * is real — with the gate shut, `TenantResolver::grantsFor()` gives a
     * NON-owner administrator every membership row they hold and fails closed when
     * that is more than one, so a second row 403s them in BOTH organisations,
     * including the one they were working in this morning.
     */
    #[Test]
    public function granting_a_second_organisation_is_refused_while_the_gate_is_shut(): void
    {
        // Assert the gate SHUT explicitly rather than inheriting it. A test whose
        // name says "with the gate shut" must set that condition, not read it off
        // the ambient environment: S5 is exercised by running this whole suite
        // with TENANCY_MULTI_MEMBERSHIP=true, and a test that merely inherited
        // the default then fails for the one reason that is not a defect.
        config(['tenancy.multi_membership' => false]);

        $closed = (new ReflectionClass(MasjidAdminsController::class))->getConstant('MULTI_MEMBERSHIP_CLOSED');
        $this->assertIsString($closed, 'MULTI_MEMBERSHIP_CLOSED must still exist — it is the refusal an operator reads.');

        // A non-owner administrator: the "second person in the office", who owns
        // nothing and whose only grant is a membership row.
        $staff = $this->masjidAdmin();
        $office = $this->makeMasjid();
        $this->membership($staff, $office, ['is_default' => true]);

        $elsewhere = $this->makeMasjid(['user_id' => $this->masjidAdmin()->id]);

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/admin/admins/masjid/{$elsewhere->id}/memberships", ['user_id' => $staff->id])
            ->assertStatus(403)
            ->assertJsonPath('data.user_id.0', $closed);

        $this->assertDatabaseMissing('masjid_user', [
            'masjid_id' => $elsewhere->id,
            'user_id' => $staff->id,
        ]);
        $this->assertSame(1, $staff->memberships()->count(), 'a refused grant must write nothing.');
    }

    /**
     * The one exception, and it is what makes the gate flippable at all.
     *
     * Writing the row for a masjid the person ALREADY OWNS adds no organisation to
     * anybody: the resolver already grants an owner that masjid, synthesising an
     * identical unsaved row when there is none. So the grant SET is unchanged —
     * asserted here through `memberships[]`, which is the resolver's own answer —
     * while the implicit grant becomes an explicit one.
     *
     * Without this the flag could never be turned on: opening it drops the
     * ownership fallback, so every owner provisioned since S2's backfill needs a
     * real row first, and this is the only door that writes one.
     */
    #[Test]
    public function repairing_an_owners_missing_row_is_allowed_and_adds_no_organisation(): void
    {
        $owner = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $owner->id]);

        // The fixture is "an owner the S2 backfill never reached", which is a
        // state the owner-membership hook now prevents on the way in. Removing
        // the row it wrote is how the state is still reachable — and the state is
        // not hypothetical: it is every organisation provisioned between S2 and
        // that hook, which is what this endpoint exists to repair.
        $this->stripMembershipRows($owner);
        $this->assertSame(0, $owner->memberships()->count(), 'fixture: the backfill never reached this masjid');

        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/admin/admins/masjid/{$owned->id}/memberships", ['user_id' => $owner->id])
            ->assertCreated()
            ->assertJsonPath('data.masjid_id', $owned->id)
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('masjid_user', [
            'masjid_id' => $owned->id,
            'user_id' => $owner->id,
            'is_default' => 1,
        ]);

        // The grant SET is what must be unchanged, and the resolver is the only
        // thing that can say so.
        Sanctum::actingAs($owner);

        $memberships = $this->getJson('/api/admin/user')->assertOk()->json('data.memberships');

        $this->assertCount(1, $memberships, 'the repair turned an implicit grant into an explicit one — it added nothing.');
        $this->assertSame($owned->id, (int) $memberships[0]['masjid_id']);
    }

    /** The mirror is shut too, for the same reason and with the same sentence. */
    #[Test]
    public function revoking_an_organisation_is_refused_while_the_gate_is_shut(): void
    {
        // Assert the gate SHUT explicitly rather than inheriting it. A test whose
        // name says "with the gate shut" must set that condition, not read it off
        // the ambient environment: S5 is exercised by running this whole suite
        // with TENANCY_MULTI_MEMBERSHIP=true, and a test that merely inherited
        // the default then fails for the one reason that is not a defect.
        config(['tenancy.multi_membership' => false]);

        $closed = (new ReflectionClass(MasjidAdminsController::class))->getConstant('MULTI_MEMBERSHIP_CLOSED');

        $staff = $this->masjidAdmin();
        $office = $this->makeMasjid();
        $this->membership($staff, $office, ['is_default' => true]);

        Sanctum::actingAs($this->superAdmin());

        $this->deleteJson("/api/admin/admins/masjid/{$office->id}/memberships/{$staff->id}")
            ->assertStatus(403)
            ->assertJsonPath('data.user_id.0', $closed);

        $this->assertDatabaseHas('masjid_user', [
            'masjid_id' => $office->id,
            'user_id' => $staff->id,
        ]);
    }

    // ------------------------------------------------ the PHP <-> TypeScript seam

    /**
     * The echo header is spelled the same on both sides of the boundary.
     *
     * `EchoResolvedTenant::TENANT_HEADER` and the FIRST entry of `TENANT_HEADERS`
     * in the SPA are one contract, and both files say so in as many words.
     * Renaming either alone fails NOTHING at runtime: the SPA finds no header,
     * falls back to its own store, and goes back to painting the organisation it
     * believes it is in over whatever rows arrived — which is the exact failure
     * the middleware exists to remove, arriving silently. This is the only place
     * the two strings can be compared to each other.
     */
    #[Test]
    public function the_tenant_echo_header_is_spelled_identically_in_php_and_in_the_spa(): void
    {
        $source = $this->spaSource(self::TENANT_REQUESTS_TS);

        $this->assertSame(
            1,
            preg_match('/const TENANT_HEADERS\s*=\s*\[\s*[\'"]([^\'"]+)[\'"]/', $source, $matches),
            'TENANT_HEADERS is gone or reshaped in ' . self::TENANT_REQUESTS_TS . '; the SPA can no longer read the echo.'
        );

        $this->assertSame(
            strtolower(EchoResolvedTenant::TENANT_HEADER),
            $matches[1],
            'The server stamps ' . EchoResolvedTenant::TENANT_HEADER . " and the SPA reads '{$matches[1]}' first. "
                . 'They are one contract — change both or neither.'
        );
    }

    /**
     * Staleness is checked BEFORE the echo is recorded.
     *
     * A response from the organisation the user just left still echoes that
     * organisation. Recording it first would put the old name straight back into
     * the chrome moments after the switch — the one thing the epoch exists to
     * prevent. Asserted by position because there is no JS runner here; it
     * catches the reordering and the deletion, which is what a regression of this
     * looks like.
     */
    #[Test]
    public function the_admin_client_drops_a_stale_response_before_recording_its_tenant(): void
    {
        $source = $this->spaSource(self::API_SERVICE_TS);

        $interceptor = strpos($source, 'interceptors.response.use');
        $this->assertNotFalse($interceptor, 'ApiService no longer installs a response interceptor; the echo and the epoch are both dead.');

        $body = substr($source, $interceptor);

        $stale = strpos($body, 'isFromSupersededEpoch');
        $record = strpos($body, 'recordServerTenant');

        $this->assertNotFalse($stale, 'the response interceptor no longer checks for a superseded epoch.');
        $this->assertNotFalse($record, 'the response interceptor no longer records the server-resolved tenant.');
        $this->assertLessThan(
            $record,
            $stale,
            'A stale response is recorded before it is dropped, so the previous organisation\'s name returns to the chrome after a switch.'
        );

        $this->assertStringContainsString(
            'interceptors.request.use(stampTenantEpoch)',
            $source,
            'requests are no longer stamped with an epoch, so no response can be told apart from a stale one.'
        );
    }

    /**
     * A switch opens the new epoch and empties the stores BEFORE it moves the
     * selection.
     *
     * The design calls the store sweep the single most important step: a store
     * still holding A's donors is a screen headed B showing A's donors, with no
     * error anywhere. Moving the selection first would leave a window in which the
     * app believes it is in B and every store still holds A.
     */
    #[Test]
    public function a_switch_opens_the_new_epoch_and_empties_the_stores_before_moving_the_selection(): void
    {
        $source = $this->spaSource(self::SWITCH_STORE_TS);

        $start = strpos($source, 'async function switchTo');
        $this->assertNotFalse($start, 'tenantSwitchStore no longer defines switchTo(); the switcher is gone.');

        $end = strpos($source, 'async function revertTo');
        $body = $end === false ? substr($source, $start) : substr($source, $start, $end - $start);

        $epoch = strpos($body, 'bumpTenantEpoch(');
        $reset = strpos($body, 'resetTenantScopedStores(');
        $select = strpos($body, 'saveDashboardMasjidId(');

        $this->assertNotFalse($epoch, 'switchTo() no longer opens a new request epoch; responses already on the wire will be delivered.');
        $this->assertNotFalse($reset, 'switchTo() no longer empties the tenant-scoped stores; the previous organisation\'s rows survive the switch.');
        $this->assertNotFalse($select, 'switchTo() no longer records the selection.');

        $this->assertLessThan($reset, $epoch, 'the epoch must open before the stores are emptied.');
        $this->assertLessThan($select, $reset, 'the stores must be emptied before the app starts believing it is in the new organisation.');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * An owner who ALSO holds a membership row in another organisation — the
     * fixture both sides of the gate are measured on.
     *
     * @return array{0: User, 1: Masjid, 2: Masjid}
     */
    private function adminHoldingTwoMembershipRows(): array
    {
        $admin = $this->masjidAdmin();
        $owned = $this->makeMasjid(['user_id' => $admin->id]);
        $other = $this->makeMasjid();

        $this->membership($admin, $owned, ['is_default' => true]);
        $this->membership($admin, $other, ['is_default' => false]);

        return [$admin, $owned, $other];
    }

    /**
     * Give $user a membership in $masjid, whatever the fixture already holds.
     *
     * `updateOrCreate`, and any default elsewhere is cleared first, because the
     * row may ALREADY exist by the time this runs: setting `masjids.user_id`
     * writes the owner's membership through a model hook
     * (`MasjidUser::ensureOwnerMembership`, from `Masjid::booted()`). A plain
     * `create()` would then die on the `(masjid_id, user_id)` unique index, and a
     * plain `is_default => true` on the one-default-per-user index — as a
     * QueryException inside a fixture, which reads like a broken test rather than
     * a fixture that has been overtaken.
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

    /**
     * Put this login back into the state of an owner the S2 backfill never
     * reached: ownership, and no `masjid_user` row at all.
     *
     * Needed because `Masjid::booted()`'s owner-membership hook now writes that
     * row on any save that carries a `user_id`, so the state cannot be built by
     * simply not writing one. It is still the state of every organisation
     * provisioned between S2's backfill and that hook, and it is precisely what
     * `TenantResolver::membershipFromOwnership()` and the repair endpoint exist
     * for — so it has to stay testable.
     */
    private function stripMembershipRows(User $user): void
    {
        MasjidUser::where('user_id', $user->id)->delete();
    }

    /** Read one of the SPA files, failing with its path rather than a warning. */
    private function spaSource(string $relativePath): string
    {
        $path = base_path($relativePath);

        $this->assertFileExists($path, "{$relativePath} has moved or been deleted; the PHP<->SPA contract it carries is unverified.");

        return (string) file_get_contents($path);
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

    private function masjidAdmin(): User
    {
        return $this->user('MasjidAdmin');
    }

    private function superAdmin(): User
    {
        return $this->user('SuperAdmin');
    }

    private function user(string $type): User
    {
        return User::factory()->create([
            'type' => $type,
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
    }
}
