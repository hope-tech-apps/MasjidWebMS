<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * group_posts gains `edited_at`: when the words (or files) of a story that was ALREADY OUT
 * were last changed, so staff and families can see that a story they may have read has since
 * been edited.
 *
 * WHY NOT `updated_at`: other writes bump it (the publish sweep's claim, a "Send now", a
 * retention change), so it says "something touched the row" and not "the words changed".
 *
 * ADDITIVE. Nullable, no default, no backfill: every existing story reads as never edited,
 * which is true of all of them, and code that runs for the seconds of a deploy before this
 * migration reads the attribute null-safely. datetime(), not timestamp(), so MySQL adds no
 * implicit ON UPDATE (the scheduling migration's reason). down() drops the column and touches
 * no other row: the only thing lost is the marker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_posts', function (Blueprint $table) {
            $table->dateTime('edited_at')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('group_posts', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
};
