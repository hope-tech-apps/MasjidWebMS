<?php

namespace App\Console\Commands;

use App\Models\Cart;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 * alone. Idempotent (a second run finds nothing), `--dry-run` deletes nothing, and it runs
 * UNBOUND across every organisation, as a console sweep does.
 *
 * Scheduled daily in routes/console.php.
 */
class PruneCarts extends Command
{
    protected $signature = 'cart:prune {--dry-run : Count the baskets that would go and delete nothing}';

    protected $description = 'Delete open baskets whose expiry is more than a day past, unless a payment page of theirs could still be paid.';

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

        $verb = $dryRun ? 'Would prune' : 'Pruned';
        $this->info("{$verb} {$count} open basket(s) that expired before {$expiredBefore->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
