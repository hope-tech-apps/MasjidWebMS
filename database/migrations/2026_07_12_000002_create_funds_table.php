<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * funds — the designations a donor can give to (Zakat, Sadaqah, Fitra, Waqf,
 * General). Tenant-scoped by masjid_id; isolation is enforced app-side by
 * App\Models\Concerns\BelongsToMasjid (MySQL has no row-level security). See
 * .claude/rules/tenant-scoping.md.
 *
 * `receiptable` marks whether gifts to this fund produce a tax receipt (e.g. a
 * pass-through/relief fund may not). `is_active` hides retired funds from the
 * public donation form without deleting historical rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('masjid_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['zakat', 'sadaqah', 'fitra', 'waqf', 'general']);
            $table->boolean('receiptable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Every tenant-scoped read is WHERE masjid_id = ? and usually also
            // filters by id / is_active.
            $table->index(['masjid_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funds');
    }
};
