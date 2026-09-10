<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire users.can_manage_web_pages (added earlier the same day, 6f5dbd6) in
 * favour of the organisation capability `web_pages`.
 *
 * The per-account grant was a stopgap for one MEC administrator. Under the
 * layered model an administrator has everything their organisation has, so
 * the right carrier is the organisation. Before the column goes, every
 * organisation where someone held the grant — by membership or ownership — is
 * given the capability, so nobody who could open Web Pages before this runs
 * loses it.
 *
 * down() restores the column (empty): the grant it carried now lives on the
 * organisation and is not moved back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'can_manage_web_pages')) {
            return;
        }

        $granted = DB::table('users')->where('can_manage_web_pages', true)->pluck('id');

        $masjidIds = DB::table('masjid_user')->whereIn('user_id', $granted)->pluck('masjid_id')
            ->merge(DB::table('masjids')->whereIn('user_id', $granted)->pluck('id'))
            ->unique()
            ->values();

        foreach ($masjidIds as $masjidId) {
            $current = DB::table('masjids')->where('id', $masjidId)->value('capability_overrides');
            $overrides = is_string($current) ? (json_decode($current, true) ?: []) : [];
            $overrides['web_pages'] = true;

            DB::table('masjids')->where('id', $masjidId)->update([
                'capability_overrides' => json_encode($overrides),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_manage_web_pages');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'can_manage_web_pages')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_manage_web_pages')->default(false)->after('type');
        });
    }
};
