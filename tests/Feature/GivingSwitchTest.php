<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationSubscription;
use App\Models\Fund;
use App\Models\Masjid;
use App\Services\Stripe\DonationService;
use App\Support\GivingSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsDonationSubscriptions;
use Tests\TestCase;

/**
 * App\Support\GivingSwitch: what the Giving switch counts before a flip, and how
 * it notes money that arrives after one.
 *
 * The HTTP halves live elsewhere: the flip refused over a live monthly gift (the
 * capabilities endpoint), and the webhook booking, receipting, emailing and then
 * noting a gift for a switched-off organisation (ModuleSideDoorsTest). This suite
 * pins the two rules those rest on, directly.
 *
 * Every subscription is seeded through SeedsDonationSubscriptions, so no case
 * here can lean on a status the production ENUM refuses.
 */
class GivingSwitchTest extends TestCase
{
    use RefreshDatabase, SeedsDonationSubscriptions;

    private Masjid $masjid;

    private Fund $fund;

    /** @var list<array{0:string, 1:string}> each subscription id the Stripe stand-in was asked about, and on which account */
    private array $stripeAsked = [];

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

        $this->masjid = $this->makeMasjid();
        $this->fund = $this->makeFund($this->masjid);
    }

    // ------------------------------------------------------ live subscriptions

    #[Test]
    public function every_monthly_gift_that_can_still_charge_a_donor_is_counted(): void
    {
        $this->seedDonationSubscription($this->masjid, $this->fund, ['status' => 'active']);
        // A gift its donor paused. The pause lives at Stripe and the row stays
        // `active`; resuming it bills again, so it counts.
        $this->seedDonationSubscription($this->masjid, $this->fund, ['status' => 'active']);
        // Stripe is still retrying the card.
        $this->seedDonationSubscription($this->masjid, $this->fund, ['status' => 'past_due']);
        // Linked by Stripe, first invoice not paid yet.
        $this->seedDonationSubscription($this->masjid, $this->fund, ['status' => 'pending']);
        // A checkout page opened an hour ago is not a gift Stripe can bill yet. It
        // blocks the switch-off too, but is counted apart (openCheckoutCount) for its
        // own sentence, because nothing here can cancel it.
        $this->seedDonationSubscription($this->masjid, $this->fund, [
            'status' => 'pending',
            'stripe_subscription_id' => null,
            'stripe_customer_id' => null,
            'stripe_checkout_session_id' => 'cs_open_' . uniqid(),
        ], now()->subHour());

        $this->assertSame(4, GivingSwitch::liveSubscriptionCount($this->masjid));
        $this->assertSame(1, GivingSwitch::openCheckoutCount($this->masjid));
    }

    #[Test]
    public function a_cancelled_gift_and_an_abandoned_checkout_are_not_counted_and_stripe_is_never_asked(): void
    {
        $this->stripeSays([]);

        $this->seedDonationSubscription($this->masjid, $this->fund, ['status' => 'canceled']);
        // Cancelled properly: its last gift came before the cancel, so it is not even
        // a candidate, and loading the panel costs no Stripe call.
        $stopped = $this->seedDonationSubscription($this->masjid, $this->fund, [
            'status' => 'canceled',
            'canceled_at' => now()->subDays(3),
        ]);
        $this->bookGift($stopped, now()->subDays(20));
        // Never linked, and older than a day: its checkout page has expired.
        $this->seedDonationSubscription($this->masjid, $this->fund, [
            'status' => 'pending',
            'stripe_subscription_id' => null,
        ], now()->subDays(2));

        $this->assertSame(0, GivingSwitch::liveSubscriptionCount($this->masjid));
        $this->assertSame([], $this->stripeAsked);
    }

    #[Test]
    public function past_due_alone_is_enough_to_count(): void
    {
        $this->seedDonationSubscription($this->masjid, $this->fund, ['status' => 'past_due']);

        $this->assertSame(1, GivingSwitch::liveSubscriptionCount($this->masjid));
    }

    #[Test]
    public function another_organisations_gifts_are_never_counted(): void
    {
        $other = $this->makeMasjid();
        $this->seedDonationSubscription($other, $this->makeFund($other), ['status' => 'active']);

        $this->assertSame(0, GivingSwitch::liveSubscriptionCount($this->masjid));
        $this->assertSame(1, GivingSwitch::liveSubscriptionCount($other));
    }

    #[Test]
    public function an_open_checkout_is_a_page_stripe_opened_in_the_last_day_and_never_linked(): void
    {
        $unlinked = ['status' => 'pending', 'stripe_subscription_id' => null, 'stripe_customer_id' => null];

        // Open: Stripe created the page an hour ago.
        $this->seedDonationSubscription($this->masjid, $this->fund, $unlinked + ['stripe_checkout_session_id' => 'cs_open_' . uniqid()], now()->subHour());
        // The Stripe call threw, so there is no page to complete. Anyone can leave
        // one of these without logging in, so it holds nothing.
        $this->seedDonationSubscription($this->masjid, $this->fund, $unlinked, now()->subHour());
        // Expired: older than Stripe's 24-hour session.
        $this->seedDonationSubscription($this->masjid, $this->fund, $unlinked + ['stripe_checkout_session_id' => 'cs_old_' . uniqid()], now()->subHours(25));
        // Completed: linked, so it is a gift, not a page.
        $this->seedDonationSubscription($this->masjid, $this->fund, ['status' => 'pending', 'stripe_checkout_session_id' => 'cs_done_' . uniqid()]);
        // Cancelled before anyone paid.
        $this->seedDonationSubscription($this->masjid, $this->fund, [
            'status' => 'canceled',
            'stripe_subscription_id' => null,
            'stripe_customer_id' => null,
            'stripe_checkout_session_id' => 'cs_cancelled_' . uniqid(),
            'canceled_at' => now(),
        ], now()->subHour());

        $this->assertSame(1, GivingSwitch::openCheckoutCount($this->masjid));
        $this->assertSame(1, GivingSwitch::liveSubscriptionCount($this->masjid));

        $other = $this->makeMasjid();
        $this->assertSame(0, GivingSwitch::openCheckoutCount($other));
    }

    #[Test]
    public function a_gift_marked_cancelled_is_live_while_stripe_says_it_is_still_billing(): void
    {
        // Cancelled here three days ago; Stripe booked a gift against it yesterday and
        // says the subscription is live. An unlinked commitment cancelled before its
        // checkout completed does this.
        $billed = $this->seedDonationSubscription($this->masjid, $this->fund, [
            'status' => 'canceled',
            'stripe_subscription_id' => 'sub_billed_after_cancel',
            'canceled_at' => now()->subDays(3),
        ]);
        $this->bookGift($billed, now()->subDay());

        // Cancelled properly: its last gift came before the cancel.
        $stopped = $this->seedDonationSubscription($this->masjid, $this->fund, [
            'status' => 'canceled',
            'stripe_subscription_id' => 'sub_stopped',
            'canceled_at' => now()->subDays(3),
        ]);
        $this->bookGift($stopped, now()->subDays(20));

        $this->stripeSays(['sub_billed_after_cancel' => 'active']);

        $this->assertSame(1, GivingSwitch::liveSubscriptionCount($this->masjid));
        $this->assertSame(1, GivingSwitch::billedAfterCancelCount($this->masjid));

        // Only the candidate reached Stripe, on this organisation's connected account.
        $this->assertSame(
            [['sub_billed_after_cancel', $this->masjid->stripe_account_id]],
            array_values(array_unique($this->stripeAsked, SORT_REGULAR))
        );

        // A live gift is not "billed after cancel".
        $this->seedDonationSubscription($this->masjid, $this->fund, ['status' => 'active']);
        $this->assertSame(2, GivingSwitch::liveSubscriptionCount($this->masjid));
        $this->assertSame(1, GivingSwitch::billedAfterCancelCount($this->masjid));
    }

    #[Test]
    public function a_late_or_old_invoice_on_a_gift_stripe_confirms_cancelled_is_not_live(): void
    {
        // Charged before the cancel; its invoice webhook was processed 5 minutes after it.
        $late = $this->seedDonationSubscription($this->masjid, $this->fund, [
            'status' => 'canceled',
            'stripe_subscription_id' => 'sub_late_webhook',
            'canceled_at' => now()->subMinutes(10),
        ]);
        $this->bookGift($late, now()->subMinutes(5));

        // Cancelled 7 months ago; a replay after an outage booked a gift 6 months ago.
        $old = $this->seedDonationSubscription($this->masjid, $this->fund, [
            'status' => 'canceled',
            'stripe_subscription_id' => 'sub_old_replay',
            'canceled_at' => now()->subMonths(7),
        ]);
        $this->bookGift($old, now()->subMonths(6));

        $this->stripeSays(['sub_late_webhook' => 'canceled', 'sub_old_replay' => 'canceled']);

        $this->assertSame(0, GivingSwitch::liveSubscriptionCount($this->masjid));
        $this->assertSame(0, GivingSwitch::billedAfterCancelCount($this->masjid));
    }

    #[Test]
    public function a_gift_marked_cancelled_that_stripe_cannot_be_asked_about_is_counted(): void
    {
        // Stripe does not answer: refusing the switch-off is the safe mistake.
        $outage = $this->seedDonationSubscription($this->masjid, $this->fund, [
            'status' => 'canceled',
            'stripe_subscription_id' => 'sub_outage',
            'canceled_at' => now()->subDays(3),
        ]);
        $this->bookGift($outage, now()->subDay());

        $this->stripeSays([]);

        $this->assertSame(1, GivingSwitch::liveSubscriptionCount($this->masjid));
        $this->assertSame(1, GivingSwitch::billedAfterCancelCount($this->masjid));

        // No connected account to ask on: it counts, and Stripe is never called.
        $unconnected = $this->makeMasjid();
        $unconnected->forceFill(['stripe_account_id' => ''])->save();
        $gift = $this->seedDonationSubscription($unconnected, $this->makeFund($unconnected), [
            'status' => 'canceled',
            'stripe_subscription_id' => 'sub_unconnected',
            'canceled_at' => now()->subDays(3),
        ]);
        $this->bookGift($gift, now()->subDay());
        $this->stripeAsked = [];

        $this->assertSame(1, GivingSwitch::billedAfterCancelCount($unconnected->fresh()));
        $this->assertSame([], $this->stripeAsked);
    }

    #[Test]
    public function the_seed_guard_accepts_only_what_the_production_enum_holds(): void
    {
        foreach (['pending', 'active', 'past_due', 'canceled'] as $status) {
            $this->assertTrue(self::subscriptionStatusIsStorable($status), "'{$status}' is in the ENUM");
        }

        foreach (['paused', 'cancelled', 'incomplete', 'trialing', ''] as $status) {
            $this->assertFalse(
                self::subscriptionStatusIsStorable($status),
                "'{$status}' would be stored by SQLite and refused by MySQL"
            );
        }
    }

    // ---------------------------------------------------------------- arrivals

    #[Test]
    public function money_arriving_while_giving_is_off_is_noted_once_per_object(): void
    {
        Log::spy();
        $this->switchGivingOff();

        // The same gift, three times: a card gift's two events plus a Stripe retry.
        foreach (range(1, 3) as $delivery) {
            GivingSwitch::noteArrivalIfOff($this->masjid->id, 'gift', 41, ['amount_minor' => 5000]);
        }
        GivingSwitch::noteArrivalIfOff($this->masjid->id, 'gift', 42);
        GivingSwitch::noteArrivalIfOff($this->masjid->id, 'monthly_gift_started', 41);

        $this->assertNoted('gift', 41, 1);
        $this->assertNoted('gift', 42, 1);
        $this->assertNoted('monthly_gift_started', 41, 1);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => $message === GivingSwitch::ARRIVAL_MESSAGE)
            ->times(3);
    }

    #[Test]
    public function the_note_names_the_organisation_and_what_arrived(): void
    {
        Log::spy();
        $this->switchGivingOff();

        GivingSwitch::noteArrivalIfOff($this->masjid->id, 'gift', 7, ['amount_minor' => 2500]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === GivingSwitch::ARRIVAL_MESSAGE
                && $context === [
                    'masjid_id' => $this->masjid->id,
                    'kind' => 'gift',
                    'id' => 7,
                    'amount_minor' => 2500,
                ])
            ->once();
    }

    #[Test]
    public function nothing_is_noted_while_giving_is_on_and_the_once_guard_is_not_spent(): void
    {
        Log::spy();

        GivingSwitch::noteArrivalIfOff($this->masjid->id, 'gift', 51);

        Log::shouldNotHaveReceived('warning', fn ($message) => $message === GivingSwitch::ARRIVAL_MESSAGE);

        // The arrival while on did not use up this gift's once-only note.
        $this->switchGivingOff();
        GivingSwitch::noteArrivalIfOff($this->masjid->id, 'gift', 51);

        $this->assertNoted('gift', 51, 1);
    }

    #[Test]
    public function an_unknown_organisation_notes_nothing(): void
    {
        Log::spy();

        GivingSwitch::noteArrivalIfOff(999999, 'gift', 61);

        Log::shouldNotHaveReceived('warning', fn ($message) => $message === GivingSwitch::ARRIVAL_MESSAGE);
    }

    #[Test]
    public function a_failing_log_never_reaches_the_webhook(): void
    {
        $this->switchGivingOff();

        // The webhook turns anything thrown into a 500 and Stripe retries for days.
        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('log sink unavailable'));

        GivingSwitch::noteArrivalIfOff($this->masjid->id, 'gift', 71);

        // Reaching this line is the assertion; once() above proves the note was attempted.
        $this->addToAssertionCount(1);
    }

    // ============================= helpers =============================

    /** A gift Stripe booked against this commitment, at $at. */
    private function bookGift(DonationSubscription $subscription, Carbon $at): Donation
    {
        return Donation::factory()->succeeded()->create([
            'masjid_id' => $subscription->masjid_id,
            'fund_id' => $subscription->fund_id,
            'stripe_subscription_id' => $subscription->stripe_subscription_id,
            'created_at' => $at,
        ]);
    }

    /**
     * Stand in for Stripe: DonationService's retrieve seam answers the status listed
     * for a subscription id, and any id not listed is an outage. Every question is
     * recorded in $stripeAsked. Tests MUST NOT hit the live Stripe API.
     *
     * @param  array<string, string>  $statuses  Stripe subscription id => Stripe's status
     */
    private function stripeSays(array $statuses): void
    {
        $service = Mockery::mock(DonationService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('retrieveStripeSubscription')
            ->andReturnUsing(function (string $id, string $account) use ($statuses) {
                $this->stripeAsked[] = [$id, $account];

                if (! array_key_exists($id, $statuses)) {
                    throw new \RuntimeException("Stripe did not answer for {$id}");
                }

                return ['status' => $statuses[$id], 'paused' => false, 'unit_amount' => 5000, 'item_id' => null, 'product_id' => null];
            });

        $this->app->instance(DonationService::class, $service);
    }

    private function assertNoted(string $kind, int $id, int $times): void
    {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === GivingSwitch::ARRIVAL_MESSAGE
                && ($context['kind'] ?? null) === $kind
                && ($context['id'] ?? null) === $id)
            ->times($times);
    }

    private function switchGivingOff(): void
    {
        $overrides = $this->masjid->capability_overrides ?? [];
        $overrides['giving'] = false;
        $this->masjid->forceFill(['capability_overrides' => $overrides])->save();

        $this->assertTrue($this->masjid->fresh()->moduleIsOff('giving'));
    }

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Giving Switch Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            'stripe_account_id' => 'acct_TEST' . uniqid(),
            'stripe_charges_enabled' => true,
        ]);
    }

    private function makeFund(Masjid $masjid): Fund
    {
        return Fund::withoutMasjidScope()->create([
            'masjid_id' => $masjid->id,
            'name' => 'General',
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
        ]);
    }
}
