<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guide_ask_counters', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 64); // org:<id> or platform; never a question or person.
            $table->date('period'); // UTC day, or the first UTC day of the month.
            $table->unsignedBigInteger('count');
            $table->unique(['scope_key', 'period']);
        });
    }

    public function down(): void { Schema::dropIfExists('guide_ask_counters'); }
};
