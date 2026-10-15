<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** Keep one existing payment response per child/year, and distinguish deliberate reminder requests. */
return new class extends Migration
{
    public function up(): void
    {
        // Never decide which duplicate payment record to discard on an operator's behalf.
        if (DB::table('school_reenrolment_deposits')->select('reenrolment_id', 'student_contact_id')->groupBy('reenrolment_id', 'student_contact_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Resolve duplicate re-enrolment deposit links before migrating. Keep the original payment records.');
        }
        Schema::table('school_reenrolment_deposits', function (Blueprint $table) {
            $table->unique(['reenrolment_id', 'student_contact_id'], 'reenrol_deposit_child_uq');
        });
        Schema::table('school_admission_mails', function (Blueprint $table) {
            $table->uuid('reminder_batch')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('school_reenrolment_deposits')->exists() || DB::table('school_admission_mails')->whereNotNull('reminder_batch')->exists()) {
            throw new RuntimeException('Refusing to roll back populated admission deposit or reminder records.');
        }
        Schema::table('school_admission_mails', function (Blueprint $table) {
            $table->dropColumn('reminder_batch');
        });
        Schema::table('school_reenrolment_deposits', function (Blueprint $table) {
            $table->dropUnique('reenrol_deposit_child_uq');
        });
    }
};
