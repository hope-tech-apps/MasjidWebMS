<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Fixed operational reasons only; never persist transport exceptions or private mail content.
        Schema::table('duty_rota_mails',fn(Blueprint $t)=>$t->string('failure_reason',120)->nullable());
    }
    public function down(): void
    {
        Schema::table('duty_rota_mails',fn(Blueprint $t)=>$t->dropColumn('failure_reason'));
    }
};
