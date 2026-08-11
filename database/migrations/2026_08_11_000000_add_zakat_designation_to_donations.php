<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zakat designation on a gift (T-031).
 *
 * ## Why this is a COLUMN and not `funds.type === 'zakat'`
 *
 * `funds.type` already carries a `zakat` value, so the cheap move would be to
 * derive the designation from the fund. That is wrong for how zakat is actually
 * accounted for, in both directions:
 *
 *   - **Zakat given to a non-zakat fund.** Most small masjids run ONE fund
 *     ("General"). A donor still says "this $500 is my zakat". Deriving from the
 *     fund would record that gift as unrestricted, and the org would spend
 *     restricted money on the electricity bill.
 *   - **Non-zakat gifts landing in a zakat fund.** A donor gives sadaqah toward
 *     the relief appeal the org labelled a zakat fund; an imported ledger row
 *     lands in the zakat bucket because of its name. Counting those as zakat
 *     overstates the restricted pot the org must disburse to eligible recipients.
 *
 * A fund is the ORG's bucket; the designation is the DONOR's restriction on one
 * gift. They are different facts and only one of them is per-gift, so zakat gets
 * its own column and the fund's type becomes its DEFAULT rather than its
 * definition.
 *
 *   - `is_zakat`     — the gift is zakat, and the org must treat it as restricted.
 *   - `zakat_source` — HOW that came to be true, so a treasurer reconciling the
 *     restricted pot can tell a donor's own declaration from an inference the
 *     platform made off the fund. Non-null only when `is_zakat` is true; there is
 *     nothing to attribute about a gift that is not zakat.
 *
 * The column is written when the pending row is persisted — BEFORE the Stripe
 * redirect — so the designation is already on the row the webhook later advances
 * and never depends on the browser coming back (.claude/rules/stripe-payments.md).
 *
 * `donation_subscriptions` carries the same pair because a standing monthly gift
 * is designated once, at checkout, and every invoice it books must inherit that
 * designation rather than re-deriving it from the fund years later.
 *
 * Both columns are pure Blueprint (no raw SQL), so nothing here needs a driver
 * guard — see .claude/rules/migrations.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->boolean('is_zakat')->default(false)->after('type');
            $table->string('zakat_source', 20)->nullable()->after('is_zakat');

            // The giving dashboard's zakat total is "this masjid's zakat gifts",
            // which is exactly this pair.
            $table->index(['masjid_id', 'is_zakat']);
        });

        Schema::table('donation_subscriptions', function (Blueprint $table) {
            $table->boolean('is_zakat')->default(false)->after('donor_covers_fees');
            $table->string('zakat_source', 20)->nullable()->after('is_zakat');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropIndex(['masjid_id', 'is_zakat']);
            $table->dropColumn(['is_zakat', 'zakat_source']);
        });

        Schema::table('donation_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['is_zakat', 'zakat_source']);
        });
    }
};
