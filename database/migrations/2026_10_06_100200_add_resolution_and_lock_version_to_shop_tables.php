<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two small additions to the shop's tables (shop slice B2, the critic's fix round). B1's migration
 * (2026_10_06_100000) is in production and is not edited; this one adds to it. Blueprint only, so no
 * driver guard is needed, and every name is written by hand under MySQL's 64 characters.
 *
 * product_sales.resolution / resolved_at / resolved_by_user_id: WHAT THE OFFICE DID ABOUT A SALE.
 *   An oversold sale (paid after the last unit had gone) was only ever flagged: the banner never
 *   went away, because nothing recorded that somebody had dealt with it. `resolution` is one of
 *   `refunded` (the office refunded it in Stripe, so there is nothing to hand out) or
 *   `substituted` (a replacement item is handed over, so the sale stays to hand out but needs no
 *   one's call any more); NULL is "nobody has decided". `resolved_by_user_id` names the login that set
 *   the CURRENT resolution; it nulls on delete, as `collected_by_user_id` does, so retiring a staff
 *   login keeps the sale. No buyer data and no free text: nothing here needs the staging scrub.
 *   Not indexed: the pickup list reads a handful of rows an organisation sold, by its own filters.
 *
 * products.lock_version: A STALE EDITOR IS REFUSED. An admin screen holds a product (and its sizes) for
 *   as long as it is open, and a save rewrites the sizes as a LIST, so a save from a stale screen
 *   would put back what another editor had just changed. Every change to what the editor shows
 *   (a save, a picture added, removed or moved) adds one under the product's row lock, and a
 *   save names the version it read; a different one is refused with a 409 and writes nothing. The
 *   Studio draft's `lock_version` is the precedent. Existing rows start at 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_sales', function (Blueprint $table) {
            $table->string('resolution', 16)->nullable()->after('oversold');
            $table->timestamp('resolved_at')->nullable()->after('resolution');
            $table->unsignedBigInteger('resolved_by_user_id')->nullable()->after('resolved_at');

            $table->foreign('resolved_by_user_id', 'product_sales_resolved_by_foreign')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(0)->after('sort');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });

        Schema::table('product_sales', function (Blueprint $table) {
            $table->dropForeign('product_sales_resolved_by_foreign');
            $table->dropColumn(['resolution', 'resolved_at', 'resolved_by_user_id']);
        });
    }
};
