<?php

/*
|--------------------------------------------------------------------------
| The row locks a checkout takes, read from the engine itself
|--------------------------------------------------------------------------
|
| Under REPEATABLE READ a locking range read over a non-unique index takes next-key and gap locks
| even when it matches nothing. A checkout that read its basket's lines FOR UPDATE therefore made
| other baskets' line adds wait for its whole transaction, Stripe calls included (the B1 ship
| critic, 2026-10-01). The checkout now finds its sizes before its transaction and locks them by
| primary key. This reads performance_schema.data_locks at the moment Stripe is asked for the page,
| inside the checkout's transaction, and compares it with the locks held just before the checkout
| began: an ordinary basket must have taken no lock on `cart_items` at all, and a shop basket no
| lock on `cart_items` and nothing on `product_variants` but primary-key record locks (no gap, no
| secondary index, no supremum). SQLite has no row locks to show.
|
| The size's own record lock is NOT visible here: RefreshDatabase runs the whole test in one
| transaction, so the size row was inserted by the very transaction that locks it, and InnoDB lets
| a transaction's implicit lock on its own fresh row stand in for an explicit one. That the lock
| makes a competing checkout WAIT is shown with two real connections on staging (ASSUMPTIONS S-2).
*/

use App\Models\Cart;
use App\Models\CartItem;
use App\Services\Cart\CartCheckoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;
use Tests\Feature\Cart\BuildsBaskets;
use Tests\Feature\Cart\SignsCartWebhooks;
use Tests\Feature\Shop\BuildsShop;

uses(BuildsBaskets::class, BuildsShop::class, SignsCartWebhooks::class);

/** This connection's transaction's record locks, as `table|index|mode|data`. */
function shopCheckoutRecordLocks(): array
{
    return collect(DB::select(
        "SELECT OBJECT_NAME AS t, INDEX_NAME AS i, LOCK_MODE AS m, LOCK_DATA AS d FROM performance_schema.data_locks
         WHERE LOCK_TYPE = 'RECORD'
           AND ENGINE_TRANSACTION_ID = (SELECT trx_id FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID())"
    ))->map(fn ($r): string => "{$r->t}|{$r->i}|{$r->m}|{$r->d}")->all();
}

/** The record locks a checkout of `$cart` added, read while Stripe is being asked for its page. */
function shopCheckoutAddedLocks(Cart $cart): array
{
    $before = shopCheckoutRecordLocks();

    $service = new class(new StripeClient('sk_test_offline')) extends CartCheckoutService {
        public ?array $during = null;

        protected function createCheckoutSession(array $params, string $connectedAccountId, string $idempotencyKey): array
        {
            $this->during = shopCheckoutRecordLocks();

            return ['id' => 'cs_locks_' . Str::lower(Str::random(12)), 'url' => 'https://checkout.stripe.test/locks', 'payment_intent' => null];
        }

        protected function retrieveCheckoutSession(string $sessionId, string $connectedAccountId): array
        {
            return ['status' => 'open', 'url' => 'https://checkout.stripe.test/existing'];
        }

        protected function expireCheckoutSession(string $sessionId, string $connectedAccountId): void
        {
        }
    };

    $service->checkout($cart, 'https://mec.manara.hopetechapps.com/basket', 'locks@example.org');

    expect($service->during)->not->toBeNull();

    return array_values(array_diff($service->during, $before));
}

it('takes no lock on any basket line in an ordinary checkout', function () {
    $org = $this->shopOrg();
    $cart = $this->cart($org);
    $this->add($cart, CartItem::TYPE_DONATION, $this->fund($org)->id, 5000);

    $onLines = array_filter(shopCheckoutAddedLocks($cart), fn (string $lock): bool => str_starts_with($lock, 'cart_items|'));

    expect($onLines)->toBe([]);
});

it('locks nothing on a shop basket\'s lines and nothing on its sizes but primary-key records', function () {
    $org = $this->shopOrg();
    $variant = $this->sizeOf($org, ['stock' => 5]);
    $cart = $this->cart($org);
    $this->addVariant($cart, $variant, 1);

    $added = shopCheckoutAddedLocks($cart);
    $onLines = array_filter($added, fn (string $lock): bool => str_starts_with($lock, 'cart_items|'));
    $onSizes = array_values(array_filter($added, fn (string $lock): bool => str_starts_with($lock, 'product_variants|')));

    expect($onLines)->toBe([]);

    foreach ($onSizes as $lock) {
        expect($lock)->toStartWith('product_variants|PRIMARY|X,REC_NOT_GAP|');
    }
});
