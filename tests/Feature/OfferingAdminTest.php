<?php

namespace Tests\Feature;

use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Group;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-006d — offerings + fee plans over HTTP:
 * /api/admin/masjids/{masjid_id}/offerings[/{id}/fee-plans].
 *
 * Mirrors GroupCrudTest: the same two paths keep organization A out of B —
 * targeting B's masjid in the route is a 403 (ResolveMasjidTenant), and B's id
 * under A's own route is a 404 (the BelongsToMasjid scope makes findOrFail
 * miss the row).
 *
 * On top of that this suite pins the two refusals that are the point of the
 * slice:
 *   - a FEE PLAN CANNOT BE EDITED (registrations snapshot its price, so an
 *     edit would retroactively restate what somebody agreed to pay), and
 *     deleting one deactivates it instead;
 *   - an OFFERING CANNOT BE DELETED out from under live registrations.
 */
class OfferingAdminTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;

    private Masjid $masjidB;

    private User $adminA;

    private Offering $offeringA;

    private Offering $offeringB;

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

        // Offerings reuse the contacts permissions and fee plans the donations
        // ones (see routes/admin.php), so the bridged masjid-admin role must be
        // seeded before the admins exist.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();

        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);

        // Seeded while the context is UNBOUND (no request yet), so the explicit
        // masjid_id is honored rather than overridden by the creating hook.
        $this->offeringA = Offering::factory()->forMasjid($this->masjidA)->create([
            'name' => 'Weekend School 2026',
            'slug' => 'weekend-school-2026',
            'kind' => Offering::KIND_PROGRAM,
        ]);
        Offering::factory()->forMasjid($this->masjidA)->create([
            'name' => 'Eid Dinner',
            'slug' => 'eid-dinner',
            'kind' => Offering::KIND_EVENT,
            'is_active' => false,
        ]);
        $this->offeringB = Offering::factory()->forMasjid($this->masjidB)->create([
            'name' => 'Weekend School 2026',
            'slug' => 'weekend-school-2026',
        ]);
    }

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
            // Offerings live inside the CRM route group, gated by
            // masjids.crm_enabled (default false; CrmFeatureGateTest covers it).
            'crm_enabled' => true,
        ], $overrides));
    }

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

    private function offeringsUrl(?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjidA)->id . '/offerings';
    }

    private function plansUrl(Offering $offering, ?Masjid $masjid = null): string
    {
        return $this->offeringsUrl($masjid) . '/' . $offering->id . '/fee-plans';
    }

    // ---------- auth ----------

    #[Test]
    public function index_rejects_unauthenticated_requests(): void
    {
        $this->getJson($this->offeringsUrl())->assertStatus(401);
    }

    // ---------- index ----------

    #[Test]
    public function index_returns_only_this_organizations_offerings(): void
    {
        Sanctum::actingAs($this->adminA);

        $response = $this->getJson($this->offeringsUrl())->assertOk();

        $this->assertSame(2, $response->json('data.total'));
    }

    #[Test]
    public function index_filters_by_kind_search_and_activity(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->assertSame(1, $this->getJson($this->offeringsUrl() . '?kind=' . Offering::KIND_PROGRAM)
            ->assertOk()->json('data.total'));

        $this->assertSame(1, $this->getJson($this->offeringsUrl() . '?search=' . urlencode('Eid'))
            ->assertOk()->json('data.total'));

        $this->assertSame(1, $this->getJson($this->offeringsUrl() . '?active_only=1')
            ->assertOk()->json('data.total'));
    }

    // ---------- options (the page-builder picker, T-006g) ----------

    #[Test]
    public function options_lists_only_this_organizations_offerings(): void
    {
        Sanctum::actingAs($this->adminA);

        $options = $this->getJson($this->offeringsUrl() . '/options')->assertOk()->json('data');

        $slugs = array_column($options, 'slug');

        $this->assertCount(2, $options);
        $this->assertContains('weekend-school-2026', $slugs);
        $this->assertContains('eid-dinner', $slugs);

        // B holds an offering with the IDENTICAL slug (the (masjid_id, slug)
        // index is per-tenant), so a leak here shows up as a third row rather
        // than as an obviously foreign name — which is why this asserts on the
        // ID, not on the slug.
        $this->assertSame('weekend-school-2026', $this->offeringB->slug);
        $this->assertNotContains($this->offeringB->id, array_column($options, 'id'));
    }

    #[Test]
    public function an_offerings_public_description_round_trips_through_the_admin_api(): void
    {
        Sanctum::actingAs($this->adminA);

        // The prose an anonymous visitor is served (T-006g). It has no home
        // anywhere else: `settings` is an unvalidated knob bag the admin form
        // does not write, and the intake FORM's description is the wording above
        // the questions and can be shared by several offerings.
        $prose = "Qur'an, Arabic and Islamic studies.\nSaturdays, 10am to 1pm.";

        $created = $this->postJson($this->offeringsUrl(), [
            'name' => 'Weekend School 2027',
            'description' => $prose,
            'slug' => 'weekend-school-2027',
            'kind' => Offering::KIND_PROGRAM,
            'intake_form_id' => $this->offeringA->intake_form_id,
        ])->assertStatus(201)->json('data');

        $this->assertSame($prose, $created['description']);

        // And it can be cleared again — an admin who published copy by mistake
        // must be able to take it down without deleting the offering.
        $this->putJson($this->offeringsUrl() . '/' . $created['id'], ['description' => ''])
            ->assertOk();

        $this->assertNull(Offering::withoutMasjidScope()->find($created['id'])->description);
    }

    #[Test]
    public function options_is_not_a_window_onto_the_roster(): void
    {
        Sanctum::actingAs($this->adminA);

        $option = collect($this->getJson($this->offeringsUrl() . '/options')->assertOk()->json('data'))
            ->firstWhere('slug', 'weekend-school-2026');

        // A page-builder picker has no business with the CRM.
        // `registration_count` is a count of PEOPLE; the roster screens are
        // where those numbers live.
        $this->assertArrayNotHasKey('registration_count', $option);
        $this->assertArrayNotHasKey('capacity', $option);
        $this->assertArrayNotHasKey('registrations_count', $option);

        // What it DOES carry is the four facts that decide whether a published
        // registration block will actually work.
        $this->assertSame(
            ['id', 'name', 'slug', 'kind', 'is_active', 'is_open', 'closed_reason', 'is_full', 'active_fee_plan_count'],
            array_keys($option)
        );
    }

    #[Test]
    public function options_reports_the_derived_open_state_and_the_active_plan_count(): void
    {
        Sanctum::actingAs($this->adminA);

        // A window that has already shut, on an offering that is still
        // is_active — the exact trap Offering::is_open exists for. An admin
        // attaching this to a page must see it here, not from a family who
        // could not sign up.
        $this->offeringA->update([
            'opens_at' => now()->subMonths(3),
            'closes_at' => now()->subMonth(),
        ]);

        FeePlan::create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $this->offeringA->id,
            'kind' => FeePlan::KIND_ONE_TIME,
            'amount_minor' => 15000,
            'currency' => 'usd',
            'label' => 'Standard',
            'is_active' => true,
        ]);
        FeePlan::create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $this->offeringA->id,
            'kind' => FeePlan::KIND_ONE_TIME,
            'amount_minor' => 12000,
            'currency' => 'usd',
            'label' => 'Early bird (ended)',
            'is_active' => false,
        ]);

        $option = collect($this->getJson($this->offeringsUrl() . '/options')->assertOk()->json('data'))
            ->firstWhere('slug', 'weekend-school-2026');

        $this->assertTrue($option['is_active']);
        $this->assertFalse($option['is_open']);
        $this->assertSame('closed', $option['closed_reason']);

        // ACTIVE plans only. The public register endpoint takes a fee_plan_id
        // and refuses an inactive plan, so a deactivated one is not a plan a
        // family can use — counting it would tell the admin the block works.
        $this->assertSame(1, $option['active_fee_plan_count']);
    }

    #[Test]
    public function options_refuses_another_organizations_route(): void
    {
        Sanctum::actingAs($this->adminA);

        // Same guardrail as every other admin route: naming B's masjid in the
        // path is a 403 from ResolveMasjidTenant, not an empty list.
        $this->getJson($this->offeringsUrl($this->masjidB) . '/options')->assertStatus(403);
    }

    #[Test]
    public function options_rejects_unauthenticated_requests(): void
    {
        $this->getJson($this->offeringsUrl() . '/options')->assertStatus(401);
    }

    #[Test]
    public function the_offering_label_follows_the_tenants_vertical(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->offeringsUrl())
            ->assertOk()
            ->assertJsonPath('meta.offering_label', config('verticals.masjid.terminology.programs'));

        $this->masjidA->update(['org_type' => Masjid::ORG_TYPE_COMMUNITY]);

        // Same endpoint, same code path, different word — nothing hardcodes a
        // vertical's vocabulary (.claude/rules/verticals.md).
        $this->getJson($this->offeringsUrl())
            ->assertOk()
            ->assertJsonPath('meta.offering_label', config('verticals.community.terminology.programs'));
    }

    // ---------- store ----------

    #[Test]
    public function store_creates_an_offering_and_derives_the_slug_from_the_name(): void
    {
        $form = Form::factory()->create(['masjid_id' => $this->masjidA->id]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->offeringsUrl(), [
            'name' => 'Summer Camp 2026',
            'kind' => Offering::KIND_PROGRAM,
            'intake_form_id' => $form->id,
            'capacity' => 40,
        ])->assertStatus(201);

        $this->assertDatabaseHas('offerings', [
            'masjid_id' => $this->masjidA->id,
            'name' => 'Summer Camp 2026',
            'slug' => 'summer-camp-2026',
            'kind' => Offering::KIND_PROGRAM,
            'capacity' => 40,
            'registration_count' => 0,
        ]);
    }

    #[Test]
    public function store_ignores_a_client_supplied_masjid_id(): void
    {
        $form = Form::factory()->create(['masjid_id' => $this->masjidA->id]);

        Sanctum::actingAs($this->adminA);

        $response = $this->postJson($this->offeringsUrl(), [
            'masjid_id' => $this->masjidB->id,
            'name' => 'Planted Offering',
            'intake_form_id' => $form->id,
        ])->assertStatus(201);

        $this->assertSame($this->masjidA->id, $response->json('data.masjid_id'));
        $this->assertDatabaseMissing('offerings', [
            'name' => 'Planted Offering',
            'masjid_id' => $this->masjidB->id,
        ]);
    }

    #[Test]
    public function store_refuses_an_intake_form_from_another_organization(): void
    {
        $foreignForm = Form::factory()->create(['masjid_id' => $this->masjidB->id]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->offeringsUrl(), [
            'name' => 'Cross Tenant Intake',
            'intake_form_id' => $foreignForm->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('offerings', ['name' => 'Cross Tenant Intake']);
    }

    #[Test]
    public function store_refuses_a_roster_group_from_another_organization(): void
    {
        $form = Form::factory()->create(['masjid_id' => $this->masjidA->id]);
        $foreignGroup = Group::factory()->create(['masjid_id' => $this->masjidB->id]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->offeringsUrl(), [
            'name' => 'Cross Tenant Roster',
            'intake_form_id' => $form->id,
            'group_id' => $foreignGroup->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function store_requires_an_intake_form(): void
    {
        Sanctum::actingAs($this->adminA);

        // offerings.intake_form_id is NOT NULL — an offering without an intake
        // form could never take a registration.
        $this->postJson($this->offeringsUrl(), ['name' => 'No Form'])
            ->assertStatus(422);
    }

    #[Test]
    public function store_rejects_a_slug_already_used_in_this_organization(): void
    {
        $form = Form::factory()->create(['masjid_id' => $this->masjidA->id]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->offeringsUrl(), [
            'name' => 'Weekend School 2026',
            'intake_form_id' => $form->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function store_allows_a_slug_another_organization_already_uses(): void
    {
        $form = Form::factory()->create(['masjid_id' => $this->masjidA->id]);

        Sanctum::actingAs($this->adminA);

        // The unique index is (masjid_id, slug), not (slug): B already holds
        // "weekend-school-2026" and that must not block anyone else. A's own
        // row is what makes the test above fail, and this one prove the
        // difference.
        $this->postJson($this->offeringsUrl(), [
            'name' => 'Eid Dinner 2027',
            'slug' => 'weekend-school-2026-b',
            'intake_form_id' => $form->id,
        ])->assertStatus(201);

        $this->assertSame(
            2,
            Offering::withoutMasjidScope()->where('slug', 'weekend-school-2026')->count()
        );
    }

    #[Test]
    public function store_rejects_an_unknown_kind(): void
    {
        $form = Form::factory()->create(['masjid_id' => $this->masjidA->id]);

        Sanctum::actingAs($this->adminA);

        $this->postJson($this->offeringsUrl(), [
            'name' => 'Weird',
            'kind' => 'retreat',
            'intake_form_id' => $form->id,
        ])->assertStatus(422);
    }

    // ---------- show / update ----------

    #[Test]
    public function show_returns_the_offering_with_its_seat_position(): void
    {
        $this->offeringA->update(['capacity' => 10]);
        Registration::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $this->offeringA->id,
        ]);
        $this->offeringA->increment('registration_count');

        Sanctum::actingAs($this->adminA);

        $this->getJson($this->offeringsUrl() . '/' . $this->offeringA->id)
            ->assertOk()
            ->assertJsonPath('data.id', $this->offeringA->id)
            ->assertJsonPath('meta.seats.capacity', 10)
            ->assertJsonPath('meta.seats.taken', 1)
            ->assertJsonPath('meta.seats.remaining', 9)
            ->assertJsonPath('meta.registrations_by_status.' . Registration::STATUS_PENDING, 1);
    }

    #[Test]
    public function show_cannot_read_another_organizations_offering_via_own_route(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->offeringsUrl() . '/' . $this->offeringB->id)->assertStatus(404);
    }

    #[Test]
    public function admin_cannot_target_another_masjid_in_the_route(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->getJson($this->offeringsUrl($this->masjidB) . '/' . $this->offeringB->id)
            ->assertStatus(403);
    }

    #[Test]
    public function update_changes_the_offering_without_regenerating_its_slug(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->putJson($this->offeringsUrl() . '/' . $this->offeringA->id, [
            'name' => 'Weekend School 2026 — Boys',
            'is_active' => false,
        ])->assertOk();

        $this->assertDatabaseHas('offerings', [
            'id' => $this->offeringA->id,
            'name' => 'Weekend School 2026 — Boys',
            'is_active' => false,
            // Untouched: a partial update must not silently move a public URL.
            'slug' => 'weekend-school-2026',
        ]);
    }

    #[Test]
    public function update_cannot_rewrite_the_guarded_seat_counter(): void
    {
        $this->offeringA->increment('registration_count');

        Sanctum::actingAs($this->adminA);

        $this->putJson($this->offeringsUrl() . '/' . $this->offeringA->id, [
            'capacity' => 50,
            'registration_count' => 999,
        ])->assertOk();

        // registration_count is guarded on the model and written only inside the
        // locked intake / seat-release transactions.
        $this->assertSame(1, $this->offeringA->fresh()->registration_count);
    }

    #[Test]
    public function update_cannot_touch_another_organizations_offering(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->putJson($this->offeringsUrl() . '/' . $this->offeringB->id, ['name' => 'HIJACKED'])
            ->assertStatus(404);

        $this->assertDatabaseMissing('offerings', ['id' => $this->offeringB->id, 'name' => 'HIJACKED']);
    }

    // ---------- destroy ----------

    #[Test]
    public function destroy_soft_deletes_an_offering_nobody_is_registered_for(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->offeringsUrl() . '/' . $this->offeringA->id)->assertOk();

        $this->assertSoftDeleted('offerings', ['id' => $this->offeringA->id]);
    }

    #[Test]
    public function destroy_is_refused_while_a_paid_registration_would_be_stranded(): void
    {
        Registration::factory()->paid()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $this->offeringA->id,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->offeringsUrl() . '/' . $this->offeringA->id)
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertDatabaseHas('offerings', ['id' => $this->offeringA->id, 'deleted_at' => null]);
    }

    #[Test]
    public function destroy_is_refused_while_someone_is_waitlisted(): void
    {
        Registration::factory()->waitlisted()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $this->offeringA->id,
        ]);

        Sanctum::actingAs($this->adminA);

        // A waitlist is a promise the organization made; deleting the offering
        // under it strands people just as surely as deleting a paid seat.
        $this->deleteJson($this->offeringsUrl() . '/' . $this->offeringA->id)->assertStatus(422);
    }

    #[Test]
    public function destroy_allows_an_offering_whose_registrations_are_all_cancelled(): void
    {
        Registration::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $this->offeringA->id,
            'status' => Registration::STATUS_CANCELLED,
            'payment_status' => Registration::PAYMENT_CANCELED,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->offeringsUrl() . '/' . $this->offeringA->id)->assertOk();

        // SOFT delete: the cancelled registration still resolves its offering.
        $this->assertSoftDeleted('offerings', ['id' => $this->offeringA->id]);
    }

    #[Test]
    public function destroy_cannot_delete_another_organizations_offering(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->offeringsUrl() . '/' . $this->offeringB->id)->assertStatus(404);

        $this->assertDatabaseHas('offerings', ['id' => $this->offeringB->id, 'deleted_at' => null]);
    }

    // ---------- fee plans ----------

    #[Test]
    public function a_one_time_plan_can_be_created(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->plansUrl($this->offeringA), [
            'kind' => FeePlan::KIND_ONE_TIME,
            'amount_minor' => 15000,
            'currency' => 'USD',
            'label' => 'Standard',
        ])->assertStatus(201)
            ->assertJsonPath('data.currency', 'usd');

        $this->assertDatabaseHas('fee_plans', [
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $this->offeringA->id,
            'kind' => FeePlan::KIND_ONE_TIME,
            'amount_minor' => 15000,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function an_installment_plan_must_say_how_many_payments_it_is(): void
    {
        Sanctum::actingAs($this->adminA);

        // Money kinds never degrade: an installment plan without a count/interval
        // could not be turned into a schedule, so it fails at the boundary.
        $this->postJson($this->plansUrl($this->offeringA), [
            'kind' => FeePlan::KIND_INSTALLMENT,
            'amount_minor' => 10000,
            'label' => 'Monthly',
        ])->assertStatus(422);

        $this->postJson($this->plansUrl($this->offeringA), [
            'kind' => FeePlan::KIND_INSTALLMENT,
            'amount_minor' => 10000,
            'billing_interval' => FeePlan::INTERVAL_MONTH,
            'installment_count' => 9,
            'label' => 'Monthly — 9 payments',
        ])->assertStatus(201);
    }

    #[Test]
    public function a_free_plan_may_not_carry_an_amount_and_a_paid_one_may_not_be_zero(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->plansUrl($this->offeringA), [
            'kind' => FeePlan::KIND_FREE,
            'amount_minor' => 500,
            'label' => 'Free-ish',
        ])->assertStatus(422);

        // A $0 paid plan would mean a $0 Checkout Session, which the design
        // forbids outright — that case is the free plan.
        $this->postJson($this->plansUrl($this->offeringA), [
            'kind' => FeePlan::KIND_ONE_TIME,
            'amount_minor' => 0,
            'label' => 'Zero',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_one_time_plan_may_not_carry_a_billing_interval(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->plansUrl($this->offeringA), [
            'kind' => FeePlan::KIND_ONE_TIME,
            'amount_minor' => 15000,
            'billing_interval' => FeePlan::INTERVAL_MONTH,
            'label' => 'Confused',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_plan_cannot_be_created_under_another_organizations_offering(): void
    {
        Sanctum::actingAs($this->adminA);

        $this->postJson($this->plansUrl($this->offeringB), [
            'kind' => FeePlan::KIND_ONE_TIME,
            'amount_minor' => 15000,
            'label' => 'Planted',
        ])->assertStatus(404);

        $this->assertDatabaseCount('fee_plans', 0);
    }

    #[Test]
    public function a_fee_plan_can_never_be_edited(): void
    {
        $plan = FeePlan::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $this->offeringA->id,
            'amount_minor' => 15000,
            'label' => 'Standard',
        ]);

        Sanctum::actingAs($this->adminA);

        // THE invariant of this slice: registrations snapshot the price at
        // intake, so editing a live plan would retroactively restate what
        // somebody agreed to pay. The refusal is explicit — never a 200 whose
        // fields were quietly dropped.
        $this->putJson($this->plansUrl($this->offeringA) . '/' . $plan->id, [
            'amount_minor' => 1,
            'label' => 'Cheaper',
        ])->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertDatabaseHas('fee_plans', [
            'id' => $plan->id,
            'amount_minor' => 15000,
            'label' => 'Standard',
        ]);
    }

    #[Test]
    public function deleting_a_fee_plan_deactivates_it_rather_than_removing_the_row(): void
    {
        $plan = FeePlan::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $this->offeringA->id,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->plansUrl($this->offeringA) . '/' . $plan->id)->assertOk();

        // The row survives: registrations reference it with a RESTRICT FK and
        // read their currency through it.
        $this->assertDatabaseHas('fee_plans', ['id' => $plan->id, 'is_active' => false]);

        // Idempotent.
        $this->deleteJson($this->plansUrl($this->offeringA) . '/' . $plan->id)->assertOk();
    }

    #[Test]
    public function the_plan_list_can_be_narrowed_to_active_plans(): void
    {
        FeePlan::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $this->offeringA->id,
        ]);
        FeePlan::factory()->inactive()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $this->offeringA->id,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->assertCount(2, $this->getJson($this->plansUrl($this->offeringA))->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson($this->plansUrl($this->offeringA) . '?active_only=1')
            ->assertOk()->json('data'));
    }

    #[Test]
    public function another_organizations_plan_is_a_404_even_under_our_own_offering(): void
    {
        $foreignPlan = FeePlan::factory()->create([
            'masjid_id' => $this->masjidB->id,
            'offering_id' => $this->offeringB->id,
        ]);

        Sanctum::actingAs($this->adminA);

        $this->deleteJson($this->plansUrl($this->offeringA) . '/' . $foreignPlan->id)->assertStatus(404);

        $this->assertDatabaseHas('fee_plans', ['id' => $foreignPlan->id, 'is_active' => true]);
    }
}
