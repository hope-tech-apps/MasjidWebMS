<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masjid Assistant — the per-masjid feature gate plus the escalation table.
 *
 * `assistant_enabled` mirrors `crm_enabled`: default OFF, flipped only by a
 * SuperAdmin, so the assistant ships dark and is switched on per masjid.
 *
 * `assistant_feature_requests` is the "beyond my skillset" path — when the
 * assistant can't do something (capability doesn't exist, isn't enabled for
 * this masjid, or the admin lacks permission) it records the ask here for
 * Hope Tech Inc instead of failing silently or pretending it succeeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->boolean('assistant_enabled')->default(false);
        });

        Schema::create('assistant_feature_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            // The admin who asked. Nullable so a request survives user deletion.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Why the assistant couldn't do it.
            $table->enum('category', [
                'missing_capability',   // we don't build this yet
                'feature_not_enabled',  // exists, but off for this masjid
                'insufficient_permission',
                'other',
            ])->default('other');

            $table->string('summary');
            $table->text('details')->nullable();

            $table->enum('status', ['open', 'acknowledged', 'resolved', 'declined'])
                ->default('open');

            $table->timestamps();
            $table->index(['masjid_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_feature_requests');

        Schema::table('masjids', function (Blueprint $table) {
            $table->dropColumn('assistant_enabled');
        });
    }
};
