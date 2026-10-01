<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The online shop (shop slice B1; DECISIONS.md 2026-09-30): products, their size variants, and
 * the record a paid basket line leaves. All money is integer minor units. The shop ships DARK
 * behind the `shop` capability, so these three tables are empty everywhere until a SuperAdmin
 * grants it.
 *
 * products — WHAT IS FOR SALE. `base_price_minor` is the price of any variant that sets none of
 *   its own. `slug` is unique per organisation among LIVE rows only (see below). `active` is the
 *   owner's "on the shelf" switch; a deleted product is soft-deleted, never removed, because a
 *   paid line names it. Images are Spatie media (collection `product_images`, many), not columns.
 *
 * product_variants — A SIZE (OR OPTION) OF A PRODUCT, AND ITS STOCK. `label` is what the buyer
 *   picks ("YS", "Adult L"), unique per product among live rows. `price_minor` NULL means "the
 *   product's base price". `stock` NULL means UNLIMITED; otherwise it is the number of units
 *   ever put on sale, and `sold_count` is how many PAID lines have taken. What can still be
 *   bought is stock - sold_count - what other pending baskets hold (CartCheckoutService), and
 *   there is no hold table: a hold is a pending order's own lines, read from `order_items`.
 *   `sold_count` moves only at settlement, under the variant's row lock.
 *
 * product_sales — THE RECORD A PAID BASKET LINE CREATES, as form_responses, meal_orders and
 *   donations are for their line types (CartSettlementService::settleProduct). It is written from
 *   the order item's frozen `price_snapshot`, so the product name, the size and the price are
 *   what the buyer PAID, whatever the catalogue says now.
 *
 *   - It holds NO buyer personal data. The pickup list reads the buyer from the order
 *     (orders.buyer_name / buyer_email / buyer_phone), which MemberAccountDeletion and the
 *     staging scrub already classify. So this table has no contact column and needs no
 *     classification of its own (MemberAccountDeletionCoverageTest and StagingScrubCoverageTest
 *     are unchanged by it).
 *   - `order_id` and `order_item_id` are RESTRICT: a paid order is never pruned (cart:prune only
 *     deletes unpaid ones), and nothing may delete the line a sale is written from.
 *     `order_item_id` is UNIQUE, so a replayed settlement cannot write a line's sale twice.
 *   - `product_id` and `variant_id` have NO foreign key. The variant (or its product) may be
 *     soft-deleted, even removed outright, after it sold; the snapshot columns are the truth,
 *     and a key that blocked removing a product would make the sale an obstacle. They are plain
 *     indexed ids for the pickup list.
 *   - `oversold` flags a line that was paid after the last unit had gone (only possible through
 *     a webhook later than the hold's grace). It is RECORDED, never refused and never
 *     auto-refunded: money taken is a record, and the refund is a person's call.
 *   - `collected_at` / `collected_by_user_id` are the pickup list's "handed over" mark (slice B2).
 *     The user key nulls on delete: retiring a staff login keeps the sale.
 *
 * ## Uniqueness among LIVE rows, spelled once per driver
 *
 * A slug and a size label must be unique only among rows that still exist. `unique(col,
 * deleted_at)` enforces nothing (NULLs never collide), so, per .claude/rules/migrations.md:
 *
 *   - SQLite (the suite) has partial indexes: `... WHERE deleted_at IS NULL`.
 *   - MySQL (production, 8.4) has none, so a generated column collapses a trashed row to NULL
 *     (`live_slug`, `live_label`) and a plain UNIQUE index covers it; NULLs are distinct in a
 *     unique index, so exactly the same rows are constrained as under SQLite.
 *
 * The generated columns are VIRTUAL, as `masjids.active_owner_user_id` and `masjid_user.
 * default_key` are, and for the reason their migrations record: an ALTER that adds a STORED
 * column forces a table rebuild, which MySQL refused ("1215 Cannot add foreign key constraint")
 * on a table with foreign keys on 2026-08-11, while a VIRTUAL column is metadata only and a
 * unique secondary index on one is supported by InnoDB 8.0+. (That rules file's line saying
 * the column must be STORED is the one the shipped migrations disagree with; they were run
 * against the production engine, this one has not been.) The two columns exist on MySQL and
 * NOT on SQLite, a real schema divergence: both are in the models' `$hidden` so a serialised
 * product is identical on both drivers, neither is fillable, and config/staging_scrub.php lists
 * both under `never_write`.
 *
 * Every index is named by hand: MySQL caps an identifier at 64 characters and SQLite does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->string('category', 60)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('base_price_minor');
            $table->char('currency', 3)->default('usd');
            $table->boolean('active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['masjid_id', 'active', 'sort'], 'products_tenant_active_sort_index');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('price_minor')->nullable();
            $table->unsignedInteger('stock')->nullable();
            $table->unsignedInteger('sold_count')->default(0);
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        $this->addUniquenessAmongLiveRows();

        Schema::create('product_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();

            // Plain ids, no foreign key: see the docblock.
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id');

            // Snapshots of products.name (120) and product_variants.label (40), at the width of
            // their sources, so a value that fitted there fits here.
            $table->string('product_name', 120);
            $table->string('variant_label', 40);

            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_minor');
            $table->unsignedBigInteger('total_minor');
            $table->boolean('oversold')->default(false);

            $table->timestamp('collected_at')->nullable();
            $table->foreignId('collected_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique('order_item_id', 'product_sales_order_item_unique');
            $table->index(['masjid_id', 'product_id', 'variant_id'], 'product_sales_tenant_product_variant_index');
        });
    }

    /**
     * Refuses while a sale exists: product_sales is the record of money taken, and a rollback
     * that dropped it would erase what an organisation sold. Nothing else here holds money, so
     * with no sale the three tables go (the generated columns and every index with them, on
     * either driver, so no driver branch is needed).
     */
    public function down(): void
    {
        $sales = DB::table('product_sales')->count();

        if ($sales > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$sales} product sale(s) exist. They are the record of paid shop orders; export them first."
            );
        }

        Schema::dropIfExists('product_sales');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }

    /**
     * UNIQUE (masjid_id, slug) and UNIQUE (product_id, label), each only WHERE deleted_at IS NULL.
     * Created on empty tables, before any row exists, so no pre-flight for duplicates is needed.
     */
    private function addUniquenessAmongLiveRows(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE products ADD COLUMN live_slug VARCHAR(140)'
                . ' GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN slug ELSE NULL END) VIRTUAL'
            );
            DB::statement('CREATE UNIQUE INDEX products_live_slug_unique ON products (masjid_id, live_slug)');

            DB::statement(
                'ALTER TABLE product_variants ADD COLUMN live_label VARCHAR(40)'
                . ' GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN label ELSE NULL END) VIRTUAL'
            );
            DB::statement('CREATE UNIQUE INDEX product_variants_live_label_unique ON product_variants (product_id, live_label)');

            return;
        }

        DB::statement('CREATE UNIQUE INDEX products_live_slug_unique ON products (masjid_id, slug) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX product_variants_live_label_unique ON product_variants (product_id, label) WHERE deleted_at IS NULL');
    }
};
