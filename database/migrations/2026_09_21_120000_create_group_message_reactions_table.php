<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_message_reactions — 🤲 👍 💯 ❓ on a message in a teacher <-> family
 * conversation (owner, 2026-09-21).
 *
 * ONE ROW PER (message, reaction, person). The reacting person is a staff User
 * or a guardian Contact, never both — the same two-principal shape
 * group_messages and group_thread_reads already have, enforced in the model
 * because a CHECK would exist on only one of the two drivers.
 *
 * TWO UNIQUE KEYS, one per principal column. NULLs are distinct in a unique
 * index on both MySQL and SQLite, so a parent's row (user_id NULL) is never
 * constrained by the staff key and vice versa — each key constrains exactly
 * the rows of its own principal. No generated column is needed
 * (.claude/rules/migrations.md, "conditional unique index") because neither
 * key has a predicate; it is the NULLs that do the partitioning.
 *
 * `reaction` is a short KEY ('ameen', 'thumbs_up', 'hundred', 'question'),
 * never the emoji itself: the allowed set is GroupMessageReaction::REACTIONS,
 * a PHP constant, and a key is what a URL, a unique index and a log line can
 * all carry without an encoding question.
 *
 * CASCADES, all of them. A reaction records nothing anyone must keep: it goes
 * with its message (which goes with its thread's purge), with a deleted staff
 * account, and with a deleted contact. Nothing hangs off it, so the DB cascade
 * is the whole teardown.
 *
 * Every index is named by hand: the generated names would pass 64 characters.
 * Blueprint only — no raw SQL, no driver guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_message_id')->constrained('group_messages')->cascadeOnDelete();
            $table->string('reaction', 32);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['group_message_id', 'reaction', 'user_id'], 'group_msg_reactions_message_user_unique');
            $table->unique(['group_message_id', 'reaction', 'contact_id'], 'group_msg_reactions_message_contact_unique');
            $table->index(['masjid_id', 'group_message_id'], 'group_msg_reactions_masjid_message_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_message_reactions');
    }
};
