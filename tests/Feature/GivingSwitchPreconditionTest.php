<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationSubscription;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\MasjidCapabilityChange;
use App\Models\MasjidUser;
use App\Models\User;
use App\Services\Stripe\DonationService;
use App\Support\GivingSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Switching Giving OFF is refused while a monthly gift Stripe can bill exists
 * (owner, 2026-09-14: block, not warn). MasjidsController::setCapability asks
 * GivingSwitch before it writes anything.
 *
 * A switch never cancels, pauses or changes a donor's gift, and once Giving is
 * off the organisation's admins cannot see Recurring Donations. A flip that went
 * through with a live gift would leave money moving that nobody at the
 * organisation can see or stop. So the SuperAdmin cancels first, and a refused
 * flip leaves no ledger row, because nothing changed.
 *
 * A monthly-gift checkout page still open blocks just as firmly, but never with
 * "cancel": the admin cancel marks an unlinked row cancelled and leaves its page
 * payable, so following that advice would let Stripe bill a gift this system
 * reports as cancelled. The sentence says wait. There is no "switch off anyway".
 *
 * A row marked cancelled here blocks only when Stripe says it is still billing.
 * Stripe is stood in for by DonationService's retrieve seam (stripeSays()); tests
 * MUST NOT hit the live Stripe API.
 *
 * `donation_subscriptions.status` is enum('pending','active','past_due',
 * 'canceled') in production. SQLite ignores the ENUM, so every seed here goes
 * through subscription(), which refuses any other value: a donor's pause lives
 * only at Stripe (pause_collection) and the row stays `active`.
 */
class GivingSwitchPreconditionTest extends TestCase
{
    use RefreshDatabase;

    private const STATUSES = ['pending', 'active', 'past_due', 'canceled'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function org(): Masjid
    {
        return Masjid::create([
            'name' => 'Giving Org ' . uniqid(),
            'email' => 'giving' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => 'masjid',
            'stripe_account_id' => 'acct_TEST' . uniqid(),
            'stripe_charges_enabled' => true,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)])->fresh();
    }

    private function admin(Masjid $masjid): User
    {
        $user = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        MasjidUser::create(['masjid_id' => $masjid->id, 'user_id' => $user->id, 'role' => 'masjid-admin', 'is_default' => true]);

        return $user->fresh();
    }

    /**
     * Seed one standing gift. Seeded BEFORE any request: a SuperAdmin request
     * binds the tenant from the route, and a later create would be stamped with it.
     *
     * @param  array<string, mixed>  $extra  any other fillable column (a checkout session id, canceled_at)
     */
    private function subscription(Masjid $masjid, string $status, ?string $stripeId, ?Carbon $createdAt = null, array $extra = []): DonationSubscription
    {
        $this->assertContains($status, self::STATUSES, "'{$status}' cannot exist in production: donation_subscriptions.status is an ENUM");

        $fund = Fund::factory()->create(['masjid_id' => $masjid->id]);

        $subscription = DonationSubscription::create(array_merge([
            'masjid_id' => $masjid->id,
            'fund_id' => $fund->id,
            'intended_amount' => 5000,
            'charged_amount' => 5000,
            'currency' => 'usd',
            'interval' => 'month',
            'status' => $status,
            'stripe_subscription_id' => $stripeId,
            'idempotency_key' => 'sub_' . uniqid('', true),
            'canceled_at' => $status === 'canceled' ? now() : null,
        ], $extra));

        if ($createdAt !== null) {
            $subscription->forceFill(['created_at' => $createdAt])->save();
        }

        return $subscription->fresh();
    }

    /** A monthly-gift checkout page Stripe opened $hoursOld hours ago, never completed. */
    private function openCheckout(Masjid $masjid, int $hoursOld): DonationSubscription
    {
        return $this->subscription($masjid, 'pending', null, now()->subHours($hoursOld), [
            'stripe_checkout_session_id' => 'cs_open_' . uniqid(),
        ]);
    }

    /** A gift Stripe booked against this commitment, processed here at $at. */
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
     * for a subscription id, and any id not listed is an outage.
     *
     * @param  array<string, string>  $statuses  Stripe subscription id => Stripe's status
     */
    private function stripeSays(array $statuses): void
    {
        $service = Mockery::mock(DonationService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('retrieveStripeSubscription')
            ->andReturnUsing(function (string $id, string $account) use ($statuses) {
                if (! array_key_exists($id, $statuses)) {
                    throw new \RuntimeException("Stripe did not answer for {$id}");
                }

                return ['status' => $statuses[$id], 'paused' => false, 'unit_amount' => 5000, 'item_id' => null, 'product_id' => null];
            });

        $this->app->instance(DonationService::class, $service);
    }

    /** @param  array<string, string>  $extra  anything else the request carries */
    private function switchGiving(Masjid $masjid, string $enabled, array $extra = []): TestResponse
    {
        // Form-encoded "0"/"1", exactly as the SPA sends it.
        return $this->patch("/api/admin/masjids/{$masjid->id}/capabilities/giving", ['enabled' => $enabled] + $extra, ['Accept' => 'application/json']);
    }

    private function sentence(int $n, int $billedAfterCancel = 0): string
    {
        $tail = match (true) {
            $billedAfterCancel === 1 => ' 1 of them already shows as cancelled here but Stripe is still billing it, so cancel that one in Stripe.',
            $billedAfterCancel > 1 => " {$billedAfterCancel} of them already show as cancelled here but Stripe is still billing them, so cancel those in Stripe.",
            default => '',
        };

        return ($n === 1 ? '1 monthly gift can' : "{$n} monthly gifts can")
            . ' still charge donors. Cancel them on Recurring Donations or in Stripe first.'
            . $tail;
    }

    private function openSentence(int $n): string
    {
        return $n === 1
            ? '1 monthly-gift checkout page opened in the last 24 hours can still start a monthly gift. Try again once it expires, 24 hours after it opened.'
            : "{$n} monthly-gift checkout pages opened in the last 24 hours can still start a monthly gift. Try again once they expire, 24 hours after each one opened.";
    }

    /** @return array<string, array{0:string, 1:?string, 2:int}> status, Stripe id, hours old */
    public static function giftsThatCanStillCharge(): array
    {
        return [
            'an active gift linked to Stripe' => ['active', 'sub_active_' . uniqid(), 0],
            // Paused at Stripe: locally still `active`, and still a commitment.
            'a gift the donor paused at Stripe' => ['active', 'sub_paused_' . uniqid(), 24 * 40],
            'a past_due gift Stripe is still retrying' => ['past_due', 'sub_past_due_' . uniqid(), 24 * 10],
            'a linked gift whose first invoice is not paid yet' => ['pending', 'sub_pending_' . uniqid(), 1],
        ];
    }

    #[Test]
    #[DataProvider('giftsThatCanStillCharge')]
    public function a_gift_that_can_still_charge_refuses_the_switch_off_with_a_sentence_and_no_ledger_row(string $status, ?string $stripeId, int $hoursOld): void
    {
        $org = $this->org();
        $this->subscription($org, $status, $stripeId, now()->subHours($hoursOld));

        $this->assertSame(1, GivingSwitch::liveSubscriptionCount($org));
        $this->assertSame(0, GivingSwitch::openCheckoutCount($org));

        Sanctum::actingAs($this->superAdmin());

        $this->switchGiving($org, '0')
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['capability' => [$this->sentence(1)]]]);

        $fresh = $org->fresh();
        $this->assertSame(0, MasjidCapabilityChange::where('masjid_id', $org->id)->count(), 'a refused flip wrote a ledger row');
        $this->assertArrayNotHasKey('giving', $fresh->capability_overrides ?? []);
        $this->assertFalse($fresh->moduleIsOff('giving'));
    }

    #[Test]
    public function the_sentence_carries_the_count_and_past_due_is_counted(): void
    {
        $org = $this->org();
        $this->subscription($org, 'active', 'sub_a_' . uniqid());
        $this->subscription($org, 'past_due', 'sub_b_' . uniqid());
        // Unlinked and an hour old, but Stripe never opened a page for it.
        $this->subscription($org, 'pending', null, now()->subHour());
        $this->subscription($org, 'canceled', 'sub_c_' . uniqid());
        $this->subscription($org, 'pending', null, now()->subHours(25));

        $this->assertSame(2, GivingSwitch::liveSubscriptionCount($org));

        Sanctum::actingAs($this->superAdmin());

        $this->switchGiving($org, '0')
            ->assertStatus(422)
            ->assertJsonPath('data.capability.0', $this->sentence(2));
    }

    #[Test]
    public function a_gift_marked_cancelled_that_stripe_is_still_billing_refuses_and_is_sent_to_stripe(): void
    {
        // An unlinked commitment cancelled here, whose checkout the donor then
        // completed: the row says canceled, Stripe booked a gift yesterday, and
        // Stripe says the subscription is live.
        $org = $this->org();
        $gift = $this->subscription($org, 'canceled', 'sub_billed_' . uniqid(), null, ['canceled_at' => now()->subDays(3)]);
        $this->bookGift($gift, now()->subDay());
        $this->stripeSays([$gift->stripe_subscription_id => 'active']);

        $this->assertSame(1, GivingSwitch::liveSubscriptionCount($org));
        $this->assertSame(1, GivingSwitch::billedAfterCancelCount($org));

        Sanctum::actingAs($this->superAdmin());

        // Cancel on Recurring Donations skips a cancelled row, so the sentence names
        // Stripe. A flag an older panel sent to switch off anyway changes nothing.
        $this->switchGiving($org, '0', ['accept_open_checkouts' => '1'])
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['capability' => [$this->sentence(1, 1)]]]);

        $this->assertFalse($org->fresh()->moduleIsOff('giving'));
    }

    #[Test]
    public function a_late_or_old_invoice_on_a_gift_stripe_confirms_cancelled_never_blocks(): void
    {
        $org = $this->org();

        // Charged before the cancel, its invoice webhook processed 5 minutes after it.
        $late = $this->subscription($org, 'canceled', 'sub_late_' . uniqid(), null, ['canceled_at' => now()->subMinutes(10)]);
        $this->bookGift($late, now()->subMinutes(5));

        // Cancelled 7 months ago; a replay booked a gift after it 6 months ago.
        $old = $this->subscription($org, 'canceled', 'sub_old_' . uniqid(), null, ['canceled_at' => now()->subMonths(7)]);
        $this->bookGift($old, now()->subMonths(6));

        $this->stripeSays([
            $late->stripe_subscription_id => 'canceled',
            $old->stripe_subscription_id => 'canceled',
        ]);

        $this->assertSame(0, GivingSwitch::liveSubscriptionCount($org));
        $this->assertSame(0, GivingSwitch::billedAfterCancelCount($org));

        Sanctum::actingAs($this->superAdmin());

        $this->switchGiving($org, '0')
            ->assertOk()
            ->assertJsonPath('data.modules_off', ['giving']);

        $this->assertTrue($org->fresh()->moduleIsOff('giving'));
    }

    #[Test]
    public function a_gift_marked_cancelled_that_stripe_cannot_be_asked_about_still_blocks(): void
    {
        // A gift booked after the cancel, and Stripe does not answer: blocking is
        // the safe mistake.
        $org = $this->org();
        $gift = $this->subscription($org, 'canceled', 'sub_unknown_' . uniqid(), null, ['canceled_at' => now()->subDays(3)]);
        $this->bookGift($gift, now()->subDay());
        $this->stripeSays([]);

        Sanctum::actingAs($this->superAdmin());

        $this->switchGiving($org, '0')
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['capability' => [$this->sentence(1, 1)]]]);

        $this->assertFalse($org->fresh()->moduleIsOff('giving'));
    }

    #[Test]
    public function open_checkout_pages_refuse_the_switch_off_say_wait_never_cancel_and_have_no_override(): void
    {
        $org = $this->org();
        $first = $this->openCheckout($org, 2);
        $second = $this->openCheckout($org, 5);

        $this->assertSame(0, GivingSwitch::liveSubscriptionCount($org));
        $this->assertSame(2, GivingSwitch::openCheckoutCount($org));

        Sanctum::actingAs($this->superAdmin());

        $refused = $this->switchGiving($org, '0')
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['capability' => [$this->openSentence(2)]]]);

        // Following "cancel them" here is what let Stripe bill a cancelled row.
        $this->assertStringNotContainsString('Cancel them', (string) $refused->json('data.capability.0'));

        // Owner, 2026-09-14: block. The flag an older panel sent to switch off
        // anyway is ignored.
        $this->switchGiving($org, '0', ['accept_open_checkouts' => '1'])
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['capability' => [$this->openSentence(2)]]]);

        $this->assertSame(0, MasjidCapabilityChange::where('masjid_id', $org->id)->count(), 'a refused flip wrote a ledger row');
        $this->assertFalse($org->fresh()->moduleIsOff('giving'));

        // The refusal changed no gift: both pages are exactly as they were.
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);

        // Waiting works: once both pages are past Stripe's 24 hours, the flip goes through.
        $this->travel(25)->hours();

        $this->switchGiving($org, '0')
            ->assertOk()
            ->assertJsonPath('data.modules_off', ['giving']);

        $row = MasjidCapabilityChange::where('masjid_id', $org->id)->where('capability', 'giving')->sole();
        $this->assertTrue((bool) $row->enabled_before);
        $this->assertFalse((bool) $row->enabled_after);
    }

    #[Test]
    public function a_gift_stripe_can_bill_decides_the_sentence_when_pages_are_also_open(): void
    {
        $org = $this->org();
        $this->subscription($org, 'active', 'sub_live_' . uniqid());
        $this->openCheckout($org, 1);

        Sanctum::actingAs($this->superAdmin());

        // The gift decides first: cancel it, and the page's own refusal follows.
        $this->switchGiving($org, '0')
            ->assertStatus(422)
            ->assertExactJson(['status' => 'failed', 'data' => ['capability' => [$this->sentence(1)]]]);

        $this->assertSame(0, MasjidCapabilityChange::where('masjid_id', $org->id)->count());
        $this->assertFalse($org->fresh()->moduleIsOff('giving'));
    }

    #[Test]
    public function checkouts_stripe_never_opened_or_that_expired_do_not_hold_the_switch(): void
    {
        // What an anonymous caller leaves when the Stripe call throws: pending rows
        // with no subscription and no page. None of them can ever charge anyone.
        $org = $this->org();
        foreach (range(1, 3) as $i) {
            $this->subscription($org, 'pending', null, now()->subMinutes($i));
        }
        // A page older than Stripe's 24-hour session.
        $this->openCheckout($org, 25);

        $this->assertSame(0, GivingSwitch::liveSubscriptionCount($org));
        $this->assertSame(0, GivingSwitch::openCheckoutCount($org));

        Sanctum::actingAs($this->superAdmin());

        $this->switchGiving($org, '0')
            ->assertOk()
            ->assertJsonPath('data.modules_off', ['giving']);
    }

    #[Test]
    public function canceled_gifts_and_an_abandoned_checkout_do_not_block(): void
    {
        $org = $this->org();
        $this->subscription($org, 'canceled', 'sub_done_' . uniqid());
        // A checkout nobody finished, older than Stripe's 24-hour session.
        $this->subscription($org, 'pending', null, now()->subHours(25));

        $this->assertSame(0, GivingSwitch::liveSubscriptionCount($org));

        Sanctum::actingAs($this->superAdmin());

        $this->switchGiving($org, '0')
            ->assertOk()
            ->assertJsonPath('data.modules_off', ['giving']);

        $row = MasjidCapabilityChange::where('masjid_id', $org->id)->where('capability', 'giving')->firstOrFail();
        $this->assertTrue((bool) $row->enabled_before);
        $this->assertFalse((bool) $row->enabled_after);
    }

    #[Test]
    public function another_organisations_gifts_and_checkouts_do_not_block(): void
    {
        $org = $this->org();
        $neighbour = $this->org();
        $this->subscription($neighbour, 'active', 'sub_neighbour_' . uniqid());
        $this->openCheckout($neighbour, 1);

        $this->assertSame(0, GivingSwitch::liveSubscriptionCount($org));
        $this->assertSame(0, GivingSwitch::openCheckoutCount($org));
        $this->assertSame(1, GivingSwitch::liveSubscriptionCount($neighbour));
        $this->assertSame(1, GivingSwitch::openCheckoutCount($neighbour));

        Sanctum::actingAs($this->superAdmin());

        $this->switchGiving($org, '0')->assertOk();
        $this->assertTrue($org->fresh()->moduleIsOff('giving'));
    }

    #[Test]
    public function switching_giving_on_is_never_refused(): void
    {
        $org = $this->org();
        $this->subscription($org, 'active', 'sub_on_' . uniqid());
        $this->openCheckout($org, 1);
        // Off already (a gift that started after the flip, say).
        $org->forceFill(['capability_overrides' => ['giving' => false]])->save();

        Sanctum::actingAs($this->superAdmin());

        $this->switchGiving($org, '1')
            ->assertOk()
            ->assertJsonPath('data.modules_off', []);

        $this->assertTrue($org->fresh()->capability_overrides['giving']);

        $row = MasjidCapabilityChange::where('masjid_id', $org->id)->where('capability', 'giving')->firstOrFail();
        $this->assertFalse((bool) $row->enabled_before);
        $this->assertTrue((bool) $row->enabled_after);
    }

    #[Test]
    public function the_super_admin_cancels_a_linked_gift_on_recurring_donations_first_even_while_giving_is_off(): void
    {
        // Before the flip: refused, cancel, then the flip goes through.
        $org = $this->org();
        $gift = $this->subscription($org, 'active', 'sub_cancel_first_' . uniqid());

        // After a flip: a gift that started anyway (a checkout that raced the flip),
        // which the org's own admins can no longer reach, but the SuperAdmin can.
        $offOrg = $this->org();
        $lateGift = $this->subscription($offOrg, 'active', 'sub_started_late_' . uniqid());
        $offOrg->forceFill(['capability_overrides' => ['giving' => false]])->save();
        $offAdmin = $this->admin($offOrg);
        $super = $this->superAdmin();

        // A linked gift is cancelled AT Stripe; the double answers for Stripe, and
        // proves the cancel reached it for both gifts.
        $donations = Mockery::mock(DonationService::class)->makePartial();
        $donations->shouldAllowMockingProtectedMethods();
        $donations->shouldReceive('cancelStripeSubscription')
            ->once()
            ->with($gift->stripe_subscription_id, $org->stripe_account_id);
        $donations->shouldReceive('cancelStripeSubscription')
            ->once()
            ->with($lateGift->stripe_subscription_id, $offOrg->stripe_account_id);
        $this->app->instance(DonationService::class, $donations);

        Sanctum::actingAs($super);

        $this->switchGiving($org, '0')->assertStatus(422);

        $this->postJson("/api/admin/masjids/{$org->id}/recurring-donations/{$gift->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        $this->switchGiving($org, '0')->assertOk();
        $this->assertTrue($org->fresh()->moduleIsOff('giving'));

        Sanctum::actingAs($offAdmin);
        $this->postJson("/api/admin/masjids/{$offOrg->id}/recurring-donations/{$lateGift->id}/cancel")
            ->assertForbidden()
            ->assertExactJson(['status' => 'error', 'message' => 'Giving is switched off for this organisation.']);
        $this->assertSame('active', $lateGift->fresh()->status);

        Sanctum::actingAs($super);
        $this->postJson("/api/admin/masjids/{$offOrg->id}/recurring-donations/{$lateGift->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');
        $this->assertSame(0, GivingSwitch::liveSubscriptionCount($offOrg->fresh()));
    }
}
