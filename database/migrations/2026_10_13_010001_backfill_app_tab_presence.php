<?php

use App\Support\MobileCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Older choices predate the private hint; retain them on the upgraded reader. */
    public function up(): void
    {
        foreach (DB::table('app_bottom_tabs')->distinct()->pluck('masjid_id') as $id) {
            DB::transaction(function () use ($id) {
                $row=DB::table('masjids')->where('id',$id)->lockForUpdate()->first();
                if ($row === null) return;
                $overrides=json_decode($row->capability_overrides ?? 'null',true) ?? [];
                $overrides['_app_bottom_tabs_present']=true;
                DB::table('masjids')->where('id',$id)->update(['capability_overrides'=>json_encode($overrides)]);
                DB::afterCommit(fn()=>MobileCache::flushMasjid((int)$id,MobileCache::SHOW));
            });
        }
    }
    public function down(): void
    {
        // The internal JSON hint is harmless to the prior code; preserve the choices and overrides.
    }
};
