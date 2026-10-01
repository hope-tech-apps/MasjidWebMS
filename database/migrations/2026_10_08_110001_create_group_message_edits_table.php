<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_message_edits — the earlier wording of an edited message (W7, 2026-10-01).
 *
 * GroupMessage has always said "there is no per-message eraser to quietly
 * rewrite what was said to a parent". Letting an author change a sent message
 * must not turn that into a lie, so every REAL edit leaves one row here holding
 * the text it replaced. The office reads them (admin realm only); no teacher and
 * no family payload carries one.
 *
 * APPEND-ONLY, in code (GroupMessageEdit refuses an update or a delete through
 * the model). MySQL has no portable guard and the suite runs on SQLite, so the
 * database cannot enforce it, as with the rest of this module.
 *
 * `previous_body` only, never the new text: the chain of previous_body values
 * followed by the message's current body reconstructs every version, and a
 * row's `created_at` is when that earlier version stopped being current.
 *
 * `editor_user_id` is the staff account that made the edit. It is nulled when
 * that account is deleted (the history survives with its attribution softened,
 * like the message's own author).
 *
 * CASCADES. The row goes with its message by the DB cascade, which goes with its
 * thread's retention purge, a deleted group and a deleted organisation: an
 * erased message does not survive in this table. Nothing on disk hangs off it,
 * so no model hook is needed for the bytes.
 *
 * The index is named by hand to keep it short and to match the table's other
 * hand-named indexes. It did not have to be: the generated name,
 * `group_message_edits_masjid_id_group_message_id_id_index`, is 55 characters,
 * inside MySQL's 64.
 * Blueprint only — no raw SQL, no driver guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_message_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_message_id')->constrained('group_messages')->cascadeOnDelete();
            $table->foreignId('editor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('previous_body');
            $table->timestamp('created_at')->nullable();

            $table->index(['masjid_id', 'group_message_id', 'id'], 'group_msg_edits_masjid_message_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_message_edits');
    }
};
