<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a staff login last OPENED one specific organisation.
 *
 * A person's last sign-in is global (a token is minted at any school), so a school
 * that shares a teacher with another cannot honestly show it. This is the per-school
 * replacement, on the membership because that row is exactly "this person, this
 * school" (owner, 2026-09-29: "when they last opened the specific school").
 *
 * Nullable, no default, no index: it is read only alongside the membership rows a
 * school's own screen already loads, and written by one conditional UPDATE
 * (App\Support\MembershipSeen) at most every five minutes per membership, so nothing
 * ever searches or orders by it. NULL means "no request has opened this school since
 * the column shipped", NOT "never signed in": nothing is backfilled from tokens,
 * because a global sign-in is precisely the fact this replaces.
 *
 * Plain Blueprint, so it emits the right dialect on SQLite (the suite) and MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjid_user', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('masjid_user', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
