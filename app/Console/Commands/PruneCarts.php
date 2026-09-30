<?php

namespace App\Console\Commands;

use App\Models\Cart;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delete open baskets that were abandoned long enough ago (universal cart, brief 5 section 5).
 *
 * A basket holds ANSWERS: on a festival ticket form that is the name of every attendee, and a
 * gift's zakat choice. Nothing in it is reserved and nothing in it is a sale, so once its
 * holder has been gone for a while it is only personal data with a token nobody will present.
 * The public endpoints slide a basket's expiry a week out on every write
 * (config/cart.php `ttl_days`); this sweep deletes what is still an OPEN basket more than
 * `prune.grace_days` (one day) after that expiry. The day is slack, not a policy: an expired
 * basket is already invisible to its holder (Cart::findLiveByToken), so it never matters to a
 * shopper when this runs.
 *
 * A basket is KEPT while a payment page of it could still be paid: a PENDING order whose own
 * expiry (`checkout_expires_at`) is less than `prune.hold_minutes` (an hour) behind. That is
 * belt and braces and not what makes a late payment safe, because a payment that lands for an
 * order whose basket is gone is still settled and recorded in full: settlement writes each
 * line from the order's own frozen snapshot (`order_items`), never from the basket
 * (CartSettlementService), and the order survives its basket (`orders.cart_id` is
 * nullOnDelete). A late-payment test pins both halves (CartPruneTest).
 *
 * Only OPEN baskets go: a basket that was paid for (`checked_out`) is an empty shell that
 * holds nothing personal. Deleting a basket cascades its lines (`cart_items.cart_id`), so the
 * answers go with it. A basket with no expiry (one the public endpoint did not make) is left
 * alone.
 *
 * The frozen copy of the same data lives on the ORDER, and outlives the basket by design
 * (`orders.cart_id` is nullOnDelete): `order_items.payload` holds the attendee names and
 * `orders.buyer_name`, `buyer_phone` and `buyer_email` the shopper's own details. An unpaid
 * order goes too, and its lines with it in the same statement (`order_items.order_id`
 * cascades). NEVER a `paid` one, which is the organisation's record of a sale.
 *
 * Both unpaid statuses are swept, because an order NEVER becomes `expired` on production: its
 * Connect endpoint does not subscribe to checkout.session.expired, so nothing ever moves a
 * page that was abandoned out of `pending`, and its personal data would be kept for ever. And
 * one clock does not fit both, so the rule is the same for `pending` and `expired`, and turns
 * on whether a payment intent is on record:
 *
 *   - with NO payment intent on record, once its page closed more than `prune.expired_order_days`
 *     (a week) ago: neither its own page's session event nor a settlement ever recorded one, and
 *     none can now (the page closes within 31 minutes, and Stripe redelivers a webhook for three
 *     days at most);
 *   - WITH an intent on record (written ahead of settlement by the checkout-session event of the
 *     page the app opened, or at settlement by the event that settles it), only once its page
 *     closed more than `prune.pending_with_payment_days` (30) ago. Something was paid, or at
 *     least attempted, for it, and a delayed debit that succeeded would have settled through
 *     payment_intent.succeeded, so an order still unpaid this long after is a payment to
 *     reconcile in Stripe, not a sale the office knows about. That holds for an `expired`
 *     order as well: a shopper who checks out again closes the earlier page
 *     (CartCheckoutService::markExpired, closePage) while a delayed debit from it can still be
 *     in flight. The sweep logs each order's number, payment intent and amount at WARNING as
 *     it deletes it (one WARNING per chunk of RECONCILE_CHUNK orders, none of them left off),
 *     so staff have something to reconcile against.
 *
 * Having an intent is not being paid: only `status` says that, and `paid` is never touched.
 *
 * Idempotent (a second run finds nothing), `--dry-run` deletes nothing, and it runs UNBOUND
 * across every organisation, as a console sweep does. It prints its counts and logs them
 * (`schedule:run` discards stdout).
 *
 * Scheduled daily in routes/console.php.
 */
class PruneCarts extends Command
{
    /** Orders read, deleted and listed in one WARNING: see sweepWithIntent(). */
    private const RECONCILE_CHUNK = 100;

    protected $signature = 'cart:prune {--dry-run : Count the baskets and orders that would go and delete nothing}';

    protected $description = 'Delete open baskets whose expiry is more than a day past (unless a payment page of theirs could still be paid), and unpaid orders (expired or pending) with no payment intent whose page closed more than a week ago, and unpaid orders with a payment intent whose page closed more than 30 days ago.';

    public function handle(): int
    {
        $graceDays = max(0, (int) config('cart.prune.grace_days', 1));
        $holdMinutes = max(0, (int) config('cart.prune.hold_minutes', 60));

        $expiredBefore = now()->subDays($graceDays);
        $payableSince = now()->subMinutes($holdMinutes);

        // withoutMasjidScope(): a console sweep spans organisations, and says so.
        $query = Cart::withoutMasjidScope()
            ->where('status', Cart::STATUS_OPEN)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $expiredBefore)
            ->whereNotExists(function ($orders) use ($payableSince): void {
                $orders->select(DB::raw('1'))
                    ->from('orders')
                    ->whereColumn('orders.cart_id', 'carts.id')
                    ->where('orders.status', Order::STATUS_PENDING)
                    ->where('orders.checkout_expires_at', '>', $payableSince);
            });

        $dryRun = (bool) $this->option('dry-run');
        $count = 0;

        $query->chunkById(200, function (Collection $carts) use ($dryRun, &$count): void {
            $count += $carts->count();

            if (! $dryRun) {
                // The FK cascade takes each basket's lines; orders.cart_id nulls out.
                Cart::withoutMasjidScope()->whereIn('id', $carts->modelKeys())->delete();
            }
        });

        $orderDays = max(1, (int) config('cart.prune.expired_order_days', 7));
        $lapsedBefore = now()->subDays($orderDays);

        // An `expired` order with NO payment intent on record: a payment page that was never
        // completed, and neither its own page's session event nor a settlement recorded a payment.
        $orderCount = $this->sweepWithoutIntent(Order::STATUS_EXPIRED, $lapsedBefore, $dryRun);

        // A `pending` order with no payment intent on record: nothing was ever paid or attempted
        // for it (an order never becomes `expired` on production, so it would otherwise stay).
        $bareCount = $this->sweepWithoutIntent(Order::STATUS_PENDING, $lapsedBefore, $dryRun);

        // An order of either status WITH an intent, long past: a payment to reconcile.
        $payingDays = max(7, (int) config('cart.prune.pending_with_payment_days', 30));
        $payingLapsedBefore = now()->subDays($payingDays);
        $withIntent = $this->sweepWithIntent($payingLapsedBefore, $payingDays, $dryRun);
        $payingCount = $withIntent[Order::STATUS_PENDING];
        $expiredPayingCount = $withIntent[Order::STATUS_EXPIRED];

        $verb = $dryRun ? 'Would prune' : 'Pruned';
        $this->info(
            "{$verb} {$count} open basket(s) that expired before {$expiredBefore->toDateTimeString()}"
            . " and {$orderCount} expired unpaid order(s) whose page closed before {$lapsedBefore->toDateTimeString()}."
        );
        $this->info(
            "{$verb} {$bareCount} pending order(s) with no payment whose page closed before {$lapsedBefore->toDateTimeString()}"
            . " and {$payingCount} pending order(s) with a payment intent whose page closed before {$payingLapsedBefore->toDateTimeString()}."
        );
        $this->info(
            "{$verb} {$expiredPayingCount} expired order(s) with a payment intent whose page closed before {$payingLapsedBefore->toDateTimeString()}."
        );

        // The scheduled run's only evidence: `schedule:run` discards stdout (routes/console.php,
        // the retention sweeps), as groups:purge-feed and registrations:reap-expired say. This
        // deletes personal data on a timer, so a night that found nothing (the cart never
        // switched on, a schedule that stopped) must look different from a night that ran.
        // Always emitted, zeros included.
        Log::info('Cart retention sweep completed.', [
            'dry_run' => $dryRun,
            'baskets_expired_before' => $expiredBefore->toIso8601String(),
            'baskets' => $count,
            'orders_page_closed_before' => $lapsedBefore->toIso8601String(),
            'orders' => $orderCount,
            'pending_orders_without_payment' => $bareCount,
            'pending_orders_with_payment_page_closed_before' => $payingLapsedBefore->toIso8601String(),
            'pending_orders_with_payment' => $payingCount,
            'expired_orders_with_payment' => $expiredPayingCount,
        ]);

        return self::SUCCESS;
    }

    /**
     * Delete the orders of one status that have NO payment intent on record and whose page
     * closed before the cut-off. Returns how many went (or, on a dry run, would).
     *
     * Only an order whose page has a closing time to age from. The delete names the status
     * and the missing intent again, so an order that settled, or that a payment event named,
     * between the read and the delete is never taken. The lines cascade
     * (`order_items.order_id`), in the same statement.
     */
    private function sweepWithoutIntent(string $status, Carbon $closedBefore, bool $dryRun): int
    {
        $count = 0;

        Order::withoutMasjidScope()
            ->where('status', $status)
            ->whereNull('stripe_payment_intent_id')
            ->whereNotNull('checkout_expires_at')
            ->where('checkout_expires_at', '<', $closedBefore)
            ->chunkById(200, function (Collection $orders) use ($status, $dryRun, &$count): void {
                $count += $dryRun
                    ? $orders->count()
                    : Order::withoutMasjidScope()
                        ->whereIn('id', $orders->modelKeys())
                        ->where('status', $status)
                        ->whereNull('stripe_payment_intent_id')
                        ->delete();
            });

        return $count;
    }

    /**
     * Delete the `pending` and the `expired` orders that carry a payment intent, once their
     * page closed before the cut-off, and log every one of them.
     *
     * An `expired` order can carry an intent too: the intent is recorded as soon as a session
     * event names the order, and a shopper who checks out again closes the earlier page
     * (CartCheckoutService::markExpired) while a delayed debit from it may still be in flight.
     * So both statuses wait `pending_with_payment_days`, not the shorter clock of an order
     * nothing was ever paid for.
     *
     * One by one, because the orders actually deleted are the ones the log must name, and
     * one WARNING per chunk of RECONCILE_CHUNK, listing every order of that chunk that went:
     * the rows are gone after this run, so these lines are all staff have to reconcile from,
     * and a cap on the list would lose the rest. Ids, amounts and Stripe references only: no
     * name, address or answer.
     *
     * @return array<string, int> orders deleted (or, on a dry run, that would be), by status
     */
    private function sweepWithIntent(Carbon $closedBefore, int $days, bool $dryRun): array
    {
        $counts = [Order::STATUS_PENDING => 0, Order::STATUS_EXPIRED => 0];

        Order::withoutMasjidScope()
            ->whereIn('status', array_keys($counts))
            ->whereNotNull('stripe_payment_intent_id')
            ->whereNotNull('checkout_expires_at')
            ->where('checkout_expires_at', '<', $closedBefore)
            ->chunkById(self::RECONCILE_CHUNK, function (Collection $orders) use ($closedBefore, $days, $dryRun, &$counts): void {
                $listed = [];

                foreach ($orders as $order) {
                    $gone = $dryRun
                        ? 1
                        : Order::withoutMasjidScope()
                            ->whereKey($order->id)
                            ->where('status', $order->status)
                            ->whereNotNull('stripe_payment_intent_id')
                            ->delete();

                    if ($gone !== 1) {
                        continue;
                    }

                    $counts[$order->status]++;
                    $listed[] = [
                        'order_number' => (string) $order->order_number,
                        'masjid_id' => (int) $order->masjid_id,
                        'payment_intent' => (string) $order->stripe_payment_intent_id,
                        'total_minor' => (int) $order->total_minor,
                        'currency' => (string) $order->currency,
                    ];
                }

                if ($listed === []) {
                    return;
                }

                $n = count($listed);

                Log::warning(
                    $dryRun
                        ? "cart:prune (dry run) would delete {$n} unpaid order(s) that carry a payment intent and whose page closed more than {$days} days ago. Reconcile them in Stripe first."
                        : "cart:prune deleted {$n} unpaid order(s) that carry a payment intent and whose page closed more than {$days} days ago. "
                            . 'A delayed debit that succeeded would have settled through payment_intent.succeeded, so these were never recorded as paid: reconcile each payment intent in Stripe.',
                    [
                        'dry_run' => $dryRun,
                        'orders' => $n,
                        'page_closed_before' => $closedBefore->toIso8601String(),
                        'listed' => $listed,
                    ]
                );
            });

        return $counts;
    }
}
