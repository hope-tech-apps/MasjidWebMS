<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationSubscription;
use App\Models\Fund;
use App\Models\Masjid;
use App\Services\Stripe\DonationService;
use App\Support\TenantContext;
use App\Support\ZakatDesignation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Donor self-service on a standing commitment — the server half.
 *
 * These four verbs are the first MONEY writes in the member realm, and three of
 * them (pause, resume, change amount) did not exist anywhere in the codebase
 * before, for donors or for staff. So this file is not a smoke test of five
 * routes; each case below is one promise about somebody else's money, written so
 * that the way it could break is the thing that fails.
 *
 * Stripe is stubbed the way .claude/rules/stripe-payments.md requires and the way
 * DonationFlowTest and DonationSubscriptionTenantIsolationTest already do it: the
 * ONLY things mocked are the outbound protected seams. The tenant scope, the
 * ownership clause, the gross-up, the status transitions and the response shape
 * all run for real, and no test here can reach the live API.
 *
 * The stub also REMEMBERS what Stripe was told, because most of these guarantees
 * are statements about the outbound call rather than about the response body: a
 * pause that answered 200 having called `cancel` would look identical from the
 * outside and would have destroyed the donor's commitment.
 */
class MemberRecurringGivingTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    private Masjid $masjidA;
    private Masjid $masjidB;

    private Fund $fundA;
    private Fund $fundB;

    /** The donor under test, a second member of the same masjid, and a member elsewhere. */
    private Contact $donor;
    private Contact $neighbour;
    private Contact $foreigner;

    private DonationSubscription $mine;
    private DonationSubscription $neighbours;

    /** Every outbound Stripe call the code attempted, in order. */
    private array $stripeCalls = [];

    /** What the fake Stripe currently believes about the subscription. */
    private bool $pausedAtStripe = false;
    private int $unitAmountAtStripe = 5000;
    private string $statusAtStripe = 'active';

    /** When set, the next amount-change seam call throws instead of succeeding. */
    private ?\Throwable $amountChangeThrows = null;

    /** When set, the next cancel seam call throws instead of succeeding. */
    private ?\Throwable $cancelThrows = null;

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

        // Pin the fee constants: the gross-up assertions below quote real
        // numbers, and a deployment-level override must not silently retune them.
        config(['services.stripe.currency' => 'usd']);
        config(['services.stripe.fee_percentage' => 0.029]);
        config(['services.stripe.fee_fixed' => 30]);

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->masjidA = $this->makeMasjid('acct_A');
        $this->masjidB = $this->makeMasjid('acct_B');

        $this->fundA = $this->makeFund($this->masjidA, 'Zakat');
        $this->fundB = $this->makeFund($this->masjidB, 'General');

        $this->donor = $this->makeMember($this->masjidA);
        $this->neighbour = $this->makeMember($this->masjidA);
        $this->foreigner = $this->makeMember($this->masjidB);

        $this->mine = $this->makeCommitment($this->masjidA, $this->fundA, $this->donor, 'sub_mine');
        $this->neighbours = $this->makeCommitment($this->masjidA, $this->fundA, $this->neighbour, 'sub_theirs');

        $this->stubStripeSeams();
    }

    // ---------------------------------------------------------------- fixtures

    private function makeMasjid(?string $stripeAccount): Masjid
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
            'crm_enabled' => true,
            'stripe_account_id' => $stripeAccount,
            'stripe_charges_enabled' => $stripeAccount !== null,
        ]);
    }

    private function makeFund(Masjid $masjid, string $name): Fund
    {
        return Fund::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'name' => $name,
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
        ]);
    }

    /** A contact who has proved control of their address — `member.active` passes. */
    private function makeMember(Masjid $masjid, bool $verified = true): Contact
    {
        $contact = Contact::factory()->create(['masjid_id' => $masjid->id]);

        $contact->forceFill([
            'login_email' => 'donor-' . uniqid() . '@test.local',
            'verified_at' => $verified ? now() : null,
        ])->save();

        return $contact->refresh();
    }

    private function makeCommitment(
        Masjid $masjid,
        Fund $fund,
        ?Contact $contact,
        ?string $stripeId,
        array $overrides = []
    ): DonationSubscription {
        return DonationSubscription::withoutMasjidScope()->create(array_merge([
            'masjid_id' => $masjid->id,
            'contact_id' => $contact?->id,
            'fund_id' => $fund->id,
            'intended_amount' => 5000,
            'charged_amount' => 5000,
            'currency' => 'usd',
            'donor_covers_fees' => false,
            'is_zakat' => false,
            'zakat_source' => null,
            'interval' => 'month',
            'status' => 'active',
            'stripe_subscription_id' => $stripeId,
            'stripe_customer_id' => 'cus_' . uniqid(),
            'idempotency_key' => 'sub_' . uniqid(),
        ], $overrides));
    }

    /**
     * Bind a partial DonationService whose only mocked methods are the outbound
     * seams. Everything else — ownership, gross-up, persistence — runs for real.
     */
    private function stubStripeSeams(): void
    {
        $service = Mockery::mock(DonationService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('retrieveStripeSubscription')
            ->andReturnUsing(function (string $id, string $account) {
                $this->stripeCalls[] = ['retrieve', $id, $account];

                return $this->stripeSays();
            });

        // The seams take the request array they will send, so a test can read the
        // PAYLOAD rather than the fake's own bookkeeping. A stub that flipped its
        // own `$pausedAtStripe` flag and returned it would answer `paused: false`
        // for a resume that sent Stripe nothing at all.
        $service->shouldReceive('pauseStripeSubscription')
            ->andReturnUsing(function (string $id, array $params, string $account) {
                $this->stripeCalls[] = ['pause', $id, $params, $account];
                $this->pausedAtStripe = ($params['pause_collection'] ?? null) !== null;

                return $this->stripeSays();
            });

        $service->shouldReceive('resumeStripeSubscription')
            ->andReturnUsing(function (string $id, array $params, string $account) {
                $this->stripeCalls[] = ['resume', $id, $params, $account];

                // The fake behaves the way Stripe does, which is the only way a
                // test can catch the trap the resume docblock names: an UPDATE
                // that omits `pause_collection` leaves the pause in place. Only
                // the key being present and null lifts it here, exactly as at
                // Stripe.
                if (array_key_exists('pause_collection', $params)) {
                    $this->pausedAtStripe = $params['pause_collection'] !== null;
                }

                return $this->stripeSays();
            });

        $service->shouldReceive('updateStripeSubscriptionAmount')
            ->andReturnUsing(function (
                string $id,
                string $itemId,
                string $productId,
                int $unitAmount,
                string $currency,
                string $interval,
                string $account
            ) {
                $this->stripeCalls[] = ['amount', $id, $unitAmount, $currency, $interval, $account];

                if ($this->amountChangeThrows) {
                    throw $this->amountChangeThrows;
                }

                $this->unitAmountAtStripe = $unitAmount;

                return $this->stripeSays();
            });

        $service->shouldReceive('cancelStripeSubscription')
            ->andReturnUsing(function (string $id, string $account) {
                $this->stripeCalls[] = ['cancel', $id, $account];

                if ($this->cancelThrows) {
                    throw $this->cancelThrows;
                }

                $this->statusAtStripe = 'canceled';
                $this->pausedAtStripe = false;
            });

        // The checkout seam RECORDS rather than throws. No donor verb may ever
        // open a Checkout Session — that is how a "resume" becomes a second live
        // subscription against one commitment row — but a `$this->fail()` here
        // could never say so: every verb runs inside the controller's
        // `catch (\Throwable)`, which would swallow the AssertionFailedError and
        // answer 503, so the operator would be told "expected 200, got 503" and
        // left to guess. Recorded, the violation survives the catch and is named
        // by assertNoCheckoutSessionWasOpened() — which tearDown asks on EVERY
        // test, so a verb added later cannot slip past it silently.
        $service->shouldReceive('createCheckoutSession')
            ->andReturnUsing(function () {
                $this->stripeCalls[] = ['checkout'];

                return ['id' => 'cs_tripwire', 'url' => 'https://stripe.test/tripwire'];
            });

        $this->app->instance(DonationService::class, $service);
    }

    protected function tearDown(): void
    {
        $this->assertNoCheckoutSessionWasOpened();

        parent::tearDown();
    }

    private function stripeSays(): array
    {
        return [
            'status' => $this->statusAtStripe,
            'paused' => $this->pausedAtStripe,
            'unit_amount' => $this->unitAmountAtStripe,
            'item_id' => 'si_test',
            'product_id' => 'prod_test',
        ];
    }

    // ------------------------------------------------------------------ callers

    private function asMember(Contact $contact): self
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $contact->createMemberToken()->plainTextToken
        );
    }

    private function url(Masjid $masjid, string $path = ''): string
    {
        return "/api/mobile/masjids/{$masjid->id}/me/recurring-giving" . $path;
    }

    /** Only the verbs that actually reached Stripe, by name. */
    private function outboundVerbs(): array
    {
        return array_column($this->stripeCalls, 0);
    }

    /** Every recorded call of one verb, in order. */
    private function callsTo(string $verb): array
    {
        return array_values(array_filter($this->stripeCalls, fn ($c) => $c[0] === $verb));
    }

    /**
     * The request array a pause/resume actually sent Stripe.
     *
     * This is the thing the two verbs ARE: `pause_collection` with a behavior,
     * and `pause_collection` explicitly null. Everything else about them —
     * status codes, the `paused` field — is downstream of this array, and a test
     * that reads only the response is reading the fake's memory of what it was
     * told, not what the code sent.
     */
    private function paramsSentBy(string $verb): array
    {
        $calls = $this->callsTo($verb);

        $this->assertNotEmpty($calls, "No `{$verb}` call reached Stripe.");

        return $calls[0][2];
    }

    /**
     * No donor verb opened a Checkout Session.
     *
     * Asked in tearDown for every test in this file rather than case by case: a
     * resume, a retry or a future verb implemented as "just open a new checkout"
     * would leave the donor holding two live subscriptions against one
     * commitment row — the second invisible to `bookRecurringInvoice`'s lookup by
     * `stripe_subscription_id`, the first paused forever.
     */
    private function assertNoCheckoutSessionWasOpened(): void
    {
        $this->assertNotContains(
            'checkout',
            $this->outboundVerbs(),
            'A donor self-service verb opened a Stripe Checkout Session.'
        );
    }

    // ================================================================ ownership

    #[Test]
    public function a_member_sees_only_their_own_recurring_gifts(): void
    {
        $this->makeCommitment($this->masjidB, $this->fundB, $this->foreigner, 'sub_foreign');

        $response = $this->asMember($this->donor)
            ->getJson($this->url($this->masjidA))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $uuids = array_column($response->json('data'), 'uuid');

        $this->assertSame([$this->mine->uuid], $uuids);
    }

    #[Test]
    public function a_member_cannot_address_another_members_commitment_by_uuid(): void
    {
        // A 404 and NOT a 403. A 403 would confirm that this uuid names a real
        // commitment belonging to somebody else, which is a disclosure about a
        // named person's giving — see MemberRecurringGivingController.
        $theirs = $this->neighbours->uuid;

        $this->asMember($this->donor)->postJson($this->url($this->masjidA, "/{$theirs}/pause"))->assertNotFound();
        $this->asMember($this->donor)->postJson($this->url($this->masjidA, "/{$theirs}/resume"))->assertNotFound();
        $this->asMember($this->donor)->postJson($this->url($this->masjidA, "/{$theirs}/cancel"))->assertNotFound();
        $this->asMember($this->donor)
            ->patchJson($this->url($this->masjidA, "/{$theirs}"), ['amount' => 100])
            ->assertNotFound();

        // Refused BEFORE anything left the building, and the neighbour's gift is
        // exactly as it was. A 404 that had already called Stripe would have
        // stopped a stranger's donation and merely lied about it afterwards.
        $this->assertSame([], $this->stripeCalls);

        $this->neighbours->refresh();
        $this->assertSame('active', $this->neighbours->status);
        $this->assertNull($this->neighbours->canceled_at);
        $this->assertSame(5000, (int) $this->neighbours->charged_amount);
    }

    #[Test]
    public function a_member_cannot_reach_a_commitment_by_pointing_the_url_at_another_masjid(): void
    {
        // `family.tenant` binds from the TOKEN and refuses a path naming anyone
        // else, so this dies in middleware — the controller is never entered and
        // the tenant is never bound to the masjid the caller named.
        $this->asMember($this->donor)
            ->getJson($this->url($this->masjidB))
            ->assertStatus(403);

        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidB, "/{$this->mine->uuid}/cancel"))
            ->assertStatus(403);

        $this->assertSame([], $this->stripeCalls);
        $this->assertSame('active', $this->mine->refresh()->status);
    }

    #[Test]
    public function an_unverified_contact_cannot_reach_the_recurring_giving_realm(): void
    {
        $unverified = $this->makeMember($this->masjidA, verified: false);
        $theirs = $this->makeCommitment($this->masjidA, $this->fundA, $unverified, 'sub_unverified');

        // `member.active` gates on verified_at. Someone who has not proved
        // control of the address must not be able to stop, reprice or even
        // enumerate the gifts attached to it.
        $this->asMember($unverified)->getJson($this->url($this->masjidA))->assertStatus(401);
        $this->asMember($unverified)
            ->postJson($this->url($this->masjidA, "/{$theirs->uuid}/cancel"))
            ->assertStatus(401);

        $this->assertSame([], $this->stripeCalls);
        $this->assertSame('active', $theirs->refresh()->status);
    }

    // ==================================================================== pause

    #[Test]
    public function pausing_stops_the_charges_at_stripe_and_never_marks_the_commitment_canceled(): void
    {
        $before = $this->mine->only(['status', 'canceled_at', 'intended_amount', 'charged_amount']);

        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$this->mine->uuid}/pause"))
            ->assertOk()
            ->assertJsonPath('data.paused', true)
            ->assertJsonPath('data.status', 'active');

        // The pause went to Stripe's own collection switch — the cancel seam was
        // not touched. This is the assertion that separates a real pause from the
        // catastrophic near-miss, which answers 200 and destroys the commitment.
        $this->assertContains('pause', $this->outboundVerbs());
        $this->assertNotContains('cancel', $this->outboundVerbs());

        // And `void` is what Stripe was TOLD, not merely what the constant says.
        // A donor who pauses must not come back to a stack of collectable
        // back-charges, so the behavior has to arrive at Stripe: comparing the
        // constant to its own literal would stay green while
        // `pauseStripeSubscription` hardcoded 'keep_as_draft'.
        $this->assertSame(
            ['pause_collection' => ['behavior' => 'void']],
            $this->paramsSentBy('pause')
        );
        $this->assertSame('void', DonationService::PAUSE_BEHAVIOR);

        // Nothing was written locally. `donation_subscriptions.status` is a MySQL
        // ENUM of pending/active/past_due/canceled with no 'paused' member, so a
        // local mirror would be an error on production and a green test here.
        $this->mine->refresh();
        $this->assertSame($before, $this->mine->only(array_keys($before)));
    }

    #[Test]
    public function a_paused_commitment_reports_itself_as_paused_on_the_donors_list(): void
    {
        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$this->mine->uuid}/pause"))
            ->assertOk();

        // Read back from Stripe, because there is nowhere local to remember it.
        // A screen that showed a paused gift as running would invite the donor to
        // pause it a second time and then wonder why nothing was charged.
        $this->asMember($this->donor)
            ->getJson($this->url($this->masjidA))
            ->assertOk()
            ->assertJsonPath('data.0.paused', true);
    }

    #[Test]
    public function resuming_a_paused_commitment_does_not_create_a_second_subscription(): void
    {
        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$this->mine->uuid}/pause"))
            ->assertOk();

        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$this->mine->uuid}/resume"))
            ->assertOk()
            ->assertJsonPath('data.paused', false);

        // The SAME Stripe subscription, still the only one. A resume implemented
        // as "open a new checkout" would leave the donor holding two live
        // subscriptions — the new one invisible to bookRecurringInvoice's lookup
        // by stripe_subscription_id, the old one paused forever. The
        // createCheckoutSession stub fails the test outright if that ever
        // happens; this pins the row-level consequence too.
        $this->assertSame('sub_mine', $this->mine->refresh()->stripe_subscription_id);
        $this->assertSame(
            1,
            DonationSubscription::withoutMasjidScope()->where('contact_id', $this->donor->id)->count()
        );
        $this->assertSame(['pause', 'resume'], array_values(array_diff($this->outboundVerbs(), ['retrieve'])));

        // The resume UNSET `pause_collection`, which is the whole operation. The
        // trap the seam's docblock names is omitting the key "helpfully": Stripe
        // leaves the pause in place and answers 200, so the donor is told their
        // giving resumed while nothing will ever be charged again. Asserting the
        // key is PRESENT and null is the only thing that can catch that — the
        // response body cannot, because it is built from whatever the call
        // returned.
        $params = $this->paramsSentBy('resume');
        $this->assertArrayHasKey('pause_collection', $params);
        $this->assertNull($params['pause_collection']);
    }

    // =========================================================== amount changes

    #[Test]
    public function changing_the_amount_keeps_the_zakat_designation_the_giver_made_at_checkout(): void
    {
        $zakat = $this->makeCommitment($this->masjidA, $this->fundA, $this->donor, 'sub_zakat', [
            'is_zakat' => true,
            'zakat_source' => ZakatDesignation::SOURCE_DONOR,
        ]);

        $this->asMember($this->donor)
            ->patchJson($this->url($this->masjidA, "/{$zakat->uuid}"), ['amount' => 7500])
            ->assertOk()
            ->assertJsonPath('data.intended_amount', 7500)
            ->assertJsonPath('data.is_zakat', true);

        $zakat->refresh();

        // .claude/rules/zakat.md: a recurring commitment is designated ONCE, at
        // checkout. Re-resolving it on an edit would let a later change to the
        // fund's type silently re-label money the giver already designated.
        $this->assertTrue((bool) $zakat->is_zakat);
        $this->assertSame(ZakatDesignation::SOURCE_DONOR, $zakat->zakat_source);

        // And the things a donor may not change from here at all.
        $this->assertSame($this->fundA->id, $zakat->fund_id);
        $this->assertSame('month', $zakat->interval);
    }

    #[Test]
    public function changing_the_amount_regrosses_the_charge_when_the_donor_covers_fees(): void
    {
        $covered = $this->makeCommitment($this->masjidA, $this->fundA, $this->donor, 'sub_covered', [
            'donor_covers_fees' => true,
            'intended_amount' => 5000,
            'charged_amount' => DonationService::grossUp(5000),
        ]);

        $this->asMember($this->donor)
            ->patchJson($this->url($this->masjidA, "/{$covered->uuid}"), ['amount' => 10000])
            ->assertOk();

        // $100.00 intended @ 2.9% + 30¢ ⇒ round(10030 / 0.971) = 10330¢, so the
        // org still NETS the hundred dollars the donor means to give.
        $expected = DonationService::grossUp(10000);
        $this->assertSame(10330, $expected);

        $covered->refresh();
        $this->assertSame(10000, (int) $covered->intended_amount);
        $this->assertSame($expected, (int) $covered->charged_amount);

        // The GROSSED figure is what Stripe was told to charge. Sending the
        // intended amount instead would quietly move the fee onto the mosque; the
        // request body carries the intended figure, so this is the one place the
        // two can be confused.
        $amountCalls = array_values(array_filter($this->stripeCalls, fn ($c) => $c[0] === 'amount'));
        $this->assertCount(1, $amountCalls);
        $this->assertSame($expected, $amountCalls[0][2]);
        $this->assertSame('usd', $amountCalls[0][3]);
        $this->assertSame('month', $amountCalls[0][4]);
        $this->assertSame('acct_A', $amountCalls[0][5]);
    }

    #[Test]
    public function an_amount_outside_the_sane_range_is_refused_and_never_reaches_stripe(): void
    {
        foreach ([0, 99, DonationService::MAX_RECURRING_AMOUNT + 1] as $amount) {
            $this->asMember($this->donor)
                ->patchJson($this->url($this->masjidA, "/{$this->mine->uuid}"), ['amount' => $amount])
                ->assertStatus(422);
        }

        // A float is not a near-miss, it is a different unit: "50.00" reaching an
        // integer-minor-units column is fifty cents.
        $this->asMember($this->donor)
            ->patchJson($this->url($this->masjidA, "/{$this->mine->uuid}"), ['amount' => 50.5])
            ->assertStatus(422);

        // A missing amount is a 422, never "change it to nothing".
        $this->asMember($this->donor)
            ->patchJson($this->url($this->masjidA, "/{$this->mine->uuid}"), [])
            ->assertStatus(422);

        $this->assertSame([], $this->stripeCalls);
        $this->assertSame(5000, (int) $this->mine->refresh()->charged_amount);
    }

    #[Test]
    public function the_local_amount_is_never_rewritten_when_stripe_refuses_the_change(): void
    {
        $this->amountChangeThrows = new \RuntimeException('card_declined');

        $this->asMember($this->donor)
            ->patchJson($this->url($this->masjidA, "/{$this->mine->uuid}"), ['amount' => 12345])
            ->assertStatus(503);

        // The row must never claim a figure Stripe did not accept: a local $123.45
        // beside a live $50.00 subscription is a number the card statement will
        // contradict, and the donor has no way to tell which one is real. Unlike
        // cancel, this verb has NO "do it locally anyway" tolerance.
        $this->mine->refresh();
        $this->assertSame(5000, (int) $this->mine->intended_amount);
        $this->assertSame(5000, (int) $this->mine->charged_amount);
    }

    // =================================================================== refusals

    #[Test]
    public function a_cancelled_commitment_cannot_be_paused_resumed_or_repriced(): void
    {
        $dead = $this->makeCommitment($this->masjidA, $this->fundA, $this->donor, 'sub_dead', [
            'status' => 'canceled',
            'canceled_at' => now(),
        ]);

        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$dead->uuid}/pause"))->assertStatus(422);
        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$dead->uuid}/resume"))->assertStatus(422);
        $this->asMember($this->donor)
            ->patchJson($this->url($this->masjidA, "/{$dead->uuid}"), ['amount' => 9000])->assertStatus(422);

        // Cancel stays idempotent, though: a donor tapping twice on a slow
        // connection must never be shown an error about their own money.
        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$dead->uuid}/cancel"))->assertOk();

        $this->assertSame([], $this->stripeCalls);
        $this->assertSame(5000, (int) $dead->refresh()->charged_amount);
    }

    #[Test]
    public function a_commitment_whose_checkout_never_completed_is_refused_rather_than_half_acted_on(): void
    {
        $unstarted = $this->makeCommitment($this->masjidA, $this->fundA, $this->donor, null, [
            'status' => 'pending',
        ]);

        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$unstarted->uuid}/pause"))->assertStatus(422);
        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$unstarted->uuid}/resume"))->assertStatus(422);
        $this->asMember($this->donor)
            ->patchJson($this->url($this->masjidA, "/{$unstarted->uuid}"), ['amount' => 9000])->assertStatus(422);

        // CANCEL IS REFUSED TOO, and it is the one that matters most.
        //
        // There is no billing clock to stop yet, so a "cancel" here could only
        // ever be a local flag — and the row it would set is the row
        // `checkout.session.completed` is about to attach a LIVE Stripe
        // subscription to, seconds later (Stripe retries a failed delivery for
        // up to three days). `linkSubscriptionCheckout` pins the id regardless
        // of status and `activateSubscription` will not move a 'canceled' row
        // back to active, so the gift bills every month, `bookRecurringInvoice`
        // books and receipts every charge, the donor's screen says "Cancelled",
        // and every verb on this surface then refuses the row for being
        // cancelled — the donor could never stop it again from anywhere.
        //
        // So the donor is told to try again in a moment, which is a true
        // sentence about a checkout that has not finished landing.
        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$unstarted->uuid}/cancel"))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertSame([], $this->stripeCalls);

        $unstarted->refresh();
        $this->assertSame('pending', $unstarted->status);
        $this->assertNull($unstarted->canceled_at);
    }

    #[Test]
    public function an_organisation_with_no_connected_stripe_account_still_serves_the_list(): void
    {
        // .claude/rules: an integration with no credentials must NO-OP, not throw.
        // The donor's own history is local data and must stay readable; the pause
        // state simply cannot be known, and `null` says exactly that rather than
        // guessing `false`.
        $unconnected = $this->makeMasjid(null);
        $fund = $this->makeFund($unconnected, 'General');
        $member = $this->makeMember($unconnected);
        $this->makeCommitment($unconnected, $fund, $member, 'sub_no_account');

        $this->asMember($member)
            ->getJson($this->url($unconnected))
            ->assertOk()
            ->assertJsonPath('data.0.paused', null);

        $this->assertSame([], $this->stripeCalls);
    }

    // ============================================================== side effects

    #[Test]
    public function the_donor_verbs_never_touch_a_donation_row(): void
    {
        // A gift already made is settled money. It is booked by a verified
        // webhook, it backs a receipt with a gap-free serial, and no donor-facing
        // button may edit, re-price or delete it — the only thing these verbs may
        // change is what happens NEXT.
        $gift = Donation::withoutMasjidScope()->create([
            'masjid_id' => $this->masjidA->id,
            'contact_id' => $this->donor->id,
            'fund_id' => $this->fundA->id,
            'type' => 'recurring',
            'intended_amount' => 5000,
            'charged_amount' => 5000,
            'currency' => 'usd',
            'status' => 'succeeded',
            'stripe_subscription_id' => 'sub_mine',
            'idempotency_key' => 'invoice_' . uniqid(),
        ]);

        $snapshot = $gift->fresh()->getAttributes();

        $uuid = $this->mine->uuid;

        // The mutating verbs answer with the SAME shape the list does, gift
        // count included. A verb that re-read the row with `refresh()` would
        // drop the aggregate and tell a donor of three years they had given
        // nothing, on the very screen they opened to check.
        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$uuid}/pause"))
            ->assertOk()
            ->assertJsonPath('data.gifts_count', 1);
        $this->asMember($this->donor)->postJson($this->url($this->masjidA, "/{$uuid}/resume"))->assertOk();
        $this->asMember($this->donor)->patchJson($this->url($this->masjidA, "/{$uuid}"), ['amount' => 6000])->assertOk();
        $this->asMember($this->donor)->postJson($this->url($this->masjidA, "/{$uuid}/cancel"))->assertOk();

        $this->assertSame(1, Donation::withoutMasjidScope()->count());
        $this->assertSame($snapshot, $gift->fresh()->getAttributes());
    }

    #[Test]
    public function the_list_reports_how_many_gifts_a_commitment_has_actually_booked(): void
    {
        foreach (['inv_1', 'inv_2'] as $invoice) {
            Donation::withoutMasjidScope()->create([
                'masjid_id' => $this->masjidA->id,
                'contact_id' => $this->donor->id,
                'fund_id' => $this->fundA->id,
                'type' => 'recurring',
                'intended_amount' => 5000,
                'charged_amount' => 5000,
                'currency' => 'usd',
                'status' => 'succeeded',
                'stripe_subscription_id' => 'sub_mine',
                'idempotency_key' => $invoice,
            ]);
        }

        // "since March 2026 · 7 gifts" is the copy this feeds, and it is the one
        // number on the screen a donor can check against their bank.
        $this->asMember($this->donor)
            ->getJson($this->url($this->masjidA))
            ->assertOk()
            ->assertJsonPath('data.0.gifts_count', 2)
            ->assertJsonPath('data.0.fund.name', 'Zakat');
    }

    #[Test]
    public function cancelling_stops_the_gift_at_stripe_and_records_it_locally(): void
    {
        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$this->mine->uuid}/cancel"))
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        $this->assertContains('cancel', $this->outboundVerbs());

        $this->mine->refresh();
        $this->assertSame('canceled', $this->mine->status);
        $this->assertNotNull($this->mine->canceled_at);
    }

    #[Test]
    public function a_cancel_stripe_refused_is_answered_as_a_refusal_and_the_gift_stays_live(): void
    {
        // A Stripe 500, a connect timeout, a deauthorised connected account, an
        // expired platform key. The old behaviour swallowed all four, wrote
        // 'canceled' and answered 200 — so the donor read "Cancelled", Stripe
        // kept the subscription, and the card was charged on the 3rd of every
        // month after that. Nothing reconciled it either: the
        // `customer.subscription.deleted` that repairs a row is never emitted
        // for a cancel that never happened.
        $this->cancelThrows = new \RuntimeException('stripe unreachable');

        $response = $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$this->mine->uuid}/cancel"))
            ->assertStatus(503)
            ->assertJsonPath('status', 'failed');

        // And the sentence says the thing the donor has to know. "Nothing has
        // changed" is true but useless here: what they need told, in the one
        // line they will read, is that the gift is still running.
        $this->assertStringContainsString('NOT been cancelled', $response->json('data'));

        // The row may not say 'canceled' while Stripe's billing clock runs.
        $this->mine->refresh();
        $this->assertSame('active', $this->mine->status);
        $this->assertNull($this->mine->canceled_at);

        // The donor's screen agrees with the row, so the gift is still shown as
        // theirs to cancel — the refusal left them a way back, which the
        // 200-on-failure never did (every verb refuses a cancelled row).
        $this->asMember($this->donor)
            ->getJson($this->url($this->masjidA))
            ->assertOk()
            ->assertJsonPath('data.0.status', 'active');
    }

    #[Test]
    public function a_cancel_stripe_had_already_processed_completes_on_the_retry(): void
    {
        // The donor's first tap reached Stripe and the answer did not reach us
        // (a timeout after Stripe processed it). Stripe refuses to cancel a
        // subscription it has already cancelled, so a strict "any error is a
        // refusal" would strand this donor forever: our row says active, Stripe
        // says canceled, and every retry raises the same error.
        //
        // A refusal is not an answer, so Stripe is ASKED — the same idiom as
        // MealOrderCheckoutService::closeAndSee. Stripe saying 'canceled' IS
        // agreement, and the local write is then honest.
        $this->statusAtStripe = 'canceled';
        $this->cancelThrows = \Stripe\Exception\InvalidRequestException::factory(
            'A subscription with status canceled cannot be canceled.'
        );

        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$this->mine->uuid}/cancel"))
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled')
            ->assertJsonPath('data.paused', false);

        $this->mine->refresh();
        $this->assertSame('canceled', $this->mine->status);
        $this->assertNotNull($this->mine->canceled_at);

        // It asked rather than assumed.
        $this->assertContains('retrieve', $this->outboundVerbs());
    }

    #[Test]
    public function a_cancel_refused_by_a_stripe_that_still_holds_a_live_subscription_writes_nothing(): void
    {
        // The same refusal shape as the retry above, but Stripe's answer to
        // "what do you hold now?" is a LIVE subscription. Agreement is the only
        // thing that unlocks the local write, so this one is a refusal — a
        // subscription Stripe cannot find on the account we asked (the wrong
        // connected account, say) must never be read as "not billing".
        $this->cancelThrows = \Stripe\Exception\InvalidRequestException::factory('No such subscription.');

        $this->asMember($this->donor)
            ->postJson($this->url($this->masjidA, "/{$this->mine->uuid}/cancel"))
            ->assertStatus(503);

        $this->mine->refresh();
        $this->assertSame('active', $this->mine->status);
        $this->assertNull($this->mine->canceled_at);
    }

    #[Test]
    public function an_amount_change_stripe_accepted_is_never_reported_as_nothing_changed(): void
    {
        // Stripe is called first and it is the call that moves money: once the
        // item is repriced the card WILL be charged 250.00 from the next
        // invoice. If our own write then fails — a deadlock on
        // donation_subscriptions, a dropped connection, a read-only replica
        // after a failover — the truth is "Stripe changed, we did not", and the
        // generic 503 ("nothing has changed") is the exact opposite of what the
        // donor's statement will show. A donor told nothing happened gives up,
        // or changes it again on top of a change that already took.
        DonationSubscription::saving(function () {
            throw new \RuntimeException('SQLSTATE[40001]: deadlock detected');
        });

        $response = $this->asMember($this->donor)
            ->patchJson($this->url($this->masjidA, "/{$this->mine->uuid}"), ['amount' => 25000])
            ->assertStatus(500)
            ->assertJsonPath('status', 'failed');

        $this->assertStringNotContainsString('nothing has changed', $response->json('data'));
        $this->assertStringContainsString('accepted by the payment processor', $response->json('data'));

        // Stripe really was told, and the local row really is stale — which is
        // why the sentence above has to name the amount as accepted and send the
        // donor to the office instead of inviting a retry. `bookRecurringInvoice`
        // copies `intended_amount` onto every donation and receipt it writes, so
        // this row is a number that would otherwise reach a tax document.
        $this->assertSame(25000, $this->callsTo('amount')[0][2]);
        $this->assertSame(5000, (int) $this->mine->refresh()->intended_amount);
    }

    #[Test]
    public function the_giving_screen_is_rate_limited_because_every_read_fans_out_to_stripe(): void
    {
        // GET is not the cheap verb it looks like: it asks Stripe for the pause
        // state of every live commitment the caller holds, because a pause has
        // nowhere local to live. Left on the realm's generic per-IP budget it
        // would be both the most expensive verb at Stripe and the only one a
        // whole mosque's wifi shares — read traffic on the org's connected
        // account competing with that org's own live checkouts.
        $url = $this->url($this->masjidA);

        for ($i = 0; $i < 30; $i++) {
            $this->asMember($this->donor)->getJson($url)->assertOk();
        }

        $this->asMember($this->donor)->getJson($url)->assertStatus(429);
    }
}
