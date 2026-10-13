<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Keep retired choices until the next save; a deleted catalogue row becomes unavailable. */
    public function up(): void
    {
        Schema::create('app_bottom_tabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id');
            $table->foreign('masjid_id', 'abt_org_fk')->references('id')->on('masjids')->cascadeOnDelete();
            $table->foreignId('feature_id')->nullable();
            $table->foreign('feature_id', 'abt_feature_fk')->references('id')->on('mobile_app_features')->nullOnDelete();
            $table->unsignedTinyInteger('position');
            // Catalogue text has no application-defined maximum and must round-trip exact Unicode.
            $table->text('saved_key');
            $table->text('saved_title');
            $table->timestamps();
            $table->unique(['masjid_id', 'position'], 'abt_position_uq');
            $table->unique(['masjid_id', 'feature_id'], 'abt_feature_uq');
        });
    }

    /** Reversible while the grant is disabled; dropping the table discards preferences. */
    public function down(): void
    {
        Schema::dropIfExists('app_bottom_tabs');
    }
};
