<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `group_threads.created_by_contact_id` — who opened a conversation, when the
 * person who opened it was a PARENT.
 *
 * Until now only staff could open a thread, so `created_by_user_id` answered the
 * question on its own. A parent has no `users` row, so without this column a
 * parent-opened thread would carry a NULL creator — and a NULL there is already
 * meaningful: it is what a thread opened by a since-deleted staff account looks
 * like (`created_by_user_id` is nullOnDelete). Two different facts collapsing
 * into one value is exactly the ambiguity the provenance columns on
 * `group_memberships` exist to avoid.
 *
 * Exactly one of the two is set. Not enforced by a CHECK constraint — this
 * codebase keeps that kind of rule in PHP, where the two writers live, for the
 * same reason `role` and `scope` are strings rather than DB enums.
 *
 * Additive and nullable, so every existing row stays valid and staff-opened
 * threads are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_threads', function (Blueprint $table) {
            $table->foreignId('created_by_contact_id')->nullable()->after('created_by_user_id')
                ->constrained('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('group_threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_contact_id');
        });
    }
};
