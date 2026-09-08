<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which scale a piece of work is marked on: points, or the school's four
 * performance levels.
 *
 * ---------------------------------------------------------------------------
 * WHY A COLUMN AND NOT AN INFERENCE
 * ---------------------------------------------------------------------------
 *
 * A levels assignment looks exactly like a 4-point one in the existing schema:
 * `points_possible = 4`, `points_earned` between 1 and 4. It would therefore
 * have been free to skip this column and infer the scale from `points_possible
 * === 4`.
 *
 * That inference is wrong in both directions and quietly. A genuine four-mark
 * spelling quiz would start being reported as "Approaching Expectations", and a
 * school that ever wanted a 5-level scale would silently lose the inference
 * entirely. The scale is a fact about what the teacher intended to record, and
 * intent does not survive being derived from a number.
 *
 * ---------------------------------------------------------------------------
 * DEFAULT 'points', which is NOT the school's scale — on purpose
 * ---------------------------------------------------------------------------
 *
 * Every row that exists today was created and marked as points, so the column
 * default has to be `points` or this migration would relabel real marks as
 * performance levels. `config('groups.default_grading_scale')` is what makes
 * `levels` the default for NEW work at a school that uses them; the two are
 * deliberately different knobs, because one is about history and the other is
 * about what the next form should be pre-set to.
 *
 * A varchar rather than an enum, per .claude/rules/migrations.md: adding a third
 * scale must be a code change, not an ALTER TABLE on a live table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_assignments', function (Blueprint $table) {
            $table->string('scale', 16)->default('points')->after('points_possible');
        });
    }

    public function down(): void
    {
        Schema::table('class_assignments', function (Blueprint $table) {
            $table->dropColumn('scale');
        });
    }
};
