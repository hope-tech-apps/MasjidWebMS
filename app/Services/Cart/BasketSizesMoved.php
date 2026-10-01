<?php

namespace App\Services\Cart;

use RuntimeException;

/**
 * Internal to CartCheckoutService::checkout(): the priced basket named a product size the checkout
 * had not locked before pricing (a line added between the pre-transaction read and the cart lock).
 * The transaction rolls back having written nothing and the checkout runs once more; it never
 * reaches a caller.
 */
final class BasketSizesMoved extends RuntimeException
{
}
