<?php

namespace Tests\Feature;

use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Masjid;
use App\Models\Offering;
use App\Models\User;
use App\Support\OfferingRegistrationState;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ONE VERDICT ABOUT WHETHER A FAMILY CAN REGISTER, AND EVERY SURFACE READS IT.
 *
 * Before 2026-08-12 there were four answers to that question and they disagreed:
 *
 *   public payload           window + intake form
 *   OfferingSectionEditor    window + is_full + active fee plan count
 *   offerings list           `is_open` (the window, and nothing else)
 *   offering detail header   `is_open`
 *
 * Two live states fell straight through the gaps, and in both of them `is_open`
 * reports TRUE and `closed_reason` reports NULL:
 *
 *  1. ZERO ACTIVE FEE PLANS. `POST /offerings/{slug}/register` takes a
 *     `fee_plan_id`, so with no plan there is nothing to name. Measured: an
 *     active offering with an intake form, no window and no plans published
 *     `registration_state: "open"` beside `fee_plans: []`, and the parent who
 *     filled the form in got 404 "This fee plan is not available." from
 *     `quote`. Two admin screens showed a green "Open" throughout.
 *  2. A SOFT-DELETED INTAKE FORM. The public payload already called this closed;
 *     the admin screens did not, so deleting a form silently stopped a program
 *     while every admin surface kept saying it was running.
 *
 * `App\Support\OfferingRegistrationState` is now the only implementation, and
 * `every_registration_verdict_comes_from_one_function` below is the test that
 * keeps it that way.
 */
class OfferingRegistrationStateTest extends TestCase
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

    // -------------------------------------------- the measured reproduction

    #[Test]
    public function an_offering_with_no_active_fee_plan_is_not_open(): void
    {
        // The exact fixture from the report: active, intake form present, NO
        // window at all, and zero fee plans.
        $offering = Offering::factory()->forMasjid($this->masjid)->create([
            'slug' => 'no-plans-program',
            'is_active' => true,
            'opens_at' => null,
            'closes_at' => null,
        ]);

        $this->assertSame(0, FeePlan::query()->where('offering_id', $offering->id)->count());

        $data = $this->fetch('no-plans-program');

        // The model's own answers are UNCHANGED — they are about the window, and
        // the window really is open. That is precisely why they are not enough.
        $this->assertTrue($data['is_open']);
        $this->assertNull($data['closed_reason']);
        $this->assertSame([], $data['fee_plans']);

        // The verdict is the one that accounts for the write path.
        $this->assertSame('closed', $data['registration_state']);
        $this->assertSame('no_fee_plan', $data['registration_state_reason']);
    }

    #[Test]
    public function the_state_and_the_write_path_now_agree_where_they_used_to_contradict(): void
    {
        // The contradiction itself, asserted end to end: whatever the READ says
        // is `open`, the WRITE must accept — and whatever it says is `closed`,
        // the write must refuse. This is the sentence
        // .claude/rules/registration-billing-data.md claimed and the code did
        // not implement.
        $offering = Offering::factory()->forMasjid($this->masjid)->create(['slug' => 'agreement']);

        $headers = ['masjid-id' => (string) $this->masjid->id];

        // No plans yet: the read says closed...
        $this->assertSame('closed', $this->fetch('agreement')['registration_state']);

        // ...and the write refuses, which is the half a parent hits.
        $this->postJson('/api/v1/offerings/agreement/quote', ['fee_plan_id' => 1], $headers)
            ->assertStatus(404)
            ->assertJsonPath('message', 'This fee plan is not available.');

        // Add a plan and both flip together.
        $plan = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $data = $this->fetch('agreement');

        $this->assertSame('open', $data['registration_state']);
        $this->assertNull($data['registration_state_reason']);

        $this->postJson('/api/v1/offerings/agreement/quote', ['fee_plan_id' => $plan->id], $headers)
            ->assertStatus(200);
    }

    #[Test]
    public function a_free_offering_still_needs_a_free_plan(): void
    {
        // The trap in the fix: "no fee plan" reads as "it is free", and it is
        // not — `register` takes a fee_plan_id whatever the price, so a genuinely
        // free program needs a `free` plan to point at.
        $offering = Offering::factory()->forMasjid($this->masjid)->create(['slug' => 'free-program']);

        $this->assertSame('no_fee_plan', $this->fetch('free-program')['registration_state_reason']);

        FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $data = $this->fetch('free-program');

        $this->assertSame('open', $data['registration_state']);
        $this->assertSame(0, $data['fee_plans'][0]['total_minor']);
        $this->assertFalse($data['fee_plans'][0]['requires_payment']);
    }

    #[Test]
    public function a_deactivated_plan_does_not_keep_an_offering_open(): void
    {
        // Plans are deactivate-and-replace, so a superseded row lives forever. It
        // is refused by `register` (planInactive) and withheld from the payload —
        // so it must not count towards "there is something to buy" either.
        $offering = Offering::factory()->forMasjid($this->masjid)->create(['slug' => 'replaced-only']);

        FeePlan::factory()->inactive()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $data = $this->fetch('replaced-only');

        $this->assertSame([], $data['fee_plans']);
        $this->assertSame('closed', $data['registration_state']);
        $this->assertSame('no_fee_plan', $data['registration_state_reason']);
    }

    #[Test]
    public function a_plan_with_an_unrecognised_money_kind_does_not_keep_an_offering_open_either(): void
    {
        // The payload withholds a plan whose kind is not in FeePlan::KINDS,
        // because listTotalFor() throws on one and money never degrades. The
        // count that decides the verdict must use the SAME predicate, or the
        // page reports `open` while publishing nothing purchasable — which is
        // the original defect wearing a different hat.
        $offering = Offering::factory()->forMasjid($this->masjid)->create(['slug' => 'barter-only']);

        $rogue = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);
        FeePlan::withoutMasjidScope()->whereKey($rogue->id)->update(['kind' => 'barter']);

        $data = $this->fetch('barter-only');

        $this->assertSame([], $data['fee_plans']);
        $this->assertSame('closed', $data['registration_state']);
        $this->assertSame('no_fee_plan', $data['registration_state_reason']);
    }

    #[Test]
    public function no_fee_plan_outranks_full(): void
    {
        // Precedence: `findFeePlan()` refuses before capacity is ever consulted,
        // so a full offering with nothing purchasable must not invite people onto
        // a waitlist they cannot join.
        Offering::factory()->forMasjid($this->masjid)->create([
            'slug' => 'full-and-unbuyable',
            'capacity' => 4,
            'registration_count' => 4,
        ]);

        $data = $this->fetch('full-and-unbuyable');

        $this->assertTrue($data['seats']['is_full']);
        $this->assertSame('closed', $data['registration_state']);
        $this->assertSame('no_fee_plan', $data['registration_state_reason']);
    }

    #[Test]
    public function a_closed_window_outranks_a_missing_fee_plan(): void
    {
        // The other way round: an admin whose window shut in March should be told
        // about the window, not sent to the Fee Plans tab.
        Offering::factory()->forMasjid($this->masjid)->create([
            'slug' => 'closed-and-unbuyable',
            'opens_at' => now()->subMonths(3),
            'closes_at' => now()->subMonth(),
        ]);

        $data = $this->fetch('closed-and-unbuyable');

        $this->assertSame('closed', $data['registration_state']);
        $this->assertSame('closed', $data['registration_state_reason']);
    }

    #[Test]
    public function a_deleted_intake_form_reports_its_own_reason(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create(['slug' => 'orphaned-form']);
        FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        Form::query()->whereKey($offering->intake_form_id)->first()->delete();

        $data = $this->fetch('orphaned-form');

        // Already reported `closed` before this work; what is new is that it says
        // WHY, so a renderer can explain itself and an admin screen can too.
        $this->assertTrue($data['is_open']);
        $this->assertNull($data['closed_reason']);
        $this->assertNull($data['intake_form']);
        $this->assertSame('closed', $data['registration_state']);
        $this->assertSame('no_intake_form', $data['registration_state_reason']);
    }

    #[Test]
    public function full_is_still_waitlist_and_not_closed(): void
    {
        // The clause that must survive the fix: `register()` QUEUES a sign-up for
        // a full offering rather than refusing it, so a surface saying "closed"
        // would turn away people the organisation wants on its waitlist.
        $offering = Offering::factory()->forMasjid($this->masjid)->create([
            'slug' => 'full-but-buyable',
            'capacity' => 3,
            'registration_count' => 3,
        ]);
        FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $data = $this->fetch('full-but-buyable');

        $this->assertSame('waitlist', $data['registration_state']);
        $this->assertNull($data['registration_state_reason']);
    }

    // ------------------------------------------------------ admin surfaces

    #[Test]
    public function the_admin_list_stops_saying_open_for_an_offering_nobody_can_register_for(): void
    {
        // The measured admin half: /offerings answered is_open=true,
        // closed_reason=null for both broken states, and the SPA rendered a green
        // "Open" badge from exactly those two fields.
        $noPlans = Offering::factory()->forMasjid($this->masjid)->create([
            'slug' => 'admin-no-plans',
            'name' => 'AAA No plans',
        ]);

        $noForm = Offering::factory()->forMasjid($this->masjid)->create([
            'slug' => 'admin-no-form',
            'name' => 'BBB No form',
        ]);
        FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $noForm->id,
        ]);
        Form::query()->whereKey($noForm->intake_form_id)->first()->delete();

        $healthy = Offering::factory()->forMasjid($this->masjid)->create([
            'slug' => 'admin-healthy',
            'name' => 'CCC Healthy',
        ]);
        FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $healthy->id,
        ]);

        Sanctum::actingAs($this->admin);

        $rows = collect(
            $this->getJson("/api/admin/masjids/{$this->masjid->id}/offerings")
                ->assertStatus(200)
                ->json('data.data')
        )->keyBy('slug');

        // The window fields are untouched — they were never wrong, only
        // insufficient, and other screens read them.
        $this->assertTrue($rows['admin-no-plans']['is_open']);
        $this->assertNull($rows['admin-no-plans']['closed_reason']);

        $this->assertSame('closed', $rows['admin-no-plans']['registration_state']);
        $this->assertSame('no_fee_plan', $rows['admin-no-plans']['registration_state_reason']);
        $this->assertSame(0, $rows['admin-no-plans']['active_fee_plan_count']);
        $this->assertTrue($rows['admin-no-plans']['has_intake_form']);

        $this->assertSame('closed', $rows['admin-no-form']['registration_state']);
        $this->assertSame('no_intake_form', $rows['admin-no-form']['registration_state_reason']);
        $this->assertFalse($rows['admin-no-form']['has_intake_form']);

        $this->assertSame('open', $rows['admin-healthy']['registration_state']);
        $this->assertNull($rows['admin-healthy']['registration_state_reason']);

        // The list did not lose anything it already served.
        foreach (['id', 'name', 'slug', 'kind', 'capacity', 'registration_count', 'fee_plans', 'registrations_count'] as $key) {
            $this->assertArrayHasKey($key, $rows['admin-healthy'], "{$key} disappeared from the offerings list");
        }
    }

    #[Test]
    public function the_admin_detail_and_the_section_picker_carry_the_same_verdict(): void
    {
        $offering = Offering::factory()->forMasjid($this->masjid)->create(['slug' => 'shared-verdict']);

        Sanctum::actingAs($this->admin);

        $detail = $this->getJson("/api/admin/masjids/{$this->masjid->id}/offerings/{$offering->id}")
            ->assertStatus(200)
            ->json('data');

        $option = collect(
            $this->getJson("/api/admin/masjids/{$this->masjid->id}/offerings/options")
                ->assertStatus(200)
                ->json('data')
        )->firstWhere('slug', 'shared-verdict');

        $public = $this->fetch('shared-verdict');

        // Three payloads, one answer — the property that stops the page-builder
        // warning and the public page disagreeing about the same program.
        foreach ([$detail, $option] as $payload) {
            $this->assertSame($public['registration_state'], $payload['registration_state']);
            $this->assertSame($public['registration_state_reason'], $payload['registration_state_reason']);
        }

        $this->assertSame(0, $option['active_fee_plan_count']);
        $this->assertTrue($option['has_intake_form']);
    }

    // -------------------------------------------------------- the meta-test

    /**
     * The verdict has ONE implementation.
     *
     * A lexical check, and a floor rather than a ceiling: it proves no PHP
     * source outside App\Support\OfferingRegistrationState reconstructs the
     * judgement out of its parts. Four hand-rolled copies is how this defect
     * happened, and the copies were each individually reasonable.
     */
    #[Test]
    public function every_registration_verdict_comes_from_one_function(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if (str_ends_with($path, 'OfferingRegistrationState.php')) {
                continue;
            }

            $source = file_get_contents($path);

            // Any file that writes the field must get it from the decider.
            if (str_contains($source, "'registration_state'")
                && ! str_contains($source, 'OfferingRegistrationState')
                && ! str_contains($source, 'State::decide')) {
                $offenders[] = str_replace(base_path() . '/', '', $path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These files produce a `registration_state` without going through "
            . "App\\Support\\OfferingRegistrationState. A second implementation of "
            . "\"can a family register\" is exactly what shipped four disagreeing answers:\n  "
            . implode("\n  ", $offenders)
        );
    }

    #[Test]
    public function the_reason_vocabulary_is_closed_and_covers_every_write_path_refusal(): void
    {
        // A reason a renderer has no branch for is worse than none, so the set is
        // declared and pinned. The first three are the model's own
        // `closed_reason` words, verbatim; the last three are the clauses no
        // other field reports.
        //
        // This assertion pinned FIVE while `decide()` produced a sixth. Both
        // halves of this test's name were false because of it: the vocabulary
        // was not closed, and `org_cannot_collect` was not a write-path refusal
        // — the page reported it while `register` went on answering 200 and
        // holding a seat nothing could pay for. It is both now: the reason is
        // declared here, and the clause behind it lives in
        // `isPurchasable()`, which is what `findFeePlan()` accepts on, so the
        // page, the quote and the write all refuse together.
        $this->assertSame([
            'inactive',
            'not_yet_open',
            'closed',
            'no_intake_form',
            'no_fee_plan',
            'org_cannot_collect',
        ], OfferingRegistrationState::REASONS);

        // And every one of them is reachable — a constant nothing produces is a
        // promise, not a vocabulary.
        $produced = [];

        $cases = [
            'inactive' => ['is_active' => false],
            'not_yet_open' => ['opens_at' => now()->addMonth()],
            'closed' => ['closes_at' => now()->subDay()],
        ];

        foreach ($cases as $expected => $state) {
            $offering = Offering::factory()->forMasjid($this->masjid)->create($state);
            FeePlan::factory()->free()->create([
                'masjid_id' => $this->masjid->id,
                'offering_id' => $offering->id,
            ]);

            $produced[] = OfferingRegistrationState::for($offering->fresh())['reason'];
        }

        // no_fee_plan: a live offering with a form and nothing to buy.
        $bare = Offering::factory()->forMasjid($this->masjid)->create();
        $produced[] = OfferingRegistrationState::for($bare)['reason'];

        // no_intake_form: a live offering with a plan and no form.
        $formless = Offering::factory()->forMasjid($this->masjid)->create();
        FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $formless->id,
        ]);
        Form::query()->whereKey($formless->intake_form_id)->first()->delete();
        $produced[] = OfferingRegistrationState::for($formless->fresh())['reason'];

        // org_cannot_collect: a live offering whose ONLY plan raises a charge,
        // on an organisation with no `stripe_account_id` (this file's fixture is
        // deliberately not onboarded). It is a distinct word from `no_fee_plan`
        // because the plan is right there — telling the registrar they have none
        // would send them to the wrong screen.
        $paidOnly = Offering::factory()->forMasjid($this->masjid)->create();
        FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $paidOnly->id,
        ]);
        $this->assertFalse($this->masjid->fresh()->canAcceptDonations());
        $produced[] = OfferingRegistrationState::for($paidOnly->fresh())['reason'];

        sort($produced);
        $expected = OfferingRegistrationState::REASONS;
        sort($expected);

        $this->assertSame($expected, $produced);
    }

    #[Test]
    public function an_organisation_that_cannot_collect_still_runs_its_free_tier(): void
    {
        // M4, the over-refusal half. A masjid running "Weekend school $450"
        // beside "Weekend school — fee waived", whose Stripe onboarding has
        // lapsed. The whole program read `closed / org_cannot_collect` — to the
        // scholarship families too — while `register` on the free tier answered
        // 200 confirmed in the same breath. The offering-level verdict was
        // computed from a per-PLAN fact.
        $offering = Offering::factory()->forMasjid($this->masjid)->create(['slug' => 'weekend-school']);

        $paid = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
            'amount_minor' => 45000,
            'label' => 'Weekend school',
        ]);
        $free = FeePlan::factory()->free()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
            'label' => 'Scholarship — fee waived',
        ]);

        $this->assertFalse($this->masjid->fresh()->canAcceptDonations());

        $page = $this->fetch('weekend-school');

        // The program is open, because one of its plans can genuinely be used.
        $this->assertSame('open', $page['registration_state']);
        $this->assertNull($page['registration_state_reason']);

        // And exactly one plan is published: the paid tier is withheld, so no
        // price is advertised for something this organisation cannot charge.
        $this->assertSame([$free->id], array_column($page['fee_plans'], 'id'));

        $headers = ['masjid-id' => (string) $this->masjid->id];

        // The write agrees with the page on BOTH plans.
        $this->postJson('/api/v1/offerings/weekend-school/register', [
            'fee_plan_id' => $free->id,
            'payer' => ['name' => 'Amal Yusuf', 'email' => 'amal@test.local'],
            'data' => ['full_name' => 'Amal Yusuf'],
        ], $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed');

        $this->postJson('/api/v1/offerings/weekend-school/register', [
            'fee_plan_id' => $paid->id,
            'payer' => ['name' => 'Bilal Ahmed', 'email' => 'bilal@test.local'],
            'data' => ['full_name' => 'Bilal Ahmed'],
        ], $headers)
            ->assertStatus(404)
            ->assertJsonPath('message', 'This fee plan is not available.');

        // The refused half wrote nothing: one registration, one seat.
        $this->assertSame(1, \App\Models\Registration::query()->count());
        $this->assertSame(1, (int) $offering->fresh()->registration_count);
    }

    // ============================= helpers =============================

    private function fetch(string $slug): array
    {
        return $this->getJson(
            "/api/v1/offerings/{$slug}",
            ['masjid-id' => (string) $this->masjid->id]
        )->assertStatus(200)->json('data');
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
            // masjids.crm_enabled (default false).
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
