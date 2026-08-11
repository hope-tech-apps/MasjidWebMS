<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * behavior_skills — the recognition VOCABULARY one organization defines
 * (PLAN T-013, the Classroom module).
 *
 * "Participation", "Kindness", "Memorised a new surah", "Disruption". A school
 * writes its own list; nothing here ships a fixed set, because what a tenant
 * chooses to notice about a child is the tenant's decision, not ours.
 *
 * This table holds NO data about any child. It is the drop-down, and it is
 * per-tenant vocabulary — which is why, unlike groups/posts/threads, it does
 * NOT soft-delete: deleting a skill destroys the menu entry and nothing else.
 * Every award snapshots the label, polarity and points it was given with (see
 * create_behavior_awards_table), so history is already independent of this row,
 * and `behavior_awards.behavior_skill_id` nulls rather than cascades. The
 * ordinary way to retire a skill is `is_active = false`, which keeps it out of
 * the picker while leaving past awards legible.
 *
 * `polarity` is a plain string, NOT a DB enum: BehaviorSkill::POLARITIES is the
 * authority, validated at the request boundary with Rule::in(...). Adding a
 * polarity (or any other constant set in this codebase) must never mean
 * `ALTER TABLE … MODIFY` on a live table — see .claude/rules/migrations.md.
 *
 * Tenant-scoped by masjid_id; MySQL has no row-level security, so isolation is
 * App\Models\Concerns\BelongsToMasjid, proven by
 * tests/Feature/BehaviorTenantIsolationTest.
 *
 * NO PAYWALL COLUMN, HERE OR ANYWHERE IN THIS MODULE. ClassDojo's second
 * loudest complaint is that core classroom features sit behind a plan; Manara's
 * differentiator is that they do not. There is deliberately no `plan`, `tier`
 * or `is_premium` column to hang one on. See .claude/rules/groups.md.
 *
 * Blueprint only — no raw SQL, so no driver guard is needed
 * (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavior_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // Admin-facing wording, in the tenant's own language. Never
            // translated or normalised by us.
            $table->string('label');

            // `positive` or `negative` — BehaviorSkill::POLARITIES is the
            // authority. It exists so a summary can separate encouragement from
            // correction WITHOUT inferring it from the sign of the points, which
            // an office is free to set however it likes.
            $table->string('polarity', 32);

            // The value proposed when this skill is awarded. A signed integer:
            // a tenant that prefers "-1 for Disruption" may say so, and a tenant
            // that prefers "+1, marked negative" may say that instead. The award
            // SNAPSHOTS whatever value applied at the time, so editing this
            // later never rewrites a child's record.
            $table->integer('default_points')->default(1);

            // Retiring a skill without deleting it: it leaves the picker, past
            // awards stay legible.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Per-tenant label uniqueness, per .claude/rules/migrations.md. Two
            // "Participation" rows in one school is a data-entry slip, not a
            // vocabulary. Nothing soft-deletes here, so the index never blocks
            // reusing a label that was genuinely removed.
            $table->unique(['masjid_id', 'label']);

            // The picker read: this tenant's active skills.
            $table->index(['masjid_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_skills');
    }
};
