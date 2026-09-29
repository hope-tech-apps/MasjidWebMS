<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * prize_ledger_entries: the Manara Bucks ledger (T-003.4, W6). APPEND-ONLY.
 *
 * Points stay the record; bucks are what a week's points turn into, and a child's balance
 * is the SUM of this table for their roster row, never a stored figure that can drift from
 * its history. One row is one thing that happened:
 *
 *   earned     the week's positive points, minted once at the week boundary (+)
 *   adjusted   a late change to a closed week's points, over the last two weeks (+ or -, clamped at 0 balance)
 *   redeemed   a prize taken from the class store (-)
 *   reversal   a correction of a redeemed or cashed_out entry (+); the only way to undo one
 *   cashed_out bucks handed over as paper notes, with the 20/10/5/1 breakdown (-)
 *   expired    the balance ended with the class or the school year (-)
 *
 * NO UPDATE AND NO DELETE by the application (PrizeLedgerEntry refuses both, and no route
 * exists): a correction is a NEW row that points at the old one. No SoftDeletes and no
 * database triggers: append-only is enforced in the application so erasure and the
 * retention purge, which remove a child's whole ledger together, still work.
 *
 * `amount` is signed bucks. `dedupe_key` is a nullable UNIQUE string, which is how "once"
 * is made a database fact on both engines (NULLs are distinct, and there are no partial
 * indexes): `earned:{membership}:{week_start}`, `adjusted:{membership}:{week_start}:{n}`,
 * `reversal:{entry}`, `redeemed:{membership}:{request_id}`, `expired:{membership}:{cutoff}`.
 * `week_basis` is, for earned and adjusted rows, the bucks the week's points came to as
 * accounted AFTER that row, so a clamped clawback is forgiven once and never taken back out
 * of a later week's earnings.
 *
 * `group_membership_id` is RESTRICT, like every other academic-record key
 * (2026_09_09_040000): a roster row that holds a ledger cannot be deleted from under it,
 * and AcademicRecordsHeld says so in a sentence. `group_id` cascades with the class. The
 * prize is nullable and NULL-on-delete, with its title and price snapshotted on the row.
 *
 * RETENTION AS A SET: `retained_until` is stamped on every row, but
 * PrizeLedgerEntry::purgeDueSets() removes a child's rows only when EVERY one of them is
 * due, so the sweep can never delete the earned rows and leave a redemption behind (a
 * negative balance) or the reverse.
 *
 * `occurred_at` is a datetime, not a timestamp (no implicit ON UPDATE), stored UTC; there
 * is no updated_at because nothing here is ever updated. Every index is named by hand:
 * Laravel's default for (masjid_id, group_membership_id, occurred_at) is 68 characters and
 * MySQL refuses more than 64, which SQLite does not, so the suite would stay green and
 * production would fail. Blueprint only; the columns avoid the staging scrub's
 * personal-data tokens except `note`, which the scrub replaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prize_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('group_membership_id')->constrained('group_memberships')->restrictOnDelete();
            $table->string('kind', 16);
            $table->integer('amount');
            $table->date('week_start')->nullable();
            $table->unsignedInteger('week_basis')->nullable();
            $table->foreignId('prize_id')->nullable()->constrained('prizes')->nullOnDelete();
            $table->string('prize_title', 120)->nullable();
            $table->unsignedInteger('prize_cost')->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('prize_ledger_entries')->nullOnDelete();
            $table->json('breakdown')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dedupe_key', 64)->nullable();
            $table->dateTime('occurred_at');
            $table->date('retained_until')->nullable();

            $table->unique('dedupe_key', 'prize_ledger_dedupe_unique');
            $table->index(['group_membership_id', 'occurred_at'], 'prize_ledger_member_occurred_idx');
            $table->index(['group_id', 'occurred_at'], 'prize_ledger_group_occurred_idx');
            $table->index('retained_until', 'prize_ledger_retained_idx');
        });
    }

    public function down(): void
    {
        $rows = DB::table('prize_ledger_entries')->count();

        if ($rows > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$rows} Manara Bucks ledger entr(ies) exist. The ledger is append-only and "
                .'dropping it would destroy what children earned and spent. Export and clear it deliberately first.'
            );
        }

        Schema::dropIfExists('prize_ledger_entries');
    }
};
