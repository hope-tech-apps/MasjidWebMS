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
        Schema::create('masjid_mobile_app_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->onDelete('cascade');
            $table->foreignId('feature_id')->constrained('mobile_app_features', 'id')->onDelete('cascade');
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            $table->unique(['masjid_id', 'feature_id'], 'masjid_id_app_feature_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('masjid_mobile_app_features');
    }
};
