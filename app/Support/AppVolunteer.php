<?php

namespace App\Support;

use App\Models\{AppVolunteerSetting, Masjid};
use Illuminate\Support\Facades\DB;

final class AppVolunteer
{
    public static function enabled(?Masjid $org): bool
    {
        return $org !== null && ! $org->trashed() && $org->hasCapability('app_volunteer_entry');
    }
    public static function payload(Masjid $org): ?array
    {
        if (!self::enabled($org)) return null;
        $row=AppVolunteerSetting::withoutMasjidScope()->where('masjid_id',$org->id)->first();
        return filled($row?->url) ? ['title'=>$row->title,'url'=>$row->url] : null;
    }
    public static function save(int $orgId, array $data): void
    {
        abort_unless(app(TenantContext::class)->get() === $orgId,403);
        DB::transaction(function () use ($orgId,$data) {
            $org=Masjid::whereKey($orgId)->lockForUpdate()->firstOrFail(); abort_unless(self::enabled($org),404);
            AppVolunteerSetting::updateOrCreate(['masjid_id'=>$orgId],['title'=>$data['title'] ?? 'Volunteer','url'=>$data['url'] ?? null]);
            DB::afterCommit(fn()=>MobileCache::flushMasjid($orgId,MobileCache::SHOW));
        });
    }
}
