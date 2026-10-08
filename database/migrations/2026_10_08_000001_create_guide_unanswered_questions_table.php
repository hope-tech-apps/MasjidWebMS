<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guide_unanswered_questions', function (Blueprint $table) {
            $table->id();
            $table->text('question');
            $table->timestamp('created_at')->index();
            $table->string('books', 32);
            $table->text('release_version'); // Version digits have no length cap in the release contract.
        });
    }

    public function down(): void { Schema::dropIfExists('guide_unanswered_questions'); }
};
