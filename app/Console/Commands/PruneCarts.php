<?php

namespace App\Console\Commands;

use App\Models\Cart;
use App\Models\Order;
use Illuminate\Console\Command;
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
 * `orders.buyer_name`, `buyer_phone` and `buyer_email` the shopper's own details. An order
 * whose status is `expired` is a payment page that was never completed, so once its page
 * closed more than `prune.expired_order_days` (a week) ago the order goes too, and its lines
 * with it in the same statement (`order_items.order_id` cascades). By then no payment can
 * arrive for it: the page closes within 31 minutes, and Stripe redelivers a webhook for three
 * days at most. NEVER a `paid` one, which is the organisation's record of a sale.
 *
 * A `pending` order goes too, because an order NEVER becomes `expired` on production: its
 * Connect endpoint does not subscribe to checkout.session.expired, so nothing ever moves a
 * page that was abandoned out of `pending`, and its personal data would be kept for ever.
 *
 *   - with NO payment intent on record, once its page closed more than `prune.expired_order_days`
 *     ago: no payment event ever named it, and none can now;
 *   - WITH an intent on record (recorded by the first session or payment-intent event that named
 *     the order, whether or not settlement then succeeded), only once its page closed more than
 *     `prune.pending_with_payment_days` (30) ago. Something was paid, or at least attempted, for
 *     it, and a delayed debit that succeeded would have settled through payment_intent.succeeded,
 *     so an order still pending this long after is a payment to reconcile in Stripe, not a sale
 *     the office knows about. The sweep logs the count AND each order's number, payment intent
 *     and amount at WARNING before the rows go, so staff have something to reconcile against.
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
    protected $signature = 'cart:prune {--dry-run : Count the baskets and orders that would go and delete nothing}';

    protected $description = 'Delete open baskets whose expiry is more than a day past (unless a payment page of theirs could still be paid), and unpaid orders (expired, or pending with no payment) whose page closed more than a week ago, and pending orders with a payment intent whose page closed more than 30 days ago.';

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
        $orderCount = 0;

        // Only `expired`, and only one whose page has a closing time to age from. The delete
        // names the status again, so an order that settled between the read and the delete is
        // never taken. The lines cascade (`order_items.order_id`), in the same statement.
        Order::withoutMasjidScope()
            ->where('status', Order::STATUS_EXPIRED)
            ->whereNotNull('checkout_expires_at')
            ->where('checkout_expires_at', '<', $lapsedBefore)
            ->chunkById(200, function (Collection $orders) use ($dryRun, &$orderCount): void {
                $orderCount += $dryRun
                    ? $orders->count()
                    : Order::withoutMasjidScope()
                        ->whereIn('id', $orders->modelKeys())
                        ->where('status', Order::STATUS_EXPIRED)
                        ->delete();
            });

        // A `pending` order with no payment intent on record: nothing was ever paid or attempted
        // for it (an order never becomes `expired` on production, so it would otherwise stay).
        // The delete names the status and the missing intent again, so an order that a payment
        // event named, or that settled, between the read and the delete is never taken.
        $bareCount = 0;

        Order::withoutMasjidScope()
            ->where('status', Order::STATUS_PENDING)
            ->whereNull('stripe_payment_intent_id')
            ->whereNotNull('checkout_expires_at')
            ->where('checkout_expires_at', '<', $lapsedBefore)
            ->chunkById(200, function (Collection $orders) use ($dryRun, &$bareCount): void {
                $bareCount += $dryRun
                    ? $orders->count()
                    : Order::withoutMasjidScope()
                        ->whereIn('id', $orders->modelKeys())
                        ->where('status', Order::STATUS_PENDING)
                        ->whereNull('stripe_payment_intent_id')
                        ->delete();
            });

        // A `pending` order WITH an intent, long past: a payment to reconcile. One by one, because
        // the ones actually deleted are the ones the log must name.
        $payingDays = max(7, (int) config('cart.prune.pending_with_payment_days', 30));
        $payingLapsedBefore = now()->subDays($payingDays);
        $payingCount = 0;
        $toReconcile = [];

        Order::withoutMasjidScope()
            ->where('status', Order::STATUS_PENDING)
            ->whereNotNull('stripe_payment_intent_id')
            ->whereNotNull('checkout_expires_at')
            ->where('checkout_expires_at', '<', $payingLapsedBefore)
            ->chunkById(200, function (Collection $orders) use ($dryRun, &$payingCount, &$toReconcile): void {
                foreach ($orders as $order) {
                    $gone = $dryRun
                        ? 1
                        : Order::withoutMasjidScope()
                            ->whereKey($order->id)
                            ->where('status', Order::STATUS_PENDING)
                            ->whereNotNull('stripe_payment_intent_id')
                            ->delete();

                    if ($gone !== 1) {
                        continue;
                    }

                    $payingCount++;
                    $toReconcile[] = [
                        'order_number' => (string) $order->order_number,
                        'masjid_id' => (int) $order->masjid_id,
                        'payment_intent' => (string) $order->stripe_payment_intent_id,
                        'total_minor' => (int) $order->total_minor,
                        'currency' => (string) $order->currency,
                    ];
                }
            });

        // Ids, amounts and Stripe references only: no name, address or answer. The rows are gone
        // after this run, so this line is the only list staff will have to reconcile from.
        if ($payingCount > 0) {
            Log::warning(
                $dryRun
                    ? "cart:prune (dry run) would delete {$payingCount} pending order(s) that carry a payment intent and whose page closed more than {$payingDays} days ago. Reconcile them in Stripe first."
                    : "cart:prune deleted {$payingCount} pending order(s) that carry a payment intent and whose page closed more than {$payingDays} days ago. "
                        . 'A delayed debit that succeeded would have settled through payment_intent.succeeded, so these were never recorded as paid: reconcile each payment intent in Stripe.',
                [
                    'dry_run' => $dryRun,
                    'orders' => $payingCount,
                    'page_closed_before' => $payingLapsedBefore->toIso8601String(),
                    'listed' => array_slice($toReconcile, 0, 100),
                ]
            );
        }

        $verb = $dryRun ? 'Would prune' : 'Pruned';
        $this->info(
            "{$verb} {$count} open basket(s) that expired before {$expiredBefore->toDateTimeString()}"
            . " and {$orderCount} expired unpaid order(s) whose page closed before {$lapsedBefore->toDateTimeString()}."
        );
        $this->info(
            "{$verb} {$bareCount} pending order(s) with no payment whose page closed before {$lapsedBefore->toDateTimeString()}"
            . " and {$payingCount} pending order(s) with a payment intent whose page closed before {$payingLapsedBefore->toDateTimeString()}."
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
        ]);

        return self::SUCCESS;
    }
}
