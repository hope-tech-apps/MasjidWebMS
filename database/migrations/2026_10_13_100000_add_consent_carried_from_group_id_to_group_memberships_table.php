<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A consent that was CARRIED by a move, told apart from one the office recorded.
 *
 * Since 2026-10-05 a move copies a guardian's consent, as it was recorded, onto
 * the entry it creates in the new class (App\Models\GroupMembership::carriedFrom).
 * Afterwards two entries for one adult and one child hold the same two consent
 * columns, and nothing on either says which one the family was asked about.
 * This column says it: on the copy, the id of the CLASS it was copied from.
 *
 *   marker   consent columns   reads as
 *   null     set               recorded by the office for this class
 *   set      set               carried from that class, untouched since
 *   set      null              withdrawn here after it was carried
 *   null     null              never asked, or withdrawn where it was recorded
 *
 * The office recording consent clears it (the office is now asserting it for
 * this class); a withdrawal keeps it, and that kept state is what stops a later
 * move from bringing the old class's consent back into force
 * (App\Support\RosterMove, rule R10).
 *
 * THE CLASS, NOT THE ENTRY. Removing a student's place deletes the guardian
 * entries beside it, and a contact merge re-issues rows, so an entry id can
 * dangle while the office still needs "carried from which class". The same
 * adult, the same child and a class is one row by the roster's unique index, so
 * the source entry is still found while it exists.
 *
 * PLAIN COLUMN: NO FOREIGN KEY, NO INDEX, as the four `moved_*` columns beside
 * it (2026_10_11_100000). A key into `groups` added to an existing table makes
 * SQLite rebuild the table; a class deleted since leaves an id that reads as
 * "a class that was removed". Nothing reads it in a hot path.
 *
 * Additive and nullable: every existing row keeps its meaning, NULL means "not
 * carried", and nothing is backfilled. Blueprint only, so the same statement
 * runs on MySQL and on the suite's SQLite.
 *
 * DEPLOY ORDER. bin/deploy serves the new code before it runs this. A move is
 * refused with a sentence until the column exists (RosterMove::ready), and
 * every other read or write of it is behind GroupMembership::consentCarryReady().
 *
 * ROLLING BACK. By code only. Older code ignores the column and a carried
 * consent stays in force as ordinary consent. `down()` would lose, for good,
 * which consents were carried and which carried ones a family has withdrawn, so
 * it refuses while any row is marked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_memberships', function (Blueprint $table) {
            $table->unsignedBigInteger('consent_carried_from_group_id')->nullable()->after('consent_scope');
        });
    }

    public function down(): void
    {
        $marked = DB::table('group_memberships')->whereNotNull('consent_carried_from_group_id')->count();

        if ($marked > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$marked} guardian ".($marked === 1 ? 'entry is' : 'entries are')
                .' marked as holding a consent a move carried. Dropping the column would lose which consents were '
                .'carried and which of them a family has since withdrawn.'
            );
        }

        Schema::table('group_memberships', function (Blueprint $table) {
            $table->dropColumn('consent_carried_from_group_id');
        });
    }
};
