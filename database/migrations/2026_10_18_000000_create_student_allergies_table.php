<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_allergies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->text('allergies')->nullable();
            $table->string('source', 12);
            // Preserve provenance if a response or staff account is retired.
            $table->unsignedBigInteger('form_response_id')->nullable();
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['masjid_id', 'contact_id'], 'student_allergy_school_contact_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_allergies');
    }
};
