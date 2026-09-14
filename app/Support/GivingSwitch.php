<?php

namespace App\Support;

use App\Models\DonationSubscription;
use App\Models\Masjid;
use App\Services\Stripe\DonationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What the Giving switch (config/capabilities.php `giving`) can and cannot stop.
 *
 * A switch never refuses money that has already moved: webhooks, receipts and
 * receipt emails run for a switched-off organisation exactly as they do for any
 * other, because the charge happened at Stripe whatever Manara thinks. What a
 * switched-off organisation loses is the giving SCREENS; the app stops OPENING new
 * checkouts (Mobile\DonationsController, Mobile\FundsController). Three things can
 * still arrive after a flip, and this class is where they are counted and noted:
 *
 *  - a monthly gift Stripe can still bill (liveSubscriptionCount), which the flip
 *    is refused over until it is cancelled, because nobody at the organisation
 *    could see or cancel it once the screens are hidden;
 *  - a monthly-gift checkout page still open (openCheckoutCount), which the flip
 *    is also refused over (owner, 2026-09-14: block), until the page expires. It
 *    is never sent to Recurring Donations to be cancelled: that cancel only marks
 *    an unlinked row cancelled and leaves the page payable, so Stripe would start
 *    billing a gift this system reports as cancelled
 *    (DonationService::linkSubscriptionCheckout);
 *  - money arriving anyway (noteArrivalIfOff): a delayed bank debit, an invoice, or
 *    a checkout that raced the flip.
 */
final class GivingSwitch
{
    /** The warning line an arrival writes. Production runs LOG_LEVEL=warning. */
    public const ARRIVAL_MESSAGE = 'Money arrived for an organisation whose Giving is switched off';

    /** How long one object stays noted, comfortably past Stripe's retry window. */
    private const ARRIVAL_GUARD_DAYS = 3;

    /** Stripe subscription statuses that can never bill again. Both are final at Stripe. */
    private const STRIPE_STOPPED = ['canceled', 'incomplete_expired'];

    /**
     * Monthly gifts at this organisation that Stripe can still bill.
     *
     * Only a row Stripe has linked a subscription to can be billed; an unlinked
     * `pending` row is a checkout page at most (openCheckoutCount), and one whose
     * Stripe call threw is not even that. Read from the LOCAL status, which the
     * donation_subscriptions ENUM limits to pending, active, past_due and canceled.
     * A gift a donor paused is still `active` here (the pause lives only at Stripe
     * as pause_collection), so it is counted: resuming it bills again. `past_due`
     * is counted because Stripe is still retrying it.
     *
     * A `canceled` row counts too when Stripe itself says it is still billing
     * (billedAfterCancelCount).
     *
     * Hand-filtered by masjid_id with no global scope: the caller is a SuperAdmin
     * acting on another organisation, not a bound tenant.
     */
    public static function liveSubscriptionCount(Masjid $masjid): int
    {
        return self::linked($masjid)->where('status', '!=', 'canceled')->count()
            + self::billedAfterCancelCount($masjid);
    }

    /**
     * Rows that read `canceled` here while Stripe is still billing them: an unlinked
     * commitment cancelled here whose checkout the donor then completed, or an admin
     * cancel whose Stripe call failed (DonationService::cancelSubscription marks the
     * row cancelled either way). Cancel on Recurring Donations skips a cancelled row,
     * so these can only be stopped in the Stripe dashboard, and the refusal says so.
     *
     * Local timestamps only pick the candidates: a gift booked after canceled_at.
     * They cannot decide, because canceled_at is stamped when the admin clicks and a
     * donation's created_at when its webhook is processed. An invoice Stripe charged
     * before the cancel, delivered late or replayed after an outage, lands after it
     * too, and nothing would ever age that row out. So Stripe decides: a candidate
     * counts unless Stripe says its subscription is stopped. When Stripe cannot be
     * asked (an outage, no connected account), it counts, because refusing a
     * switch-off is the safe mistake. A row with no gift booked after its cancel
     * never reaches Stripe, so the panel costs no Stripe call for an ordinary
     * cancelled gift.
     */
    public static function billedAfterCancelCount(Masjid $masjid): int
    {
        $candidates = self::linked($masjid)
            ->where(fn (Builder $cancelled) => self::bookedAfterCancel($cancelled))
            ->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $donations = app(DonationService::class);

        return $candidates
            ->filter(fn (DonationSubscription $subscription) => ! in_array(
                $donations->stripeStatusOf($subscription),
                self::STRIPE_STOPPED,
                true
            ))
            ->count();
    }

    /**
     * Monthly-gift checkout pages opened in the last 24 hours that a donor can still
     * complete: `pending`, no Stripe subscription yet, and a Checkout Session Stripe
     * actually created (a row whose Stripe call threw has no page to complete).
     * Checkout Sessions last Stripe's default 24 hours; createSubscriptionCheckout
     * sets no expiry of its own.
     *
     * The switch-off is refused over these as firmly as over a live gift, but with
     * its own sentence: wait for the page to expire, never cancel it (see the class
     * docblock). They are kept apart from liveSubscriptionCount for that sentence.
     */
    public static function openCheckoutCount(Masjid $masjid): int
    {
        return DonationSubscription::withoutGlobalScopes()
            ->where('masjid_id', $masjid->id)
            ->where('status', 'pending')
            ->whereNull('stripe_subscription_id')
            ->whereNotNull('stripe_checkout_session_id')
            ->where('stripe_checkout_session_id', '!=', '')
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }

    /** This organisation's rows Stripe has linked a subscription to. */
    private static function linked(Masjid $masjid): Builder
    {
        return DonationSubscription::withoutGlobalScopes()
            ->where('masjid_id', $masjid->id)
            ->whereNotNull('stripe_subscription_id')
            ->where('stripe_subscription_id', '!=', '');
    }

    /**
     * A `canceled` row with a gift booked against its Stripe subscription after its
     * canceled_at: a candidate for billedAfterCancelCount, never a verdict. Donations
     * link to a commitment by stripe_subscription_id (DonationSubscription::donations),
     * and the subquery is a plain query builder, so no tenant scope applies to it.
     */
    private static function bookedAfterCancel(Builder $query): void
    {
        $query->where('status', 'canceled')
            ->whereNotNull('canceled_at')
            ->whereExists(function ($booked) {
                $booked->select(DB::raw(1))
                    ->from('donations')
                    ->whereColumn('donations.masjid_id', 'donation_subscriptions.masjid_id')
                    ->whereColumn('donations.stripe_subscription_id', 'donation_subscriptions.stripe_subscription_id')
                    ->whereColumn('donations.created_at', '>', 'donation_subscriptions.canceled_at');
            });
    }

    /**
     * Log, once per object, money that arrived for an organisation whose Giving is
     * switched off. Called by StripeWebhookController AFTER the booking, receipt
     * and email it already does, and it changes none of them.
     *
     * Once per object, not once per event: a card gift raises both
     * checkout.session.completed and payment_intent.succeeded, Stripe retries, and
     * a replayed invoice returns the donation it already booked. Cache::add is
     * atomic, so only the first of those for the same `$kind:$objectId` writes.
     * The switch is read FIRST, so an organisation with Giving on never touches
     * the cache.
     *
     * NEVER THROWS. It runs inside the webhook's try, where an exception turns
     * into a 500 and Stripe retries the event for days; a cache or log outage must
     * not do that to money that is already booked. A stale config mid-deploy reads
     * each organisation type's default (Masjid::moduleIsOff), so it notes nothing
     * for a masjid.
     */
    public static function noteArrivalIfOff(int $masjidId, string $kind, int|string $objectId, array $context = []): void
    {
        try {
            if (Masjid::find($masjidId)?->moduleIsOff('giving') !== true) {
                return;
            }

            if (! Cache::add("giving_off_arrival:{$kind}:{$objectId}", 1, now()->addDays(self::ARRIVAL_GUARD_DAYS))) {
                return;
            }

            Log::warning(self::ARRIVAL_MESSAGE, [
                'masjid_id' => $masjidId,
                'kind' => $kind,
                'id' => $objectId,
            ] + $context);
        } catch (\Throwable $e) {
            // Deliberately swallowed; see the docblock. The money is booked either way.
        }
    }
}
