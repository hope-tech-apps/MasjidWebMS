<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an email suppression was before a person's opt-out replaced it.
 *
 * An import's hold for want of consent (`not_opted_in`, `order_history_import`,
 * EmailSuppression::STAFF_LIFTABLE_REASONS) is one staff may lift. When a real
 * opt-out lands on such a row — an unsubscribe link, or a Wix unsubscribe,
 * complaint or bounce the contact import reads later — the row's reason
 * becomes the stricter one, so staff can no longer lift it
 * (EmailSuppressionService::suppress()). The row keeps what it was:
 *
 *  - `held_reason` — the staff-liftable reason the row carried before;
 *  - `held_since` — that hold's `suppressed_at`. The row's own
 *    `suppressed_at` moves to the date of the opt-out, because a row that
 *    says "unsubscribed on the day the order import ran" would be untrue.
 *
 * Additive and nullable: every existing row reads as before, and both stay
 * null on a row that was never a hold. Blueprint only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_suppressions', function (Blueprint $table) {
            $table->string('held_reason', 32)->nullable()->after('released_by_user_id');
            $table->timestamp('held_since')->nullable()->after('held_reason');
        });
    }

    public function down(): void
    {
        Schema::table('email_suppressions', function (Blueprint $table) {
            $table->dropColumn(['held_reason', 'held_since']);
        });
    }
};
