<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A teacher's avatar must not destroy the child's.
 *
 * The first cut had ONE set of avatar columns, so staff editing a student's
 * avatar overwrote whatever the child had picked and there was no way back.
 * ClassDojo — which this is modelled on — keeps the two apart and offers
 * "Restore student-created monster", and that is the right shape: a child
 * choosing how they are represented is theirs, and a teacher's change is an
 * OVERRIDE laid on top rather than a replacement.
 *
 * So `avatar_*` now means THE CHILD'S OWN choice, and `staff_avatar_*` is the
 * override. The effective avatar is the override when present, otherwise the
 * child's. Clearing the override restores what the child picked, because it was
 * never touched.
 *
 * Nothing is backfilled: the handful of avatars set before this ran were set by
 * staff on behalf of a child who had not been asked, which is exactly what
 * "the child's own, until they say otherwise" should mean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('staff_avatar_character', 16)->nullable()->after('avatar_color');
            $table->string('staff_avatar_tone', 16)->nullable()->after('staff_avatar_character');
            $table->string('staff_avatar_color', 16)->nullable()->after('staff_avatar_tone');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['staff_avatar_character', 'staff_avatar_tone', 'staff_avatar_color']);
        });
    }
};
