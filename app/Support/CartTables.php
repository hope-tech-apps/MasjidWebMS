<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whether the universal cart's tables exist yet, for the code that runs whether or not the
 * cart is switched on.
 *
 * bin/deploy makes the new code live BEFORE it runs `php artisan migrate`, and if migrate then
 * fails the deploy stops with the new code serving and the old schema in place. For that window
 * (seconds, or until someone notices) the paths that reach the cart's tables would answer 500:
 * the form registrations' refund arm (`whereNotExists` over order_items), the cart's own refund
 * arm, a member's "Delete account", an admin's contact merge (`orders.contact_id`), the undo of a
 * Wix contact import, and the daily `cart:prune`. Each asks first and skips what is not there.
 *
 * TWO QUESTIONS, because a check that cannot be answered means opposite things to different callers:
 *
 *  - `has()` FAILS SAFE. If the question itself throws (a database that cannot answer
 *    information_schema), it says "absent" and logs one warning. Use it only where absent is
 *    harmless: the live form refund arm, which then behaves exactly as it did before the cart, and
 *    the prune, which then skips a night. A payment webhook must not turn into a 500 that Stripe
 *    retries and in the end gives up on because the cart, which that path does not need, could not
 *    be looked up.
 *
 *  - `existsOrFail()` FAILS CLOSED. It says false ONLY for a table that genuinely does not exist,
 *    and it lets a failed check propagate. Use it on any path that deletes or moves data: a false
 *    "absent" there would skip the look at `orders`, and a member holding paid orders (the
 *    organisation's records) would be erased, a merge would leave its source's orders to be nulled
 *    by the foreign key, and an import's undo would delete a contact orders still name. On those
 *    paths the caller's transaction rolls back and the operator sees an error, which is the right
 *    answer to "I could not tell". CartPaymentService::handleChargeFlag also asks the strict question,
 *    but inside its own catch: the failure is logged at error and the webhook still answers.
 *
 * The rule, in one line: only a genuinely missing table skips; a failed check fails closed on any
 * path that deletes or moves data.
 *
 * MEMOISED PER PROCESS, so it costs one `hasTable` per table, not one per request-step. A table
 * that exists is remembered for good (tables are never dropped in a running deploy). A table that
 * is MISSING is asked again after `RECHECK_SECONDS`, so a long-lived worker (a queue worker,
 * Octane) that saw the window does not go on believing in it after migrate has finished; a
 * PHP-FPM request is a fresh process and asks once. A FAILED check is never remembered: the next
 * call asks again. `existsOrFail()` does not trust a remembered absence at all, because it guards
 * paths that delete data and an absence can be stale; it asks again, which costs one statement on
 * a path that runs rarely.
 */
final class CartTables
{
    /** The tables migrate creates for the cart, in the order they are dropped. */
    public const NAMES = ['cart_items', 'order_items', 'orders', 'carts'];

    private const RECHECK_SECONDS = 30;

    /** @var array<string, array{0: bool, 1: int}> table => [exists, when it was asked] */
    private static array $seen = [];

    /** Whether `has()` has already logged that its question failed, so a broken database logs once. */
    private static bool $warned = false;

    /**
     * Does the table exist? A question that cannot be answered is answered "no", once logged.
     * For a path where the cart is irrelevant when its tables are absent; NEVER for one that
     * deletes or moves data (see existsOrFail()).
     */
    public static function has(string $table): bool
    {
        try {
            return self::ask($table, trustAbsence: true);
        } catch (Throwable $e) {
            if (! self::$warned) {
                self::$warned = true;

                // The class only: a driver's message can carry the host or the user. Once per
                // process, because a database that cannot answer fails every call the same way.
                Log::warning('The cart tables could not be looked up; treating them as absent, so this path behaves as it did before the cart.', [
                    'table' => $table,
                    'exception' => $e::class,
                ]);
            }

            return false;
        }
    }

    /**
     * Does the table exist? False only when it genuinely does not; if the check itself throws,
     * that propagates. For any path that deletes or moves data.
     */
    public static function existsOrFail(string $table): bool
    {
        return self::ask($table, trustAbsence: false);
    }

    private static function ask(string $table, bool $trustAbsence): bool
    {
        $known = self::$seen[$table] ?? null;

        if ($known !== null && ($known[0] || ($trustAbsence && now()->getTimestamp() - $known[1] < self::RECHECK_SECONDS))) {
            return $known[0];
        }

        // Nothing is stored unless this returns, so a failure is never remembered.
        $exists = Schema::hasTable($table);
        self::$seen[$table] = [$exists, now()->getTimestamp()];

        return $exists;
    }

    /** Forget what was seen: for a test that drops or creates the tables, and for nothing else. */
    public static function forget(): void
    {
        self::$seen = [];
        self::$warned = false;
    }
}
