<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Placeholder contacts + import tagging.
 *
 *   - is_placeholder  an unidentified card donor ("Unknown Name Credit 3256").
 *     Their giving is real and attributed to the card, but there's no person yet.
 *     An admin can later merge a placeholder into a real member (existing or new).
 *   - import_batch    tags contacts created by a bulk import, so a whole import
 *     is reversible in one query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_placeholder')->default(false)->after('notes');
            $table->string('import_batch')->nullable()->after('is_placeholder');

            $table->index(['masjid_id', 'is_placeholder']);
            $table->index('import_batch');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['masjid_id', 'is_placeholder']);
            $table->dropIndex(['import_batch']);
            $table->dropColumn(['is_placeholder', 'import_batch']);
        });
    }
};
