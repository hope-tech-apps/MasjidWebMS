<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files under a lesson plan's Activities (T-004.1).
 *
 * A plan does not own bytes. A file a teacher attaches is a `group_resources`
 * row (the class's Files, PRIVATE disk, staff-only by default), and this table
 * only says "this plan uses that file", in an order. So every rule the Files
 * feature already has, the size and type limits, the per-class ceiling, the
 * private disk, the download route that re-resolves the ownership chain, applies
 * to a plan attachment without a second copy of any of it, and `lesson_plans` is
 * never altered.
 *
 * ## Deleting either side
 *
 * Both foreign keys CASCADE. Removing a plan removes its links and leaves the
 * file in the class's Files (the teacher may still want it); removing a file from
 * Files removes it from every plan that used it, so a plan can never point at
 * bytes that are gone. `GroupResource`'s `deleting` hook still removes the bytes
 * through the model; the cascade only takes the link rows, which hold no bytes.
 *
 * ## "The same class" is not a database constraint
 *
 * The row does not carry `group_id`, so nothing here stops a link between a plan
 * of one class and a file of another. That rule lives where the write happens
 * (`Teacher\LessonPlanController::resolveAttachments`) and is pinned by tests
 * that attach another class's file and another school's file and expect a 422.
 *
 * ## Index names are hand-written
 *
 * MySQL caps an identifier at 64 characters and SQLite does not, so a generated
 * name that passes the suite can abort a migration on production. Both names
 * here are short; `LessonPlanAttachmentsTest::the_table_has_the_shape_the_migration_documents_and_short_index_names` asserts it.
 *
 * Blueprint only, no raw SQL, so there is no driver guard to write. Additive: a
 * new table, nothing existing touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_plan_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_plan_id')->constrained('lesson_plans')->cascadeOnDelete();
            $table->foreignId('group_resource_id')->constrained('group_resources')->cascadeOnDelete();

            // The order the teacher listed them in. Ten at most (config
            // `groups.lessons.max_attachments`), so a tiny integer is plenty.
            $table->unsignedTinyInteger('position')->default(0);

            $table->timestamps();

            // A file is attached to a plan once.
            $table->unique(['lesson_plan_id', 'group_resource_id'], 'lesson_plan_resource_unique');

            // "Which plans use this file?" — the Files tab's count and the
            // cascade on a file's deletion.
            $table->index(['masjid_id', 'group_resource_id'], 'lesson_plan_res_masjid_res_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_plan_resources');
    }
};
