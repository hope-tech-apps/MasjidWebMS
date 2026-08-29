<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avatars for people, and an Arabic letter tracker for students.
 *
 * Both are ports of features built in DeenQuest, reshaped to the way this
 * codebase already models a school.
 *
 * ## The avatar is three strings on `contacts`, not a media row
 *
 * A chosen avatar is not an uploaded file — it names one of forty drawings that
 * ship with the app (2 characters × 4 skin tones × 5 hijab/kufi colours), so
 * there is nothing to store but the choice. Putting it through Spatie
 * MediaLibrary would mint a row and a file per child for an image the server
 * already has, and would make an avatar deletable by the media sweeps that have
 * already eaten this platform's images once.
 *
 * All three columns are nullable together: a contact with no avatar shows their
 * initials, which is the right default for the 486 contacts that exist today
 * and were never asked.
 *
 * ## Letter progress is keyed on a MEMBERSHIP, like every other student record
 *
 * `hifz_entries` and `behavior_awards` both name their student by the child's
 * own `group_memberships` row rather than by contact, because that is the edge
 * a guardian's consent and a teacher's roster are decided from. Letter progress
 * follows exactly that, so the disclosure rule stays decidable from the row and
 * `GroupAudience` needs no new concept.
 *
 * It also means progress belongs to a child IN A CLASS. A student who moves to
 * next year's class starts that class's tracker fresh, which is what a teacher
 * expects — and their previous year's record stays attached to the class where
 * it was earned.
 *
 * ## The stage lives on the GROUP
 *
 * In DeenQuest the child's own grade decides how much of the qāʿidah is in
 * scope, because the app is installed by one family. A school already models
 * the grade: the class IS the grade. So the stage is a property of the group and
 * the teacher sets it once for the room, instead of thirty children each
 * carrying a number that has to agree with the class they sit in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // ameer | ameera — the character. Nullable: no avatar chosen yet.
            $table->string('avatar_character', 16)->nullable()->after('notes');
            // tone1..tone4 — skin tone.
            $table->string('avatar_tone', 16)->nullable()->after('avatar_character');
            // black | blue | green | pink | white — the hijab or kufi colour.
            $table->string('avatar_color', 16)->nullable()->after('avatar_tone');
        });

        Schema::table('groups', function (Blueprint $table) {
            // How far through the qāʿidah this class is working. Null means the
            // first stage (the letters), so every existing group is already in a
            // valid state without a backfill.
            $table->string('arabic_stage', 32)->nullable()->after('description');
        });

        Schema::create('arabic_letter_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            // Denormalised beside the membership so a whole-class view scopes
            // without joining through group_memberships — the same reasoning
            // that put group_id on behavior_awards and hifz_entries.
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();

            // THE STUDENT: their own participant membership in this class.
            $table->foreignId('group_membership_id')
                ->constrained('group_memberships')->cascadeOnDelete();

            // The teacher who marked it. nullOnDelete for the same reason as
            // hifz_entries.heard_by_user_id: removing a teacher's login must not
            // erase a term of a child's progress.
            $table->foreignId('marked_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // One practisable item, as ArabicCurriculum names it: `ba`,
            // `ba.fatha`, `ba.madd_alif`. A single opaque key rather than a
            // letter column plus a mark column, because the drill is the unit
            // the syllabus counts and the tracker displays — split across two
            // columns, every read would have to reassemble it and agree with
            // the curriculum about which pairs are even valid.
            $table->string('drill_id', 40);

            // not_started | learning | mastered. PHP constants, never a DB enum
            // (.claude/rules/migrations.md).
            $table->string('status', 16)->default('not_started');

            // When it was FIRST mastered, and never rewritten afterwards — a
            // child who slips back to `learning` and masters it again has not
            // mastered it twice, and this is the date a parent reads.
            //
            // dateTime, not timestamp(): production runs MariaDB with
            // `explicit_defaults_for_timestamp` OFF, where the first
            // non-nullable TIMESTAMP silently acquires ON UPDATE
            // CURRENT_TIMESTAMP. See the hifz_entries migration.
            $table->dateTime('mastered_at')->nullable();

            $table->timestamps();

            // One row per drill per student. This is what makes marking
            // idempotent: the tracker upserts against it rather than counting
            // on the client never double-tapping.
            $table->unique(['group_membership_id', 'drill_id'], 'arabic_progress_student_drill_unique');

            // The two reads: one student's tracker, and the class overview.
            $table->index(['masjid_id', 'group_membership_id']);
            $table->index(['masjid_id', 'group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arabic_letter_progress');

        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('arabic_stage');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['avatar_character', 'avatar_tone', 'avatar_color']);
        });
    }
};
