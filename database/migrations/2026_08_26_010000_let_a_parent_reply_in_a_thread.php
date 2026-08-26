<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-015f — a parent can answer the teacher.
 *
 * The family realm shipped read-only, and routes/family.php said why: "A parent
 * replying in a thread is T-015f (it needs `group_messages.author_contact_id`
 * and a dual-principal `group_thread_reads`, whose `user_id` is NOT NULL
 * today). Absent rather than half-built." This is that slice.
 *
 * TWO PRINCIPALS, ONE CONVERSATION. A thread is now written to by either a
 * staff User or a guardian Contact, and read-marked by either. Both columns are
 * nullable on both tables and exactly one is set per row — enforced in the
 * models, because the check differs per driver and the suite runs on SQLite
 * while production is MySQL.
 *
 * WHY `user_id` COULD NOT SIMPLY STAY NOT NULL: a read bookmark belongs to
 * whoever read it. Reusing the staff column for parents would attribute a
 * parent's read to a staff account id that means something else entirely, and
 * the (thread, user) unique key would collide the moment two parents read the
 * same thread. Both are nullable now; NULLs do not collide in a unique index on
 * either driver, so (thread, user) and (thread, contact) coexist.
 *
 * Nothing is backfilled. Every existing message has a staff author and every
 * existing bookmark a staff reader, which is exactly what they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_messages', function (Blueprint $table) {
            $table->foreignId('author_contact_id')
                ->nullable()
                ->after('author_user_id')
                ->constrained('contacts')
                // The message SURVIVES the contact being deleted, with its
                // authorship reduced to "a parent" — the same posture
                // `author_user_id` already takes for a departed staff account.
                // What was said to a family is not rewritten by a directory edit.
                ->nullOnDelete();
        });

        Schema::table('group_thread_reads', function (Blueprint $table) {
            $table->foreignId('contact_id')
                ->nullable()
                ->after('user_id')
                ->constrained('contacts')
                ->cascadeOnDelete();
        });

        // Separate statement: SQLite rebuilds the table for a column change and
        // will not do it in the same closure that adds a foreign key.
        Schema::table('group_thread_reads', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('group_thread_reads', function (Blueprint $table) {
            $table->unique(['group_thread_id', 'contact_id'], 'group_thread_reads_thread_contact_unique');
        });
    }

    public function down(): void
    {
        Schema::table('group_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('author_contact_id');
        });

        Schema::table('group_thread_reads', function (Blueprint $table) {
            $table->dropUnique('group_thread_reads_thread_contact_unique');
            $table->dropConstrainedForeignId('contact_id');
        });

        Schema::table('group_thread_reads', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
