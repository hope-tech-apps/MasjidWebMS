<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * broadcast_deliveries — the per-channel outcome of one broadcast (T-008).
 *
 * ## Why a row per channel, and why it is the whole point
 *
 * "I posted it, I think the push went out" is the state of the world this slice
 * removes. One row per selected channel, each carrying its own status, its own
 * target count, its own error text — so the admin screen can say
 * "push delivered to 412 devices, email failed: mail transport unavailable"
 * instead of a single green tick that means nothing.
 *
 * ## No cross-channel rollback — deliberately
 *
 * The fan-out is NOT wrapped in a transaction and a failing channel NEVER rolls
 * back the others. This is not laziness, it is the only coherent semantics: a
 * push notification that has already reached ten thousand phones cannot be
 * un-sent by a later SMTP timeout, and an email already accepted by the relay
 * cannot be recalled because the announcement insert then deadlocked. A
 * transaction that "rolled back" such a send would roll back only our RECORD of
 * it, leaving the database claiming nothing was sent while congregants read the
 * message on their lock screens — the worst possible outcome, since the admin
 * would then send it again.
 *
 * So each delivery row is committed on its own, and this table is the honest
 * ledger of a partially-successful send. `broadcasts.status` rolls it up.
 * Retrying is a per-channel decision an admin makes with this table in front of
 * them; see App\Services\Broadcast\BroadcastDispatcher.
 *
 * ## Columns
 *
 * - `channel` — plain string (App\Enums\BroadcastChannel is the authority), so
 *   adding SMS is an enum case plus a driver class, never a migration on a live
 *   table (.claude/rules/migrations.md).
 * - `status` — pending / sent / failed / skipped. `skipped` is a first-class
 *   outcome, NOT a failure: a masjid with no registered devices, or an audience
 *   in which nobody has an email address, has nothing to deliver to and must not
 *   be shown a red error.
 * - `target_count` — how many recipients the channel actually addressed. Zero on
 *   pull channels (signage is a board that fetches; nobody is "sent" to).
 * - `reference_id` — the id of the row this channel created in ITS OWN existing
 *   table (announcements.id, notifications.id). That is the provenance link back
 *   to the untouched channel; there is no polymorphic relation because the
 *   channel name already names the table.
 * - `reference` — the external provider's id when there is one.
 * - `masjid_id` is denormalised alongside broadcast_id (same reasoning as
 *   contact_credentials): "every failed delivery this month" carries the tenant
 *   predicate without joining through broadcasts.
 *
 * Blueprint only — no raw SQL, so no driver guard needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            $table->string('channel', 32);
            $table->string('status', 24)->default('pending');

            $table->unsignedInteger('target_count')->default(0);

            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference')->nullable();

            // Operator-readable explanation for a skip, or the sanitised reason
            // a channel failed. Kept separate so a skip note never reads as an
            // error in the UI.
            $table->string('note', 512)->nullable();
            $table->text('error')->nullable();

            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            // One outcome per channel per broadcast — a re-run updates the row
            // rather than appending a second, contradictory verdict.
            $table->unique(['broadcast_id', 'channel']);
            // "Everything that failed on push this month", tenant-scoped.
            $table->index(['masjid_id', 'channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_deliveries');
    }
};
