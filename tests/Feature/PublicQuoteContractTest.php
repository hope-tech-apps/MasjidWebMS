<?php

namespace Tests\Feature;

use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Masjid;
use App\Models\Offering;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WHAT `POST /api/v1/offerings/{slug}/quote` MAY SAY, AND WHAT IT MAY REVEAL.
 *
 * Two defects, both in the same method, both of the shape this codebase keeps
 * producing: a surface that answers a question nobody checked it was allowed to
 * answer.
 *
 * ## 1. It priced offerings nobody could join
 *
 * `quote` resolved the offering through `findOffering()` (`is_active` only) and
 * went straight to the price — consulting neither the window, nor the intake
 * form, nor capacity, nor the `registration_state` the page had just rendered
 * from. Measured on this branch:
 *
 *     window closed a month ago    page  registration_state "closed"
 *                                  quote 200, amount_due_minor 15000,
 *                                        requires_payment true
 *
 *     intake form soft-deleted     page  "closed" / no_intake_form
 *                                  quote 200 for $150
 *                                  register 422
 *
 * A renderer that prices before it reads state quotes a parent $150 for a
 * program their child cannot join, and the refusal — if it comes at all — comes
 * after the form is filled in. The verdict now comes from
 * `App\Support\OfferingRegistrationState`, the SAME call the page renders from,
 * so there is one answer rather than a third.
 *
 * ## 2. It leaked an internal invariant message, and confirmed a row exists
 *
 * `findFeePlan()` filtered masjid + offering + key + `is_active` but NOT
 * `whereIn('kind', FeePlan::KINDS)` — while `OfferingPublicPayload::feePlans()`
 * and `OfferingRegistrationState::purchasablePlanCount()` both did, and
 * OfferingRegistrationState's own docblock asserted findFeePlan accepted on the
 * same predicate. Measured with `kind = 'sliding_scale'`:
 *
 *     page   fee_plans: []   registration_state "closed" / no_fee_plan
 *     quote  422 "Unrecognized fee plan kind 'sliding_scale' — money kinds
 *                 never degrade."
 *     (a nonexistent id, correctly: 404 "This fee plan is not available.")
 *
 * An invariant written for a developer reading a stack trace, delivered to an
 * anonymous caller — and a positive confirmation that a plan with that id and
 * that kind exists, which the page had just refused to publish.
 *
 * ## 3. The same shape, three more times, found by review of the fix above
 *
 * `the_page_and_the_quote_never_disagree` was written as a property test and
 * then given four fixtures that were ALL window/capacity states, so it went on
 * passing through a case where the page and the quote disagreed for a reason
 * that had nothing to do with the window. It now carries an
 * `org_cannot_collect` fixture, and the three cases below are the measured
 * reproductions the fix was verified against:
 *
 *     org with stripe_account_id = null    page closed/org_cannot_collect while
 *                                          fee_plans STILL published 15000
 *                                          quote 200, register 200 + a held seat
 *                                          with checkout_url null
 *
 *     billing_interval NULL / 'fortnight'  page open, plan published at 2500,
 *     installment_count 0                  quote 200, register 200 "your place
 *                                          is held", checkout 422, seat 1
 *
 * Both are one defect: a fact that decides whether money can move, enforced
 * where money moves and nowhere earlier. The predicate that decides which plans
 * exist for the public (`OfferingRegistrationState::isPurchasable`) now carries
 * every one of them, so the page, the quote and the write refuse together.
 */
class PublicQuoteContractTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

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

        // /api/v1 never runs the tenant middleware.
        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid();
    }

    // ---------------------------- 1. quote reads the state before it prices

    #[Test]
    public function a_closed_window_is_not_quoted(): void
    {
        // The measured reproduction, verbatim: a window that shut a month ago.
        [, $plan] = $this->makeOffering([
            'slug' => 'ended-program',
            'opens_at' => now()->subMonths(3),
            'closes_at' => now()->subMonth(),
        ]);

        // The page says closed...
        $this->assertSame('closed', $this->page('ended-program')['registration_state']);
        $this->assertSame('closed', $this->page('ended-program')['registration_state_reason']);

        // ...so the quote must not answer $150.
        $response = $this->quote('ended-program', $plan->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This offering is not currently accepting registrations.');

        // And no price travelled with the refusal.
        $this->assertNull($response->json('data'));
    }

    #[Test]
    public function an_offering_whose_intake_form_was_deleted_is_not_quoted(): void
    {
        // The second measured case. `offerings.intake_form_id` is NOT NULL and
        // forms SOFT-delete, so the column keeps pointing at a row that is gone.
        [$offering, $plan] = $this->makeOffering(['slug' => 'orphaned-form']);

        Form::query()->whereKey($offering->intake_form_id)->firstOrFail()->delete();

        $page = $this->page('orphaned-form');
        $this->assertSame('closed', $page['registration_state']);
        $this->assertSame('no_intake_form', $page['registration_state_reason']);

        $this->quote('orphaned-form', $plan->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This offering is not currently accepting registrations.');

        // The write path already refused this one — the quote now refuses it in
        // the same words, which is the whole point of one decider.
        $this->postJson('/api/v1/offerings/orphaned-form/register', [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Aisha Karim', 'email' => 'aisha@test.local'],
            'data' => ['full_name' => 'Aisha Karim'],
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('message', 'This offering is not currently accepting registrations.');
    }

    #[Test]
    public function an_inactive_offering_is_not_quoted_either(): void
    {
        // `is_active = false` is the unpublish switch; findOffering() already
        // refused it, so this pins that the state check did not somehow make the
        // older guard redundant in the wrong direction.
        [, $plan] = $this->makeOffering(['slug' => 'unpublished', 'is_active' => false]);

        $this->quote('unpublished', $plan->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'This offering is not available.');
    }

    #[Test]
    public function a_full_offering_is_still_quoted_because_the_waitlist_accepts_it(): void
    {
        // The clause that must survive the fix. `register()` QUEUES a sign-up
        // for a full offering rather than refusing it, so a family joining the
        // waitlist is entitled to know what the place will cost. Refusing here
        // would price the fix's own bug.
        [, $plan] = $this->makeOffering([
            'slug' => 'full-program',
            'capacity' => 3,
            'registration_count' => 3,
        ]);

        $this->assertSame('waitlist', $this->page('full-program')['registration_state']);

        $this->quote('full-program', $plan->id)
            ->assertStatus(200)
            ->assertJsonPath('data.amount_due_minor', 15000);
    }

    #[Test]
    public function an_open_offering_is_quoted_exactly_as_before(): void
    {
        // The regression floor: the fix must not have made quoting harder for
        // the case it exists to serve.
        [, $plan] = $this->makeOffering(['slug' => 'live-program']);

        $this->assertSame('open', $this->page('live-program')['registration_state']);

        $this->quote('live-program', $plan->id)
            ->assertStatus(200)
            ->assertJsonPath('data.list_total_minor', 15000)
            ->assertJsonPath('data.adjusted_total_minor', 15000)
            ->assertJsonPath('data.amount_due_minor', 15000)
            ->assertJsonPath('data.requires_payment', true)
            ->assertJsonPath('data.code_applied', false);
    }

    #[Test]
    public function the_page_and_the_quote_never_disagree(): void
    {
        // The property, rather than another case: whatever the page reports as
        // `closed`, the quote refuses; whatever it reports as open or waitlist,
        // the quote prices.
        //
        // Its four original fixtures were ALL window/capacity states, and that
        // is why it went on passing through the `org_cannot_collect` defect: the
        // loop never saw a fixture whose verdict came from anywhere but the
        // window. Given one, it failed — the page said `closed` and the quote
        // answered 200 with `amount_due_minor: 15000`, because
        // `OfferingPublicPayload` passed the organisation into `decide()` while
        // this endpoint called `for()`, which passed null, under a docblock
        // claiming the two were the same call. A property test is only as strong
        // as the shapes it is handed.
        //
        // `refused` is not always the same status: a clause about the OFFERING
        // (the window, the intake form) is a 422 from the decider, and a clause
        // about the PLAN (unpurchasable, including "this organisation cannot
        // collect a charge") is the same indistinguishable 404 a plan that never
        // existed gets. Both are refusals; neither prices anything.
        $offline = $this->makeMasjid(['stripe_account_id' => null, 'stripe_charges_enabled' => false]);

        $fixtures = [
            'agree-open' => [[], true, null],
            'agree-waitlist' => [['capacity' => 2, 'registration_count' => 2], true, null],
            'agree-not-yet' => [['opens_at' => now()->addMonth()], false, null],
            'agree-closed' => [['closes_at' => now()->subDay()], false, null],
            // The fixture the loop was missing. Paid plan, organisation with no
            // connected account: the page has nothing purchasable to publish.
            'agree-cannot-collect' => [[], false, $offline],
        ];

        foreach ($fixtures as $slug => [$state, $quotable, $masjid]) {
            [, $plan] = $this->makeOffering(array_merge(['slug' => $slug], $state), $masjid);

            $pageSaysClosed = $this->page($slug, $masjid)['registration_state'] === 'closed';
            $quoteRefused = $this->quote($slug, $plan->id, $masjid)->getStatusCode() !== 200;

            $this->assertSame(
                ! $quotable,
                $pageSaysClosed,
                "{$slug}: the page's verdict is not the one this test expects"
            );
            $this->assertSame(
                $pageSaysClosed,
                $quoteRefused,
                "{$slug}: the page and the quote gave different answers about the same offering"
            );
        }
    }

    #[Test]
    public function an_organisation_that_cannot_collect_never_gets_as_far_as_a_held_seat(): void
    {
        // The measured reproduction in full, all four surfaces. Before the fix,
        // on an organisation with `stripe_account_id = null` and one active
        // one_time plan at 15000:
        //
        //   PAGE     closed / org_cannot_collect — and fee_plans STILL published
        //            amount_minor 15000, a price for a plan the verdict beside
        //            it called unbuyable
        //   QUOTE    200  amount_due_minor 15000
        //   REGISTER 200  "Your place is held while you complete payment."
        //            checkout_url null  -> registrations 1, seat 1, contacts 1,
        //            form_responses 1
        //   CHECKOUT 422  "This organization is not able to accept online
        //            payments yet."
        //
        // A parent quoted $150, asked for her child's details, told her place was
        // held, and given no payment link and no error — while her seat shrank
        // capacity for other families until the reaper silently cancelled it.
        $offline = $this->makeMasjid(['stripe_account_id' => null, 'stripe_charges_enabled' => false]);

        [$offering, $plan] = $this->makeOffering(['slug' => 'camp'], $offline);

        $page = $this->page('camp', $offline);
        $this->assertSame('closed', $page['registration_state']);
        $this->assertSame('org_cannot_collect', $page['registration_state_reason']);

        // No price is published for a plan the same payload declares unbuyable.
        $this->assertSame([], $page['fee_plans']);

        $headers = ['masjid-id' => (string) $offline->id];

        $this->quote('camp', $plan->id, $offline)
            ->assertStatus(404)
            ->assertJsonPath('message', 'This fee plan is not available.');

        $this->postJson('/api/v1/offerings/camp/register', [
            'fee_plan_id' => $plan->id,
            'payer' => ['name' => 'Aisha Karim', 'email' => 'aisha@test.local'],
            'data' => ['full_name' => 'Aisha Karim'],
        ], $headers)
            ->assertStatus(404)
            ->assertJsonPath('message', 'This fee plan is not available.');

        // Nothing at all was written on the way to that refusal — no phantom
        // pending seat for the reaper to clean up later.
        $this->assertDatabaseCount('registrations', 0);
        $this->assertDatabaseCount('form_responses', 0);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertSame(0, (int) $offering->fresh()->registration_count);
    }

    #[Test]
    public function a_plan_whose_billing_shape_cannot_be_charged_is_the_same_404(): void
    {
        // The two sibling columns held to a lower standard than `kind`. All
        // three rows below are ones `StoreFeePlanRequest` refuses to create —
        // the same reachability as the `sliding_scale` kind above, which
        // `Rule::in(FeePlan::KINDS)` blocks on the same request — and all three
        // were measured as: page `open`, plan published at 2500, quote 200,
        // register 200 "your place is held", checkout 422, seat 1.
        $shapes = [
            'null-interval' => ['kind' => 'recurring', 'billing_interval' => null],
            'bad-interval' => ['kind' => 'recurring', 'billing_interval' => 'fortnight'],
            'zero-count' => ['kind' => 'installment', 'billing_interval' => 'month', 'installment_count' => 0],
        ];

        foreach ($shapes as $slug => $columns) {
            [$offering, $plan] = $this->makeOffering(['slug' => $slug]);
            FeePlan::withoutMasjidScope()->whereKey($plan->id)->update($columns);

            $page = $this->page($slug);

            $this->assertSame([], $page['fee_plans'], "{$slug}: an uncharg[e]able plan was published");
            $this->assertSame('closed', $page['registration_state'], "{$slug}: page");
            $this->assertSame('no_fee_plan', $page['registration_state_reason'], "{$slug}: reason");

            $this->quote($slug, $plan->id)->assertStatus(404);

            $this->postJson("/api/v1/offerings/{$slug}/register", [
                'fee_plan_id' => $plan->id,
                'payer' => ['name' => 'Amal Yusuf', 'email' => "amal-{$slug}@test.local"],
                'data' => ['full_name' => 'Amal Yusuf'],
            ], $this->headers())->assertStatus(404);

            // The whole point: no seat is taken for a plan that could never be
            // charged, so nothing has to be reaped or refunded afterwards.
            $this->assertSame(0, (int) $offering->fresh()->registration_count);
        }

        $this->assertDatabaseCount('registrations', 0);
    }

    #[Test]
    public function an_installment_plan_of_one_payment_is_still_purchasable(): void
    {
        // The over-refusal guard on the clause above. `StoreFeePlanRequest`
        // requires `min:2` at CREATE time, but `perChargeMinor()` only needs
        // `>= 1`, so a one-payment row that already exists divides perfectly
        // well. A predicate stricter than the write it gates would withdraw a
        // plan families are already registering on.
        [, $plan] = $this->makeOffering(['slug' => 'single-payment']);
        FeePlan::withoutMasjidScope()->whereKey($plan->id)->update([
            'kind' => 'installment',
            'billing_interval' => 'month',
            'installment_count' => 1,
        ]);

        $page = $this->page('single-payment');
        $this->assertSame('open', $page['registration_state']);
        $this->assertSame([$plan->id], array_column($page['fee_plans'], 'id'));

        $this->quote('single-payment', $plan->id)->assertStatus(200);
    }

    // ------------------- 2. an unpublished plan is indistinguishable from none

    #[Test]
    public function a_plan_of_an_unrecognised_kind_is_the_same_404_as_a_plan_that_does_not_exist(): void
    {
        // The measured reproduction: a row whose `kind` is not in
        // FeePlan::KINDS. The page withholds it because money never degrades;
        // the write path used to spend it.
        // Its ONLY plan, so the page has nothing left to publish — which is the
        // fixture the reproduction was measured on.
        $offering = Offering::factory()->forMasjid($this->masjid)
            ->create(['slug' => 'sliding-scale-program']);

        $rogue = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);
        FeePlan::withoutMasjidScope()->whereKey($rogue->id)->update(['kind' => 'sliding_scale']);

        $page = $this->page('sliding-scale-program');
        $this->assertSame([], $page['fee_plans']);
        $this->assertSame('closed', $page['registration_state']);
        $this->assertSame('no_fee_plan', $page['registration_state_reason']);

        $withheld = $this->quote('sliding-scale-program', $rogue->id)->assertStatus(404);
        $missing = $this->quote('sliding-scale-program', 987654)->assertStatus(404);

        // Byte-identical: a caller cannot tell a plan the page refused to
        // publish from one that never existed.
        $this->assertSame('This fee plan is not available.', $withheld->json('message'));
        $this->assertSame($missing->getContent(), $withheld->getContent());
    }

    #[Test]
    public function the_internal_money_invariant_never_reaches_an_anonymous_caller(): void
    {
        // The leak itself, named. "Unrecognized fee plan kind '…' — money kinds
        // never degrade." is a message for a developer reading a stack trace,
        // and it announced the existence and the kind of a row the page had just
        // withheld. It must be unreachable from BOTH public writes.
        [$offering] = $this->makeOffering(['slug' => 'no-leak']);

        $rogue = FeePlan::factory()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);
        FeePlan::withoutMasjidScope()->whereKey($rogue->id)->update(['kind' => 'barter']);

        foreach ([
            $this->quote('no-leak', $rogue->id),
            $this->postJson('/api/v1/offerings/no-leak/register', [
                'fee_plan_id' => $rogue->id,
                'payer' => ['name' => 'Bilal Ahmed', 'email' => 'bilal@test.local'],
                'data' => ['full_name' => 'Bilal Ahmed'],
            ], $this->headers()),
        ] as $response) {
            $body = $response->getContent();

            $this->assertStringNotContainsString('money kinds never degrade', $body);
            $this->assertStringNotContainsString('barter', $body);
            $this->assertSame(404, $response->getStatusCode());
        }

        // And nothing was written on the way to that refusal.
        $this->assertDatabaseCount('registrations', 0);
        $this->assertDatabaseCount('form_responses', 0);
    }

    #[Test]
    public function a_deactivated_plan_and_a_foreign_plan_are_the_same_404(): void
    {
        // The other two members of the class. All four — deactivated, foreign,
        // unrecognised kind, nonexistent — are one indistinguishable miss, which
        // is what "withholding and refusing are the same fact" means.
        [$offering] = $this->makeOffering(['slug' => 'one-miss']);

        $inactive = FeePlan::factory()->inactive()->create([
            'masjid_id' => $this->masjid->id,
            'offering_id' => $offering->id,
        ]);

        $otherMasjid = $this->makeMasjid();
        $otherOffering = Offering::factory()->forMasjid($otherMasjid)->create();
        $foreign = FeePlan::factory()->create([
            'masjid_id' => $otherMasjid->id,
            'offering_id' => $otherOffering->id,
        ]);

        $bodies = [];

        foreach ([$inactive->id, $foreign->id, 987654] as $id) {
            $response = $this->quote('one-miss', $id)->assertStatus(404);
            $bodies[] = $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies));
    }

    // ============================= helpers =============================

    private function headers(?Masjid $masjid = null): array
    {
        return ['masjid-id' => (string) ($masjid ?? $this->masjid)->id];
    }

    private function page(string $slug, ?Masjid $masjid = null): array
    {
        return $this->getJson("/api/v1/offerings/{$slug}", $this->headers($masjid))
            ->assertStatus(200)
            ->json('data');
    }

    private function quote(string $slug, int $feePlanId, ?Masjid $masjid = null)
    {
        return $this->postJson(
            "/api/v1/offerings/{$slug}/quote",
            ['fee_plan_id' => $feePlanId],
            $this->headers($masjid)
        );
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
            // Onboarded, so nothing here is refused by clause 5 of the decider
            // (organisation cannot collect) instead of by the clause under test.
            'stripe_account_id' => 'acct_TEST' . uniqid(),
            'stripe_charges_enabled' => true,
            // The public registration surface asks this too — see
            // PublicRegistrationCrmGateTest.
            'crm_enabled' => true,
        ], $overrides));
    }

    /** @return array{0: Offering, 1: FeePlan} */
    private function makeOffering(array $state = [], ?Masjid $masjid = null): array
    {
        $masjid ??= $this->masjid;

        $offering = Offering::factory()->forMasjid($masjid)->create($state);

        $plan = FeePlan::factory()->create([
            'masjid_id' => $masjid->id,
            'offering_id' => $offering->id,
        ]);

        return [$offering, $plan];
    }
}
