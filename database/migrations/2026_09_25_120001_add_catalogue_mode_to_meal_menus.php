<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A standing CATALOGUE mode for the meal-ordering module (MEC's Halal Kitchen;
 * owner, 2026-09-21: "Build ordering in Manara" — "Pickup at MEC, 48h, office
 * confirms").
 *
 * A Jummah-lunch menu is dated: one Friday, one cutoff, everyone collects after
 * the prayer. A catering catalogue has no service date at all — the customer
 * chooses when to collect, at least `pickup_lead_hours` ahead, and the office
 * confirms each order. It rides on the same tables so the order, pricing, Stripe
 * and board paths are the ones already proven in production rather than a copy.
 *
 *  - `kind` is `dated` (every existing row, and the default) or `catalogue`
 *    (MealMenu::KINDS). A string, never an enum. Every dated-only reader filters
 *    on it (MealMenu::scopeDated), so a catalogue can never be mistaken for this
 *    Friday's lunch.
 *  - `pickup_lead_hours`: how far ahead a pickup must be booked. NULL on dated
 *    menus, where the cutoff does that job.
 *  - `notify_emails`: who in the office hears about a new catalogue order, as
 *    typed ("office@…, kitchen@…"). NULL falls back to the organisation's own
 *    address (KitchenOrderNotifier), so an order can never notify nobody. `text`
 *    for the reason every admin-typed list is; nulled on staging.
 *  - `service_date` becomes NULLABLE: a catalogue has none. The unique
 *    (masjid_id, service_date) index stays exactly as it is and still means "one
 *    menu per Friday", because NULLs never collide in a unique index on either
 *    driver. The change is its own statement, after the adds, because SQLite
 *    rebuilds the table for a column change. meal_menus carries no partial index
 *    for that rebuild to lose (grep "CREATE UNIQUE INDEX" database/migrations).
 *
 * Behaviour-neutral for every existing row: all of them read as `dated`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_menus', function (Blueprint $table) {
            $table->string('kind', 16)->default('dated');
            $table->unsignedSmallInteger('pickup_lead_hours')->nullable();
            $table->text('notify_emails')->nullable();
        });

        Schema::table('meal_menus', function (Blueprint $table) {
            $table->date('service_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversible while a catalogue (service_date NULL) exists: restoring
        // NOT NULL would fail on it. Dropping the catalogue columns is safe.
        Schema::table('meal_menus', function (Blueprint $table) {
            $table->dropColumn(['kind', 'pickup_lead_hours', 'notify_emails']);
        });
    }
};
