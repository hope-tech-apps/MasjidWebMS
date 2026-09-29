<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger of changes to imported web addresses (Manara Studio W2, S6).
 *
 * W1 froze the rows imported from the renderer's live host map: they are how
 * the live tenants are reached. `domains:imported` is the one reviewed tool
 * that may release or adopt one, every use is a production change with the
 * owner's go, and each executed action writes one row here, in the same
 * transaction as the change: the host, the action, the row before and after,
 * who ran it and why.
 *
 * Append-only (App\Models\MasjidDomainChange refuses update and delete).
 * `masjid_domain_id` is a plain indexed column, not a foreign key, so a
 * release (which deletes the row) keeps its own record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('masjid_domain_changes')) {
            return;
        }

        Schema::create('masjid_domain_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('masjid_domain_id')->nullable()->index('mdc_domain_idx');
            $table->string('host', 253);
            $table->string('action', 32);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('operator', 191);
            $table->text('reason');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masjid_domain_changes');
    }
};
