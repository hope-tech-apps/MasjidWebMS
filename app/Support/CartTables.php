<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Whether the universal cart's tables exist yet, for the code that runs whether or not the
 * cart is switched on.
 *
 * bin/deploy makes the new code live BEFORE it runs `php artisan migrate`, and if migrate then
 * fails the deploy stops with the new code serving and the old schema in place. For that window
 * (seconds, or until someone notices) three live paths would query tables that do not exist and
 * answer 500: the form registrations' refund arm (`whereNotExists` over order_items), the
 * cart's own refund arm, and a member's "Delete account" (carts and orders). None of them
 * has anything to do with the cart when the tables are absent, so each asks first and skips.
 *
 * MEMOISED PER PROCESS, so it costs one `hasTable` per table, not one per request-step. A table
 * that exists is remembered for good (tables are never dropped in a running deploy). A table that
 * is MISSING is asked again after `RECHECK_SECONDS`, so a long-lived worker (a queue worker,
 * Octane) that saw the window does not go on believing in it after migrate has finished; a
 * PHP-FPM request is a fresh process and asks once.
 */
final class CartTables
{
    /** The tables migrate creates for the cart, in the order they are dropped. */
    public const NAMES = ['cart_items', 'order_items', 'orders', 'carts'];

    private const RECHECK_SECONDS = 30;

    /** @var array<string, array{0: bool, 1: int}> table => [exists, when it was asked] */
    private static array $seen = [];

    public static function has(string $table): bool
    {
        $known = self::$seen[$table] ?? null;

        if ($known !== null && ($known[0] || now()->getTimestamp() - $known[1] < self::RECHECK_SECONDS)) {
            return $known[0];
        }

        $exists = Schema::hasTable($table);
        self::$seen[$table] = [$exists, now()->getTimestamp()];

        return $exists;
    }

    /** Forget what was seen: for a test that drops or creates the tables, and for nothing else. */
    public static function forget(): void
    {
        self::$seen = [];
    }
}
