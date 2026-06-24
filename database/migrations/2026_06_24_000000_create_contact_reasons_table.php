<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contact_reasons
 *
 * Per-masjid, admin-managed list of selectable contact reasons. The public
 * mobile endpoint (/mobile/masjids/{id}/contact-reasons) returns only the
 * active rows, ordered by `order`. Distinct from the legacy global
 * `contact_us_reasons` table (which is keyed off free-text message reasons).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contact_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained('masjids')->onDelete('cascade');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_reasons');
    }
};
