<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Layer 1 of the access model: what an organisation HAS (config/capabilities.php).
 *
 * Only a SuperAdmin's explicit decisions are stored — {"web_pages": true} —
 * and anything absent falls back to the catalogue default for the
 * organisation's org_type. That is what lets this ship without a backfill:
 * every existing organisation keeps exactly what it could reach before.
 *
 * Nullable JSON rather than a table: it is read once per request with the
 * masjid row the tenant gate already loads, it has one writer
 * (MasjidsController::setCapability, SuperAdmin-only), and masjids.updated_by
 * already records who changed it. NOT fillable, and denylisted from the public
 * directory payload (Masjid::PUBLIC_DIRECTORY_DENYLIST) — it describes the
 * organisation's account, not its public identity.
 *
 * Blueprint only — identical on MySQL and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->json('capability_overrides')->nullable()->after('assistant_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('masjids', function (Blueprint $table) {
            $table->dropColumn('capability_overrides');
        });
    }
};
