<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * prizes: what a class store sells, for Manara Bucks (T-003.4, W6).
 *
 * TWO KINDS OF ROW, told apart by `group_id`:
 *   - NULL  a SCHOOL-WIDE prize, kept by the office (R4);
 *   - set   one CLASS's own prize, kept by that class's teachers (R4).
 * A redemption may name either, but only the school-wide list or the class's own list:
 * another class's prize, and another school's, are refused (App\Support\ClassStore).
 *
 * `stock` is the number still on the shelf, and NULL means unlimited (R6, "blank means
 * unlimited"). It is decremented in the same transaction as the redemption and given back
 * by a reversal. `cost_bucks` is what a redemption debits; the ledger snapshots it, so
 * repricing a prize never restates what a child paid last week.
 *
 * A prize is RETIRED with `is_active = false`, never deleted: the ledger names it.
 * Uniqueness of `title` is checked by the form request, not by an index, because a NULL
 * `group_id` is distinct from every other NULL in a unique index on both engines (no
 * partial indexes: .claude/rules/migrations.md).
 *
 * Column names avoid the staging scrub's personal-data tokens (`name`, `note`): a prize
 * is a shelf item, and its title is not about a person.
 *
 * Cascades from the school and the class (a class's own prizes go with it);
 * `created_by_user_id` is NULL-on-delete so retiring a teacher's login keeps the prize.
 * Both indexes are named by hand (MySQL caps an identifier at 64 characters). Additive,
 * Blueprint only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('groups')->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('cost_bucks');
            $table->unsignedInteger('stock')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['masjid_id', 'is_active'], 'prizes_masjid_active_idx');
            $table->index(['masjid_id', 'group_id'], 'prizes_masjid_group_idx');
        });
    }

    public function down(): void
    {
        $rows = DB::table('prizes')->count();

        if ($rows > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$rows} prize(s) exist, and the ledger names them. Retire them instead."
            );
        }

        Schema::dropIfExists('prizes');
    }
};
