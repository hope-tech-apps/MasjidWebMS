<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dated meal menu a masjid opens for ordering — the Jummah lunch being the
 * first use. The parent catalog row, mirroring `offerings`: a public `uuid`
 * handle, integer-minor money on its items (not here), string status (never a
 * DB enum — SQLite can't ALTER a CHECK), and one menu per masjid per date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_menus', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();

            $table->string('title')->default('Jummah Lunch');
            $table->date('service_date');
            $table->string('status', 32)->default('draft'); // draft | open | closed

            $table->dateTime('ordering_opens_at')->nullable();
            $table->dateTime('ordering_closes_at')->nullable();   // the cutoff
            $table->string('pickup_instructions')->nullable();
            $table->text('notes')->nullable();

            $table->boolean('allow_online_payment')->default(true);
            $table->boolean('allow_pay_at_pickup')->default(true);
            $table->char('currency', 3)->default('usd');

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['masjid_id', 'service_date']);        // one menu per Friday
            $table->index(['masjid_id', 'status']);
            $table->index(['masjid_id', 'service_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_menus');
    }
};
