<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->cascadeOnDelete();
            $table->string('header_signature', 64);
            $table->json('mapping');
            $table->timestamps();
            $table->unique(['masjid_id', 'header_signature'], 'roster_mapping_school_signature_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_import_mappings');
    }
};
