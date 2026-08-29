<?php

namespace Tests\Feature;

use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DELETING A FORM MUST NOT SILENTLY CLOSE A PROGRAM.
 *
 * `offerings.intake_form_id` is NOT NULL and `RegistrationService::register()`
 * throws `offeringClosed()` the instant it cannot load the form — but forms
 * SOFT-delete, so the column stays populated while the row it points at goes
 * away underneath it. Measured end to end before the guard:
 *
 *     DELETE /api/admin/masjids/{m}/forms/{intake_form_id}
 *         -> 200 "Form deleted successfully"
 *     PUBLIC  registration_state = closed, intake_form = null
 *     ADMIN   /offerings -> is_open = true, closed_reason = null   (a green "Open")
 *
 * An administrator tidying up an old form stopped every registration for a
 * program while every admin screen went on saying it was running. This is the
 * codebase's recurring shape — a write that fails while the UI reports success.
 *
 * ## The choice, and why
 *
 * REFUSE the delete, and ALSO make the admin state loud. Not one or the other:
 *
 *  - Refusing is the only option with a legal end state. There is no "this
 *    offering has no intake form"; the column is NOT NULL and no admin surface
 *    can restore a soft-deleted form, so allowing the delete leaves a required
 *    reference dangling with no route back.
 *  - The precedent is one file away and it is the same act one level down:
 *    `OfferingsController::destroy` refuses to delete an offering holding live
 *    registrations and points at the non-destructive switch instead.
 *  - There IS a non-destructive path that does what the admin wanted:
 *    `is_active = false` on the form stops standalone submissions and does NOT
 *    break offering registration, because `register()` loads the form to
 *    validate against and never consults its `is_active`. That asymmetry is
 *    surprising enough to be worth a test of its own, below.
 *  - The loud state is still required, because rows broken BEFORE the guard are
 *    still out there and an offering can be created pointing at an
 *    already-deleted form id.
 */
class OfferingIntakeFormDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    private User $admin;

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

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid();
        $this->admin = $this->makeAdminFor($this->masjid);
    }

    #[Test]
    public function deleting_an_offerings_intake_form_is_refused(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create([
            'slug' => 'weekend-school',
            'name' => 'Weekend School 2026',
        ]);
        FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->deleteJson(
            "/api/admin/masjids/{$this->masjid->id}/forms/{$offering->intake_form_id}"
        )->assertStatus(422);

        // The refusal NAMES the programs, because "this form is in use" sends an
        // admin hunting through a list.
        $this->assertStringContainsString('Weekend School 2026', (string) $response->json('message'));
        $this->assertSame(['Weekend School 2026'], $response->json('offerings'));

        // Nothing was deleted, and the program still takes registrations.
        $this->assertNull(Form::query()->whereKey($offering->intake_form_id)->first()->deleted_at);

        $data = $this->getJson("/api/v1/offerings/weekend-school", [
            'masjid-id' => (string) $this->masjid->id,
        ])->assertStatus(200)->json('data');

        $this->assertSame('open', $data['registration_state']);
        $this->assertNotNull($data['intake_form']);
    }

    #[Test]
    public function a_form_no_offering_uses_still_deletes(): void
    {
        // The guard must be narrow: ordinary sign-up forms are deleted routinely
        // and nothing about this change may make that harder.
        $form = Form::create([
            'masjid_id' => $this->masjid->id,
            'name' => 'Old Ramadan survey',
            'slug' => 'old-ramadan-survey',
            'schema' => ['fields' => [['key' => 'q', 'type' => 'text', 'label' => 'Q']]],
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/forms/{$form->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertSoftDeleted('forms', ['id' => $form->id]);
    }

    #[Test]
    public function a_soft_deleted_offering_does_not_block_its_forms_deletion(): void
    {
        // Nobody can register for a deleted offering, so it has no claim on the
        // form. Blocking on one would make the guard un-escapable: an admin who
        // removed a program could never tidy up after it.
        $offering = Offering::factory()->forMasjid($this->masjid)->create(['slug' => 'retired']);
        $formId = $offering->intake_form_id;

        $offering->delete();

        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/forms/{$formId}")
            ->assertStatus(200);

        $this->assertSoftDeleted('forms', ['id' => $formId]);
    }

    #[Test]
    public function another_tenants_offering_neither_blocks_nor_leaks(): void
    {
        // The blocking query names masjid_id explicitly and bypasses the global
        // scope, so it can neither be softened by an unbound context nor made to
        // report another organisation's program names into this one's error.
        $other = $this->makeMasjid();
        $this->makeAdminFor($other);

        $mine = Form::create([
            'masjid_id' => $this->masjid->id,
            'name' => 'Mine',
            'slug' => 'mine-' . uniqid(),
            'schema' => ['fields' => [['key' => 'q', 'type' => 'text', 'label' => 'Q']]],
            'is_active' => true,
        ]);

        // Another tenant's offering pointing at MY form id would be a data error
        // rather than a legal state, so build it the only way that can happen:
        // directly, bypassing the scope.
        $theirs = Offering::factory()->forMasjid($other)->create(['name' => 'Their Program']);
        Offering::withoutMasjidScope()->whereKey($theirs->id)->update(['intake_form_id' => $mine->id]);

        Sanctum::actingAs($this->admin);

        $response = $this->deleteJson("/api/admin/masjids/{$this->masjid->id}/forms/{$mine->id}")
            ->assertStatus(200);

        $this->assertStringNotContainsString('Their Program', $response->getContent());
        $this->assertSoftDeleted('forms', ['id' => $mine->id]);
    }

    #[Test]
    public function switching_the_form_off_is_the_non_destructive_path_and_leaves_registration_working(): void
    {
        // The recommendation the refusal makes has to be true, or it is worse
        // than no recommendation. `register()` loads the intake form to validate
        // against and deliberately never reads its `is_active` — the offering's
        // own window is what decides whether registration is open.
        $offering = Offering::factory()->forMasjid($this->masjid)->create(['slug' => 'still-open']);
        FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        Form::query()->whereKey($offering->intake_form_id)->first()->update(['is_active' => false]);

        $headers = ['masjid-id' => (string) $this->masjid->id];

        $this->assertSame('open', $this->getJson('/api/v1/offerings/still-open', $headers)
            ->assertStatus(200)->json('data.registration_state'));

        $this->postJson('/api/v1/offerings/still-open/register', [
            'fee_plan_id' => FeePlan::query()->where('offering_id', $offering->id)->first()->id,
            'payer' => ['name' => 'Fatima Noor', 'email' => 'fatima@test.local'],
            'data' => ['full_name' => 'Fatima Noor'],
        ], $headers)->assertStatus(200)->assertJsonPath('data.status', 'confirmed');

        // ...while the form's own standalone endpoint refuses, which is the
        // difference the admin is buying.
        $this->postJson("/api/v1/forms/{$offering->intake_form_id}/responses", [
            'data' => ['full_name' => 'Fatima Noor'],
        ], $headers)->assertStatus(422);
    }

    #[Test]
    public function a_program_broken_before_the_guard_existed_reads_as_broken_everywhere(): void
    {
        // The guard stops NEW breakage. Rows already in this state — and rows
        // that will get there through a restore or a direct fix — must still be
        // legible, and this is the assertion the original defect fails: the
        // admin list said "Open".
        $offering = Offering::factory()->forMasjid($this->masjid)->create(['slug' => 'already-broken']);
        FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        // The state the DELETE used to leave behind, reproduced at the model
        // level since the endpoint now refuses it.
        Form::query()->whereKey($offering->intake_form_id)->first()->delete();

        Sanctum::actingAs($this->admin);

        $row = collect(
            $this->getJson("/api/admin/masjids/{$this->masjid->id}/offerings")
                ->assertStatus(200)->json('data.data')
        )->firstWhere('slug', 'already-broken');

        // The two fields the badge used to be built from are unchanged and still
        // say "open" — which is exactly why the badge is no longer built on them.
        $this->assertTrue($row['is_open']);
        $this->assertNull($row['closed_reason']);

        $this->assertSame('closed', $row['registration_state']);
        $this->assertSame('no_intake_form', $row['registration_state_reason']);
        $this->assertFalse($row['has_intake_form']);

        // And the write path really does refuse, so the state is not alarmism.
        $this->postJson('/api/v1/offerings/already-broken/register', [
            'fee_plan_id' => FeePlan::query()->where('offering_id', $offering->id)->first()->id,
            'payer' => ['name' => 'Omar Said', 'email' => 'omar@test.local'],
            'data' => ['full_name' => 'Omar Said'],
        ], ['masjid-id' => (string) $this->masjid->id])->assertStatus(422);

        $this->assertDatabaseCount('registrations', 0);
    }

    // ============================= helpers =============================

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
}
