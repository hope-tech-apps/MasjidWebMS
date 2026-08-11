<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `masjids.org_type` — the Manara vertical discriminator
 * (masjid | school | community). Keystone of the three-vertical split
 * documented in DECISIONS.md (2026-08-10): one shared core, verticals as
 * configuration rather than forks.
 *
 * Behaviour-neutral on deploy: the column defaults to 'masjid', so every
 * existing tenant keeps exactly the behaviour it has today and no read path
 * changes until a caller opts into org-type awareness.
 *
 * Deliberately a `string`, not an `enum`: adding a vertical later would need
 * `ALTER TABLE … MODIFY` on MySQL, which .claude/rules/migrations.md documents
 * breaking the SQLite test run (an unguarded MODIFY silently aborted
 * RefreshDatabase for three days on 2026-07-24). The allowed set is enforced in
 * PHP via Masjid::ORG_TYPES and validated at the request boundary instead.
 *
 * The table keeps its `masjids` name for now — renaming the tenant root to
 * `organizations` is deliberate, tracked tech debt (see PLAN.md T-002), not an
 * oversight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->string('org_type', 32)
                ->default('masjid')
                ->after('name')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->dropIndex(['org_type']);
            $table->dropColumn('org_type');
        });
    }
};
