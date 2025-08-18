<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('masjid_social_media_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['Facebook', 'YouTube', 'Instagram', 'WhatsApp_URL', 'WhatsApp_Number']);
            $table->string('value');
            $table->timestamps();

            $table->unique(['masjid_id', 'type', 'value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('masjid_social_media_links');
    }
};
