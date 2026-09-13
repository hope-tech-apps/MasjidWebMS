<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-entered registrations (T-041i): a sign-up an administrator records at
 * the desk, over the phone, or for a family with no email address.
 *
 * WHY THESE THREE COLUMNS EXIST AT ALL, since the ratified design deliberately
 * had no admin create path and the offline-donation precedent stores only a
 * `source` string:
 *
 *  - `source` says which DOOR a registration came through ('public' for the
 *    unauthenticated /api/v1 endpoints, 'staff' for the roster screen). Every
 *    existing row came through the public door, which is what the default
 *    records. Same shape and same reasoning as `meal_orders.source`
 *    (add_staff_entry_to_meal_orders, 2026-09-10).
 *
 *  - `entered_by_user_id` names the administrator. This one is NOT decoration
 *    and it is the reason a staff create path is allowed to exist at all.
 *    `RegistrationService::writeRosterMemberships()` writes a GUARDIAN EDGE
 *    from the payer over every registrant, and its class docblock is explicit
 *    that the door assembling the registrant list owns proving that claim:
 *    "Any FUTURE caller that resolves registrants differently — an admin
 *    enrolment screen, an importer — is asserting that its own act carries that
 *    authority, and had better be authenticated." An assertion of authority
 *    that records no author is unfalsifiable; this column is what turns "an
 *    admin said so" into a name the office can go and ask.
 *
 *  - `staff_note` is why it was entered by hand ("paid cash at the desk",
 *    "phoned in, no email"). It is free text an administrator typed, never a
 *    money claim — see the model docblock and RegistrationsController::store.
 *
 * NONE OF THE THREE CLAIMS ANYTHING ABOUT MONEY. A hand-entered registration on
 * a paid plan is created `pending / awaiting` exactly like a public one; only a
 * signature-verified Stripe webhook, the free-path carve-out, or a 100% waiver
 * routed through `confirm()` ever advances it. There is deliberately no
 * `paid_via` here, unlike `meal_orders`: a meal order's money is a $12 cash box
 * an admin reconciles the same afternoon, while a registration's money is a
 * Stripe Checkout Session on the organisation's connected account, and a column
 * saying "cash" beside a `payment_status` Stripe never set is the exact
 * disagreement .claude/rules/stripe-payments.md exists to prevent.
 *
 * A PLAIN INDEXED COLUMN, not `->constrained()`: adding a foreign key to an
 * existing table rebuilds it on SQLite and can silently drop the WHERE-clause
 * unique indexes this schema relies on (.claude/rules/migrations.md), which is
 * why `add_staff_entry_to_meal_orders` took the same shape. Blueprint only, so
 * MySQL and SQLite emit the same schema and no driver guard is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->string('source', 16)->default('public')->after('payment_status');
            $table->unsignedBigInteger('entered_by_user_id')->nullable()->after('source');
            $table->string('staff_note', 500)->nullable()->after('entered_by_user_id');
            $table->index('entered_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropIndex(['entered_by_user_id']);
            $table->dropColumn(['source', 'entered_by_user_id', 'staff_note']);
        });
    }
};
