<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contacts — congregant records for the CRM (Phase 0).
 *
 * Tenant-scoped by masjid_id. MySQL has no row-level security, so isolation is
 * enforced in the app layer by App\Models\Concerns\BelongsToMasjid and proved
 * by tests/Feature/TenantIsolationTest. The (masjid_id, id) composite index
 * serves the common "this masjid's row by id" lookup that every scoped read
 * performs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Composite: every tenant-scoped query is WHERE masjid_id = ? and
            // typically also filters/looks up by id.
            $table->index(['masjid_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
