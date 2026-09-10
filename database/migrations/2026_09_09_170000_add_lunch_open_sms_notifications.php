<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Text me when Jummah lunch ordering opens again."
 *
 * The subscriber list is NOT a new table. `contact_service_interests` already
 * models "this person asked to hear about this service", BroadcastAudience::
 * SERVICE already resolves it AT SEND TIME so a withdrawal five minutes before
 * dispatch is honoured, and the whole SMS stack — consent evidence, the durable
 * suppression list, STOP/START, the 10DLC gate — already hangs off `contacts`.
 * A parallel `lunch_sms_subscribers` table would have had to reimplement every
 * one of those, and the one it got wrong would be the one that matters legally.
 *
 * So this migration adds only the three facts that were genuinely missing:
 *
 *   meal_menus.notify_service_id — WHICH service subscribers are opting into.
 *   NULLABLE, and null means no notification is ever sent. Fail-safe on purpose:
 *   a menu that has not been pointed at a service must stay silent rather than
 *   guess an audience and text the wrong list.
 *
 *   meal_menus.opening_notified_at — the ONCE-ONLY guard. Opening is a status
 *   change an admin can make repeatedly (open -> draft -> open while fixing a
 *   typo), and each of those must not be a fresh text to the whole list. Stamped
 *   the first time a menu opens and never cleared automatically.
 *
 *   meal_menus.allow_sms_optin — whether the order form shows the offer at all,
 *   per menu like every other option on a sale.
 *
 * `allow_sms_optin` DEFAULTS TO FALSE — the opposite of the other flags on this
 * table, deliberately. The others change what a form looks like; this one starts
 * collecting consent to send commercial text messages, which is regulated. An
 * organisation must turn that on knowingly, and it cannot usefully be on before
 * `notify_service_id` is set anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_menus', function (Blueprint $table) {
            // Plain column, not ->constrained(): adding a foreign key to an
            // existing table forces SQLite to rebuild it, which silently drops
            // partial unique indexes elsewhere in the suite.
            $table->unsignedBigInteger('notify_service_id')->nullable()->after('allow_fee_coverage');
            $table->timestamp('opening_notified_at')->nullable()->after('notify_service_id');
            $table->boolean('allow_sms_optin')->default(false)->after('opening_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('meal_menus', function (Blueprint $table) {
            $table->dropColumn(['notify_service_id', 'opening_notified_at', 'allow_sms_optin']);
        });
    }
};
