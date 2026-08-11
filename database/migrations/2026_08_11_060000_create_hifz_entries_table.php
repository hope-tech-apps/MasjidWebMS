<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * hifz_entries — one recitation heard from ONE student (PLAN T-014).
 *
 * The ḥifẓ layer of the Schools module, laid on top of the Groups primitive
 * rather than beside it: a ḥalaqa IS a group, and the student is named by their
 * `group_memberships` row — the same explicit edge a guardian names its ward
 * with and a behaviour award names its subject with (T-013). That is what makes
 * the privacy rule decidable from the row.
 *
 * ## THE DAILY CYCLE IS THE SCHEMA
 *
 * Every ḥifẓ classroom runs the same three-part cycle, and `kind` is exactly it:
 *
 *   - `sabak`  — the NEW lesson memorised today. ONLY sabak moves a student
 *                forward.
 *   - `sabqi`  — recent memorisation under active revision (the last juz or so).
 *   - `manzil` — older, consolidated memorisation on a long rotation.
 *
 * A teacher hears each portion and records where it was, how it went, and how
 * many mistakes there were. That is one row.
 *
 * PHP constants (`HifzEntry::KINDS`, `HifzEntry::QUALITIES`), not DB enums —
 * .claude/rules/migrations.md records what an `ALTER TABLE … MODIFY` on a live
 * table did to the SQLite test run, and a school that wants a fourth revision
 * band must never need one.
 *
 * ## POSITION IS DERIVED, NEVER STORED
 *
 * There is deliberately no `current_surah` / `current_ayah` on the membership or
 * anywhere else. A student's position IS their sabak history: the latest sabak
 * entry says where they are, and the union of every sabak range says how much
 * they hold. A denormalised copy would need every writer guarded the way
 * `registrations.registration_count` had to be, and it would go wrong in the one
 * case that matters most — a mis-recorded sabak that is struck afterwards must
 * move the position BACK, which a stored column silently would not.
 *
 * ## THE RANGE
 *
 * (from_surah, from_ayah) .. (to_surah, to_ayah): a closed interval that may
 * cross surahs, because revision does. "Revise juz 26" is 46:1 .. 51:30 and
 * cannot be said with a single surah column. Compared as absolute ayah ordinals
 * through App\Support\QuranIndex, which is also what refuses a backwards range
 * and an ayah past the end of its surah at the request boundary.
 *
 * NO `juz` COLUMN: juz is a function of (surah, ayah), so storing it would be a
 * second writer for one fact — QuranIndex::juzFor() derives it, and the two can
 * never disagree.
 *
 * NO `page` COLUMN: a page number is meaningless without naming which muṣḥaf it
 * refers to. The Madani 15-line, the Indo-Pak 16-line and the various regional
 * printings paginate differently, so a bare integer is a number two teachers in
 * the same school read differently. An ayah-precise range is edition-independent
 * and converts into any pagination later; a stored page number never converts
 * back. If per-edition pagination is genuinely wanted it is its own slice, with
 * the edition named on the tenant.
 *
 * ## DELETION AND RETENTION — a deliberate departure from T-013
 *
 * `group_membership_id` CASCADES, exactly as `behavior_awards` does and for the
 * same reason: the ENTIRE audience for this record is derived from that
 * membership row, so a dangling entry would be unreadable data about a minor,
 * retained forever.
 *
 * But there is NO `retained_until` column and this table is NOT in the
 * `groups:purge-feed` sweep, breaking with the feed, the threads and the awards.
 * A class-story photo and a behaviour point describe a moment; a ḥifẓ record is
 * an ACADEMIC RECORD, and two consequences follow. First, it is the only
 * evidence of what a student has memorised — a school that loses last year's
 * sabak entries cannot tell a new teacher where the child is, and families
 * reasonably expect a memorisation history to outlast a school year. Second, and
 * decisively, position here is DERIVED: a purge that removed the newest sabak
 * entry would silently move a student BACKWARDS in the muṣḥaf, which is a
 * time-bomb no retention default should ever arm. The record's lifetime is
 * instead bounded by the roster — it goes when the student's membership goes.
 * See config/groups.php and .claude/rules/groups.md.
 *
 * `deleted_at` IS THE CORRECTION CLOCK. A teacher who logged the wrong student
 * or the wrong range strikes the entry: it leaves every listing, every total and
 * every derived position at once through the ordinary SoftDeletes scope — one
 * mechanism, no parallel flag a derivation could forget — while
 * `corrected_by_user_id` records who made the correction, because a change to a
 * child's academic record is itself accountable.
 *
 * Tenant-scoped by masjid_id (BelongsToMasjid); MySQL has no row-level security.
 * Proven by tests/Feature/HifzTenantIsolationTest.
 *
 * Blueprint only — no raw SQL, so no driver guard is needed
 * (.claude/rules/migrations.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hifz_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // Denormalised alongside the membership so a per-ḥalaqa listing
            // scopes without joining through group_memberships — the same
            // reasoning that put masjid_id on group_memberships and group_id on
            // behavior_awards.
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();

            // THE STUDENT: their own participant membership in that ḥalaqa. See
            // the class docblock for why this cascades.
            $table->foreignId('group_membership_id')
                ->constrained('group_memberships')->cascadeOnDelete();

            // The teacher who HEARD the recitation. nullOnDelete for the same
            // reason as behavior_awards.awarded_by_user_id: erasing a teacher's
            // login must not erase a year of a child's memorisation history.
            $table->foreignId('heard_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // sabak | sabqi | manzil. Only sabak advances the student.
            $table->string('kind', 32);

            // ---- the Qur'anic range, as a closed interval that may cross surahs ----
            $table->unsignedSmallInteger('from_surah');
            $table->unsignedSmallInteger('from_ayah');
            $table->unsignedSmallInteger('to_surah');
            $table->unsignedSmallInteger('to_ayah');

            // How the recitation went, from HifzEntry::QUALITIES.
            $table->string('quality', 32);

            // Mistakes, counted in the two classes a ḥifẓ teacher actually marks:
            // MAJOR (laḥn jalī — a wrong word, a skipped ayah, anything that
            // changes the meaning) and MINOR (laḥn khafī — a tajwīd refinement).
            // Two independent counters rather than one total, because "one major"
            // and "one minor" are not the same lesson, and a summary that added
            // them would tell a parent nothing.
            $table->unsignedSmallInteger('major_mistakes')->default(0);
            $table->unsignedSmallInteger('minor_mistakes')->default(0);

            // Optional context from the teacher ("struggled with the waqf on
            // ayah 12"). Bounded at the request boundary.
            $table->text('note')->nullable();

            // When the recitation HAPPENED, which is not always when it was
            // typed in: a teacher entering the morning's ḥalaqa after ʿaṣr is
            // the ordinary case. Every listing, every date filter and the
            // position derivation read this, never created_at.
            //
            // DATETIME, NOT timestamp(), and that is load-bearing. Production
            // runs MariaDB 10.11, where `explicit_defaults_for_timestamp` still
            // defaults to OFF — so the FIRST non-nullable TIMESTAMP column in a
            // table silently acquires `DEFAULT CURRENT_TIMESTAMP ON UPDATE
            // CURRENT_TIMESTAMP`. Any later UPDATE that does not name the column
            // (striking an entry writes corrected_by_user_id, not this) would
            // then rewrite when the recitation happened — and here that would
            // reorder the sabak history the current position is derived from.
            // DATETIME carries no such implicit behaviour on either driver.
            $table->dateTime('recited_at');

            // Who struck the entry, when `deleted_at` says one was struck. Null
            // on a live record.
            $table->foreignId('corrected_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            // Correction. See the class docblock: deleted_at IS the correction
            // clock, and a struck entry stops counting everywhere at once.
            // Deliberately no retained_until — this table is not swept.
            $table->softDeletes();

            // The per-ḥalaqa listing, newest first, optionally date-ranged.
            $table->index(['masjid_id', 'group_id', 'recited_at']);
            // One student's diary, newest first.
            $table->index(['masjid_id', 'group_membership_id', 'recited_at']);
            // The two reads this module exists for: the latest sabak (the
            // current position) and every sabak (the coverage), plus the
            // kind-filtered per-student listing.
            $table->index(['masjid_id', 'group_membership_id', 'kind', 'recited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hifz_entries');
    }
};
