<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-attachment retention, so a VIDEO can die before the post it hangs on.
 *
 * Retention used to live entirely on the parent: `group_posts.retained_until`
 * and `group_threads.retained_until`, both stamped at 365 days, both swept by
 * `groups:purge-feed`. A video inside a post therefore inherited the post's
 * year. The owner's decision (2026-09-24) is 90 days for video, and there is no
 * way to express that without a window on the row that owns the bytes.
 *
 * NULLABLE, AND NULL IS THE DEFAULT, which is what makes this additive rather
 * than a policy change applied retroactively: every existing photograph — and
 * every new one — keeps a null here and continues to die exactly when its
 * parent does. Only rows App\Support\GroupMedia classes as video are stamped.
 * A stamped attachment ALSO still dies with its parent; this window can only
 * take it sooner, never keep it longer.
 *
 * Blueprint only, no raw SQL, so this runs identically on MySQL and on the
 * in-memory SQLite the suite uses (.claude/rules/migrations.md). Both indexes
 * are named by hand and both are under 64 characters, MySQL's identifier limit
 * — the generated names would have been 55 and 58, inside the limit today but
 * with no room for a later column, and SQLite would never have said so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_post_attachments', function (Blueprint $table) {
            $table->date('retained_until')->nullable()->after('path');

            // The purge sweeps this column across every tenant, so masjid_id
            // leads only for the `--masjid=` narrowing; the sweep itself reads
            // the date. Composite rather than two indexes because both queries
            // this table answers about retention start from one of the two.
            $table->index(['masjid_id', 'retained_until'], 'gp_attach_masjid_retained_idx');
        });

        Schema::table('group_message_attachments', function (Blueprint $table) {
            $table->date('retained_until')->nullable()->after('path');
            $table->index(['masjid_id', 'retained_until'], 'gm_attach_masjid_retained_idx');
        });
    }

    public function down(): void
    {
        Schema::table('group_post_attachments', function (Blueprint $table) {
            $table->dropIndex('gp_attach_masjid_retained_idx');
            $table->dropColumn('retained_until');
        });

        Schema::table('group_message_attachments', function (Blueprint $table) {
            $table->dropIndex('gm_attach_masjid_retained_idx');
            $table->dropColumn('retained_until');
        });
    }
};
