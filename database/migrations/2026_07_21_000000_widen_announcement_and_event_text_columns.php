<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `details` on announcements and events was varchar(255) — about two sentences.
 *
 * This is not an assistant-only problem. StoreAnnouncementRequest and
 * StoreEventRequest both validate `details` as `required|string` with no maximum,
 * so an admin typing a normal-length description into the existing admin form
 * gets a 500 and a truncated-data SQL error too. The column was simply too small
 * for what the field is for.
 *
 * `link` gets widened for the same reason: the form requests validate it as a URL
 * with no length cap, and real event sign-up links (SignUpGenius, Google Forms)
 * routinely run past 255 characters.
 *
 * `announcements.text` is already TEXT and stays untouched — it is unused, and
 * every live row holds ''.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->text('details')->nullable(false)->change();
            $table->string('link', 2048)->nullable()->change();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->text('details')->nullable(false)->change();
            $table->string('link', 2048)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing truncates. Anything already longer than 255 would be silently
        // cut, so this rollback is lossy by nature — kept only for symmetry.
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('details', 255)->nullable(false)->change();
            $table->string('link', 255)->nullable()->change();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->string('details', 255)->nullable(false)->change();
            $table->string('link', 255)->nullable()->change();
        });
    }
};
