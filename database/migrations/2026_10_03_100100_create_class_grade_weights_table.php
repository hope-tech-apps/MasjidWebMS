<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much each TYPE of work counts toward a class's average (a type is one slot)
 * (T-001.2; owner, 2026-09-28: weights "per type with a per-assignment override,
 * relative, renormalised over the types with scored work").
 *
 * ONE ROW PER (class, type), and a class either has all five types or none:
 * `PUT grade-weights` replaces the whole set or clears it, so a half-set cannot
 * exist. NO ROWS means the class is unweighted and every average is today's
 * arithmetic, byte for byte (App\Support\GradeRecord).
 *
 * `weight` is relative, an integer 0-100 in an unsigned tinyint. It is never
 * required to sum to 100: the average divides by the weights of the work that has
 * marks, so a type nobody has been marked on yet does not drag anything down.
 *
 * Cascades from the class and the school: a weight is a setting of a class and
 * goes with it. `updated_by_user_id` is NULL-on-delete so retiring a teacher's
 * login keeps the setting they made.
 *
 * The unique index is named by hand: MySQL caps an identifier at 64 characters
 * and SQLite does not, so a generated name that passes the suite can abort a
 * migration on production. Blueprint only.
 *
 * `down()` refuses while rows exist (they are a teacher's grading policy, and
 * MySQL DDL is not transactional).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_grade_weights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->string('assignment_type', 16);
            $table->unsignedTinyInteger('weight');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'assignment_type'], 'class_grade_weight_unique');
        });
    }

    public function down(): void
    {
        $rows = DB::table('class_grade_weights')->count();

        if ($rows > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$rows} class grade weight(s) exist and dropping the table would destroy "
                ."a teacher's grading policy. Clear them deliberately first."
            );
        }

        Schema::dropIfExists('class_grade_weights');
    }
};
