<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The school's own list of subjects (T-001.3), edited by the office in the
 * Subjects screen and offered to a teacher setting work.
 *
 * `grade_labels` is a JSON list of the grade levels a subject is taught at; NULL
 * means EVERY grade, which is the default and the safe direction (a grade nobody
 * listed never loses a subject). Levels are compared through
 * App\Support\GradeLevel, because memberships, the weekly guide and the Drive
 * curriculum each spell the same grade differently.
 *
 * `name_key` is `name` folded by App\Support\SubjectKey (lower case, apostrophes
 * dropped) and carries the per-school unique index, so "Qur’an" and "Qur'an" are
 * one subject. Work does not point here: it stores the subject as a snapshot
 * string (see the class_assignments migration), so renaming or deleting a subject
 * changes no mark.
 *
 * `position` orders the list; ties break by name.
 *
 * `seeded_by` names the migration that inserted a row (NULL for every row the
 * office typed). It exists so the seed's `down()` can tell a row it wrote from an
 * office row that happens to read the same: a "Qur'an" typed into the Subjects
 * screen at the defaults (position 0, every grade) is identical, column for
 * column, to the seeded one, and the seed skips a subject that already exists, so
 * it never owned that row. The office cannot set it (not fillable, never sent).
 *
 * Cascades from the school. The unique index is named by hand (64-character cap
 * on MySQL). Blueprint only. `down()` refuses while rows exist: the list is what
 * the office typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('name_key', 64);
            $table->json('grade_labels')->nullable();
            $table->smallInteger('position')->default(0);
            $table->string('seeded_by', 120)->nullable();
            $table->timestamps();

            $table->unique(['masjid_id', 'name_key'], 'school_subject_name_unique');
        });
    }

    public function down(): void
    {
        $rows = DB::table('school_subjects')->count();

        if ($rows > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$rows} school subject(s) exist and dropping the table would destroy "
                .'the list the office typed. Delete them deliberately first.'
            );
        }

        Schema::dropIfExists('school_subjects');
    }
};
