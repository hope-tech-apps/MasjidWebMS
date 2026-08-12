<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Masjid;
use App\Models\Offering;
use App\Services\Registrations\RegistrationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * T-006g — the PUBLIC READ: GET /api/v1/offerings/{slug}.
 *
 * This is the front door. Everything else in T-006 existed and was tested, and
 * a family still could not reach any of it: nothing in resources/ called the
 * registration endpoints, so a registration could only be created by a caller
 * who already knew the API.
 *
 * Four things this suite exists to hold, in descending order of how badly they
 * hurt when they break:
 *
 *  1. TENANT SCOPING. /api/v1 never runs the tenant middleware, so the
 *     BelongsToMasjid global scope is UNBOUND and adds NO filter. Every lookup
 *     has to name the masjid itself. That is not hypothetical: the identical
 *     fail-open shape in SearchableTrait::filterByMasjid was live in production
 *     for months and measurably returned 14 rows across two tenants to an
 *     anonymous caller (.claude/rules/tenant-scoping.md,
 *     PublicApiTenantScopingTest). The tests below therefore assert against A's
 *     slug fetched with B's header, and against a missing header.
 *
 *  2. A CLOSED OFFERING MUST NOT PRESENT AS OPEN. `is_active` alone said "open"
 *     on offerings whose window had shut — the reason Offering::is_open and
 *     ::closed_reason exist at all. If this payload made that mistake, the page
 *     would show a Register button and every submission would be refused.
 *
 *  3. THE PAYLOAD LEAKS NOTHING. No registrant, no roster count, no seat
 *     counter, no capacity, no internal ids beyond the fee-plan id the register
 *     endpoint actually takes, no operational settings, no notification
 *     recipients.
 *
 *  4. MONEY. Integer minor units, currency beside every amount, and totals
 *     computed by the server's own listTotalFor() — never a client's
 *     multiplication. And, above all: reading this endpoint advances NOTHING.
 */
class PublicOfferingReadTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;

    private Masjid $masjidB;

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

        // /api/v1 never runs the tenant middleware — every lookup on this path
        // must filter masjid_id explicitly, and an unbound scope must not be
        // quietly doing the work in these tests either.
        app(TenantContext::class)->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
    }

    // ------------------------------------------------------- tenant scoping

    #[Test]
    public function masjid_a_cannot_fetch_masjid_bs_offering(): void
    {
        [$offeringB] = $this->makeOffering($this->masjidB, ['slug' => 'b-only-program']);

        // B's own header resolves it...
        $this->getJson('/api/v1/offerings/b-only-program', ['masjid-id' => (string) $this->masjidB->id])
            ->assertStatus(200)
            ->assertJsonPath('data.slug', 'b-only-program');

        // ...and A's header does not, even though the row plainly exists.
        $this->getJson('/api/v1/offerings/b-only-program', ['masjid-id' => (string) $this->masjidA->id])
            ->assertStatus(404);

        $this->assertNotNull(Offering::withoutMasjidScope()->find($offeringB->id));
    }

    #[Test]
    public function two_tenants_may_hold_the_same_slug_and_each_gets_only_their_own(): void
    {
        // The (masjid_id, slug) index is per-tenant, so this is a REAL state,
        // not a contrivance: "ramadan-iftar" belongs to both organisations.
        [, $planA] = $this->makeOffering($this->masjidA, ['slug' => 'ramadan-iftar', 'name' => 'A Iftar']);
        [, $planB] = $this->makeOffering($this->masjidB, ['slug' => 'ramadan-iftar', 'name' => 'B Iftar']);

        $a = $this->getJson('/api/v1/offerings/ramadan-iftar', ['masjid-id' => (string) $this->masjidA->id])
            ->assertStatus(200)->json('data');

        $b = $this->getJson('/api/v1/offerings/ramadan-iftar', ['masjid-id' => (string) $this->masjidB->id])
            ->assertStatus(200)->json('data');

        $this->assertSame('A Iftar', $a['name']);
        $this->assertSame('B Iftar', $b['name']);

        // The fee plan a caller would post back is the one belonging to the
        // organisation it asked — never the other tenant's price.
        $this->assertSame([$planA->id], array_column($a['fee_plans'], 'id'));
        $this->assertSame([$planB->id], array_column($b['fee_plans'], 'id'));
    }

    #[Test]
    public function a_request_with_no_masjid_header_is_refused_rather_than_answered(): void
    {
        $this->makeOffering($this->masjidA, ['slug' => 'headerless']);

        // The fail-OPEN shape this asserts against: no header => no WHERE => the
        // row answers anyway. A 400 is the only acceptable outcome.
        $this->getJson('/api/v1/offerings/headerless')->assertStatus(400);

        // And the falsy-bypass variant, which is how `masjid-id: 0` used to slip
        // through an `if ($header)` guard.
        $this->getJson('/api/v1/offerings/headerless', ['masjid-id' => '0'])->assertStatus(400);
    }

    #[Test]
    public function a_missing_a_deleted_and_a_switched_off_offering_are_all_the_same_404(): void
    {
        [$inactive] = $this->makeOffering($this->masjidA, ['slug' => 'draft-program', 'is_active' => false]);
        [$deleted] = $this->makeOffering($this->masjidA, ['slug' => 'gone-program']);
        $deleted->delete();

        foreach (['no-such-program', 'draft-program', 'gone-program'] as $slug) {
            $this->getJson("/api/v1/offerings/{$slug}", ['masjid-id' => (string) $this->masjidA->id])
                ->assertStatus(404);
        }

        // is_active=false is the UNPUBLISH switch, and the read agrees with the
        // write: OfferingRegistrationsController::findOffering uses the same
        // predicate, so a draft is never discoverable and a page can never
        // render a Register button the write path would 404.
        $this->assertFalse((bool) $inactive->fresh()->is_active);
    }

    // ------------------------------------------------ closed / at capacity

    #[Test]
    public function a_closed_window_says_closed_and_never_presents_as_open(): void
    {
        $this->makeOffering($this->masjidA, [
            'slug' => 'last-semester',
            'is_active' => true,              // still active — this is the trap
            'opens_at' => now()->subMonths(3),
            'closes_at' => now()->subMonth(),
        ]);

        $data = $this->fetch('last-semester');

        $this->assertFalse($data['is_open']);
        $this->assertSame('closed', $data['closed_reason']);
        $this->assertSame('closed', $data['registration_state']);
    }

    #[Test]
    public function an_offering_whose_window_has_not_opened_says_so_distinctly(): void
    {
        $this->makeOffering($this->masjidA, [
            'slug' => 'next-semester',
            'opens_at' => now()->addMonth(),
            'closes_at' => now()->addMonths(3),
        ]);

        $data = $this->fetch('next-semester');

        $this->assertFalse($data['is_open']);
        // Distinct from 'closed': "opens 4 September" and "you have missed it"
        // are different sentences on a page.
        $this->assertSame('not_yet_open', $data['closed_reason']);
        $this->assertSame('closed', $data['registration_state']);
    }

    #[Test]
    public function an_open_offering_inside_its_window_says_open(): void
    {
        $this->makeOffering($this->masjidA, [
            'slug' => 'open-now',
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addMonth(),
        ]);

        $data = $this->fetch('open-now');

        $this->assertTrue($data['is_open']);
        $this->assertNull($data['closed_reason']);
        $this->assertSame('open', $data['registration_state']);
    }

    #[Test]
    public function a_full_offering_reports_waitlist_not_closed(): void
    {
        // Full is NOT closed: RegistrationService waitlists the sign-up rather
        // than refusing it, so a page that said "closed" here would turn away
        // people the organisation wants on its waitlist.
        $this->makeOffering($this->masjidA, [
            'slug' => 'full-class',
            'capacity' => 12,
            'registration_count' => 12,
        ]);

        $data = $this->fetch('full-class');

        $this->assertTrue($data['is_open']);
        $this->assertNull($data['closed_reason']);
        $this->assertTrue($data['seats']['is_full']);
        $this->assertSame(0, $data['seats']['remaining']);
        $this->assertSame('waitlist', $data['registration_state']);
    }

    #[Test]
    public function a_closed_window_beats_a_free_seat_and_a_full_seat_alike(): void
    {
        // Precedence matters: an offering that is both full AND closed must read
        // as closed, or the page invites people onto a waitlist for something
        // that will never take them.
        $this->makeOffering($this->masjidA, [
            'slug' => 'full-and-closed',
            'capacity' => 5,
            'registration_count' => 5,
            'closes_at' => now()->subWeek(),
        ]);

        $data = $this->fetch('full-and-closed');

        $this->assertSame('closed', $data['registration_state']);
    }

    #[Test]
    public function unlimited_capacity_reports_remaining_as_null_not_zero(): void
    {
        $this->makeOffering($this->masjidA, ['slug' => 'unlimited', 'capacity' => null]);

        $data = $this->fetch('unlimited');

        // null is UNKNOWN, not 0 — a 0 here would render as "no places left" on
        // an offering that will take everybody.
        $this->assertNull($data['seats']['remaining']);
        $this->assertFalse($data['seats']['is_full']);
        $this->assertSame('open', $data['registration_state']);
    }

    #[Test]
    public function an_offering_whose_intake_form_is_gone_reports_closed(): void
    {
        [$offering] = $this->makeOffering($this->masjidA, ['slug' => 'orphaned']);

        // Forms soft-delete, and RegistrationService::register throws
        // offeringClosed() when it cannot load one. The window is still wide
        // open, so `is_open` is true — and reporting `open` as the verdict would
        // put a Register button on a page that refuses every submission.
        Form::query()->whereKey($offering->intake_form_id)->first()->delete();

        $data = $this->fetch('orphaned');

        $this->assertTrue($data['is_open']);
        $this->assertNull($data['intake_form']);
        $this->assertSame('closed', $data['registration_state']);
    }

    // ------------------------------------------------------------- the money

    #[Test]
    public function fee_plans_are_integer_minor_units_with_an_explicit_currency(): void
    {
        [$offering] = $this->makeOffering($this->masjidA, ['slug' => 'priced']);

        FeePlan::factory()->installment(9, 10000)->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $offering->id,
            'currency' => 'cad',
        ]);

        $plans = $this->fetch('priced')['fee_plans'];

        foreach ($plans as $plan) {
            $this->assertIsInt($plan['amount_minor'], 'An amount left minor units');
            $this->assertIsInt($plan['total_minor'], 'A total left minor units');
            // No amount in this payload travels without its denomination.
            $this->assertIsString($plan['currency']);
            $this->assertNotSame('', $plan['currency']);
        }

        $installment = collect($plans)->firstWhere('kind', FeePlan::KIND_INSTALLMENT);

        $this->assertSame(10000, $installment['amount_minor']);   // one charge
        $this->assertSame(90000, $installment['total_minor']);    // the commitment
        $this->assertSame(9, $installment['installment_count']);
        $this->assertSame('month', $installment['billing_interval']);
        $this->assertSame('cad', $installment['currency']);
        $this->assertTrue($installment['requires_payment']);
    }

    #[Test]
    public function every_plans_total_is_the_servers_own_list_total_never_a_multiplication_here(): void
    {
        [$offering] = $this->makeOffering($this->masjidA, ['slug' => 'every-kind']);
        FeePlan::factory()->free()->create(['masjid_id' => $this->masjidA->id, 'offering_id' => $offering->id]);
        FeePlan::factory()->installment(4, 2500)->create(['masjid_id' => $this->masjidA->id, 'offering_id' => $offering->id]);
        FeePlan::factory()->recurring('month', 1500)->create(['masjid_id' => $this->masjidA->id, 'offering_id' => $offering->id]);

        $plans = collect($this->fetch('every-kind')['fee_plans'])->keyBy('kind');
        $service = app(RegistrationService::class);

        // The point is not the arithmetic, it is the SOURCE: `total_minor` is
        // whatever listTotalFor() says, which is the same function that
        // snapshots list_total_minor at intake. If those two ever diverge, a
        // family is quoted one number and charged another.
        foreach (FeePlan::query()->where('offering_id', $offering->id)->get() as $plan) {
            $this->assertSame(
                $service->listTotalFor($plan),
                $plans[$plan->kind]['total_minor'],
                "{$plan->kind} total disagrees with the server's own snapshot function"
            );
        }

        // A free plan is the declared carve-out: 0, and no Stripe leg at all.
        $this->assertSame(0, $plans[FeePlan::KIND_FREE]['total_minor']);
        $this->assertFalse($plans[FeePlan::KIND_FREE]['requires_payment']);

        // Open-ended recurring has no finite commitment: the total is ONE
        // interval's charge, and billing_interval is what says so.
        $this->assertSame(1500, $plans[FeePlan::KIND_RECURRING]['total_minor']);
        $this->assertSame('month', $plans[FeePlan::KIND_RECURRING]['billing_interval']);
    }

    #[Test]
    public function deactivated_fee_plans_are_never_offered(): void
    {
        [$offering, $active] = $this->makeOffering($this->masjidA, ['slug' => 'replaced-price']);

        // Plans are immutable: a price change is deactivate-and-replace, so the
        // superseded row lives forever. Advertising it would be a price nobody
        // can buy — RegistrationService refuses an inactive plan outright.
        $old = FeePlan::factory()->inactive()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $offering->id,
            'amount_minor' => 5000,
            'label' => 'Early bird (ended)',
        ]);

        $ids = array_column($this->fetch('replaced-price')['fee_plans'], 'id');

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    #[Test]
    public function a_plan_with_an_unrecognised_money_kind_is_withheld_rather_than_guessed(): void
    {
        [$offering, $good] = $this->makeOffering($this->masjidA, ['slug' => 'odd-kind']);

        // FeePlan has NO kind() degrade helper on purpose: guessing `free` would
        // waive a fee and guessing `one_time` could charge the wrong shape. A row
        // whose kind is not in FeePlan::KINDS is therefore not presented as
        // purchasable — and it must not 500 the public page either, which is what
        // listTotalFor() would do if such a row reached it.
        $rogue = FeePlan::factory()->create([
            'masjid_id' => $this->masjidA->id,
            'offering_id' => $offering->id,
        ]);
        FeePlan::withoutMasjidScope()->whereKey($rogue->id)->update(['kind' => 'barter']);

        $response = $this->getJson('/api/v1/offerings/odd-kind', ['masjid-id' => (string) $this->masjidA->id])
            ->assertStatus(200);

        $this->assertSame([$good->id], array_column($response->json('data.fee_plans'), 'id'));
    }

    #[Test]
    public function reading_the_offering_writes_nothing_at_all(): void
    {
        [$offering] = $this->makeOffering($this->masjidA, ['slug' => 'read-only', 'capacity' => 10]);

        $snapshot = fn (Offering $o): array => [
            'registration_count' => (int) $o->registration_count,
            'updated_at' => (string) $o->updated_at,
        ];

        $before = $snapshot($offering->fresh());

        $this->fetch('read-only');

        // Not even a touched timestamp: a READ that writes is how a seat counter
        // starts drifting from the rows it is supposed to count.
        $this->assertSame($before, $snapshot($offering->fresh()));
        $this->assertDatabaseCount('registrations', 0);
        $this->assertDatabaseCount('form_responses', 0);
        $this->assertDatabaseCount('registration_payments', 0);
    }

    // ------------------------------------------------------- what leaks out

    #[Test]
    public function the_payload_carries_no_registrant_no_roster_count_and_no_seat_counter(): void
    {
        [$offering, $plan] = $this->makeOffering($this->masjidA, ['slug' => 'has-signups', 'capacity' => 10]);

        // A real registration by a real person, through the real service.
        app(RegistrationService::class)->register(
            $offering,
            $plan,
            Contact::factory()->create([
                'masjid_id' => $this->masjidA->id,
                'first_name' => 'Khadija',
                'last_name' => 'Rahman',
                'email' => 'khadija@example.test',
            ]),
            ['full_name' => 'Khadija Rahman']
        );

        $response = $this->getJson('/api/v1/offerings/has-signups', ['masjid-id' => (string) $this->masjidA->id])
            ->assertStatus(200);

        $body = $response->getContent();
        $data = $response->json('data');

        // Nobody's name, address or handle appears anywhere in the response.
        foreach (['Khadija', 'Rahman', 'khadija@example.test'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "A registrant's details reached a public payload");
        }

        // No count of PEOPLE. `seats.remaining` is a property of the offering
        // (how many more places it will accept); registration_count is a count
        // of the people in it, and a public page is never a window onto the CRM.
        $this->assertArrayNotHasKey('registration_count', $data);
        $this->assertArrayNotHasKey('registrations_count', $data);
        $this->assertArrayNotHasKey('registrations', $data);
        $this->assertArrayNotHasKey('registrants', $data);
        $this->assertArrayNotHasKey('registrations_by_status', $data);

        // Capacity is withheld too: publishing capacity AND remaining hands out
        // the subtraction, which is the seat counter by another name.
        $this->assertArrayNotHasKey('capacity', $data);
        $this->assertArrayNotHasKey('capacity', $data['seats']);
        $this->assertSame(9, $data['seats']['remaining']);
    }

    #[Test]
    public function the_payload_carries_no_internal_ids_beyond_the_fee_plan_id_needed_to_register(): void
    {
        [$offering, $plan] = $this->makeOffering($this->masjidA, ['slug' => 'no-ids', 'group_id' => null]);

        $data = $this->fetch('no-ids');

        // The public API is addressed by SLUG (offerings) and UUID
        // (registrations). None of these is needed to register, so none is
        // published.
        foreach (['id', 'masjid_id', 'intake_form_id', 'group_id'] as $key) {
            $this->assertArrayNotHasKey($key, $data, "{$key} reached the public payload");
        }

        $this->assertArrayNotHasKey('id', $data['intake_form']);
        $this->assertArrayNotHasKey('slug', $data['intake_form']);

        // The ONE exception, and it is here because POST
        // /api/v1/offerings/{slug}/register takes fee_plan_id as its input.
        $this->assertSame($plan->id, $data['fee_plans'][0]['id']);
        $this->assertArrayNotHasKey('masjid_id', $data['fee_plans'][0]);
        $this->assertArrayNotHasKey('offering_id', $data['fee_plans'][0]);

        $this->assertSame($offering->slug, $data['slug']);
    }

    #[Test]
    public function operational_settings_never_reach_the_public_payload(): void
    {
        [$offering] = $this->makeOffering($this->masjidA, ['slug' => 'settings-test']);

        $offering->update(['settings' => ['internal_note' => 'call the imam first', 'waitlist' => true]]);

        Form::query()->whereKey($offering->intake_form_id)->first()->update([
            'settings' => [
                'submitButtonLabel' => 'Sign up',
                'intro' => 'Please answer for each child.',
                // Operational detail, and the reason the form's settings bag is
                // allowlisted rather than passed through.
                'notificationEmails' => ['registrar@example.test'],
                'identityMap' => ['email' => 'guardian_email'],
                // A legacy per-form fee. An offering's money comes from its FEE
                // PLANS and nowhere else — RegistrationService prices from the
                // plan and leaves form_responses.amount_due null — so publishing
                // this would put a second, contradictory price on the page.
                'fee' => ['amount' => 40, 'currency' => 'USD'],
            ],
        ]);

        $response = $this->getJson('/api/v1/offerings/settings-test', ['masjid-id' => (string) $this->masjidA->id])
            ->assertStatus(200);

        $body = $response->getContent();
        $data = $response->json('data');

        $this->assertArrayNotHasKey('settings', $data);
        $this->assertStringNotContainsString('call the imam first', $body);
        $this->assertStringNotContainsString('registrar@example.test', $body);
        $this->assertStringNotContainsString('identityMap', $body);

        // The five wording keys, and only those.
        $this->assertSame(
            ['submitButtonLabel', 'successTitle', 'successBody', 'successNextSteps', 'intro'],
            array_keys($data['intake_form']['settings'])
        );
        $this->assertSame('Sign up', $data['intake_form']['settings']['submitButtonLabel']);
        $this->assertArrayNotHasKey('fee', $data['intake_form']);
        $this->assertArrayNotHasKey('fee', $data['intake_form']['settings']);

        // And no SECOND "is it open" beside the offering's own — two of them on
        // one payload is how they drift and one starts lying.
        $this->assertArrayNotHasKey('accepting', $data['intake_form']);
        $this->assertArrayNotHasKey('closed_reason', $data['intake_form']);
    }

    #[Test]
    public function the_renderable_copy_and_the_intake_questions_are_present(): void
    {
        [$offering] = $this->makeOffering($this->masjidA, [
            'slug' => 'weekend-school',
            'name' => 'Weekend School 2026-2027',
            'description' => "Qur'an, Arabic and Islamic studies, Saturdays 10am-1pm.",
            'kind' => Offering::KIND_PROGRAM,
        ]);

        $data = $this->fetch('weekend-school');

        $this->assertSame('weekend-school', $data['slug']);
        $this->assertSame('Weekend School 2026-2027', $data['name']);
        $this->assertSame("Qur'an, Arabic and Islamic studies, Saturdays 10am-1pm.", $data['description']);
        $this->assertSame('program', $data['kind']);

        // The questions a family is about to answer — the renderer draws the
        // form from this and needs no second request for it.
        $this->assertSame(
            Form::query()->whereKey($offering->intake_form_id)->first()->schema,
            $data['intake_form']['schema']
        );
    }

    #[Test]
    public function an_unrecognised_offering_kind_degrades_to_event_rather_than_reaching_the_page(): void
    {
        [$offering] = $this->makeOffering($this->masjidA, ['slug' => 'odd-offering-kind']);

        Offering::withoutMasjidScope()->whereKey($offering->id)->update(['kind' => 'seance']);

        // Offering::kind() is presentation only, so an unknown value degrades to
        // the plainest presentation instead of arriving at the renderer as a raw
        // string it has no branch for. (FeePlan makes the opposite call, above,
        // because that kind decides MONEY.)
        $this->assertSame('event', $this->fetch('odd-offering-kind')['kind']);
    }

    // ============================= helpers =============================

    private function fetch(string $slug, ?Masjid $masjid = null): array
    {
        return $this->getJson(
            "/api/v1/offerings/{$slug}",
            ['masjid-id' => (string) ($masjid ?? $this->masjidA)->id]
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
        ], $overrides));
    }

    /**
     * An offering pinned to one masjid (form included — the factory default
     * falls back to Masjid::first(), which is the wrong tenant here) plus one
     * active one-time plan.
     *
     * @return array{0: Offering, 1: FeePlan}
     */
    private function makeOffering(Masjid $masjid, array $state = []): array
    {
        $offering = Offering::factory()->forMasjid($masjid)->create($state);

        $plan = FeePlan::factory()->create([
            'masjid_id' => $masjid->id,
            'offering_id' => $offering->id,
        ]);

        return [$offering, $plan];
    }
}
