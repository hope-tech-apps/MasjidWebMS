<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger of every SuperAdmin switch flip on an organisation
 * (DECISIONS.md 2026-09-16).
 *
 * `masjids.capability_overrides`, `crm_enabled`, `assistant_enabled` and
 * `listed_at` are STATE: they say what is on now, not who changed it or what it
 * was before. A switch that takes Announcements away from a school's office, or
 * closes its public program sign-up, must be answerable later, and the warning
 * log rotates. This table is that answer.
 *
 * Column choices:
 *  - No foreign keys. A row must survive the force delete of the organisation
 *    and of the acting user, and a FK onto `masjids` would make SQLite rebuild
 *    that table and drop its partial unique indexes.
 *  - `capability` is a catalogue key (config/capabilities.php), or
 *    `directory_listing` for the public directory switch, which is not one.
 *  - `override_before` is the stored SuperAdmin decision before the flip: null
 *    when there was none (the org_type default applied) and for column-backed
 *    switches, which have no override.
 *  - A no-op flip still writes a row: "someone confirmed it off" is a fact.
 *  - `created_at` only. Append-only is enforced on the model.
 *  - The composite index is named by hand: MySQL caps identifiers at 64.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masjid_capability_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('masjid_id');
            $table->string('capability', 64);
            $table->boolean('enabled_before');
            $table->boolean('enabled_after');
            $table->boolean('override_before')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['masjid_id', 'created_at'], 'mcc_masjid_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masjid_capability_changes');
    }
};
