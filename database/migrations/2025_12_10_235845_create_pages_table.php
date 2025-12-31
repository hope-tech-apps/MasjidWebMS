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
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->onDelete('cascade');
            $table->string('slug')->collation('utf8mb4_bin'); // e.g., 'home', 'donate', 'about-us'
            $table->string('title'); // e.g., 'Home', 'Donate', 'About Us'
            $table->string('page_title')->nullable(); // Custom page title (can be different from title)
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0); // for menu ordering
            $table->boolean('show_in_menu')->default(true); // Show in navigation menu
            $table->boolean('show_as_button')->default(false); // Show as separate button (e.g., Donate Now)
            $table->text('meta_description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Unique slug per masjid
            $table->unique(['masjid_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};

