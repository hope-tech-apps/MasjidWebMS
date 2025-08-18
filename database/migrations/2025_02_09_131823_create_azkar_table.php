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
        Schema::create('azkar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('azkar_category_id')->nullable()->constrained('azkar_categories')->onDelete('set null');
            $table->json('title');
            $table->json('text');
            $table->json('bless')->nullable();
            $table->string('pronunciation');
            $table->unsignedInteger('frequency')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('azkar');
    }
};
