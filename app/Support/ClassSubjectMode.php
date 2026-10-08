<?php

namespace App\Support;

use App\Models\Masjid;

/** Feature dispatch only. No group/staff/subject lookup is allowed after an OFF result. */
final class ClassSubjectMode
{
    public static function enabled(int|string|null $masjidId, ?Masjid $loaded = null): bool
    {
        if ($loaded !== null && (int) $loaded->getKey() === (int) $masjidId) return SchoolSettings::classSubjects($loaded);
        if ($masjidId === null) return false;
        // Archived parents still exist for FK purposes; the OFF path must not fail on them.
        $org = Masjid::withTrashed()->select(['id', 'org_type', 'capability_overrides'])->find((int) $masjidId);
        return SchoolSettings::classSubjects($org);
    }

    public static function forGroup(int $groupId): bool
    {
        $bound = app(TenantContext::class)->get();
        if ($bound !== null) return self::enabled($bound);
        // System/direct callers have only a group id. This is the capability read itself.
        $org = Masjid::withTrashed()->join('groups', 'groups.masjid_id', '=', 'masjids.id')
            ->where('groups.id', $groupId)->select(['masjids.id', 'masjids.org_type', 'masjids.capability_overrides'])->first();
        return SchoolSettings::classSubjects($org);
    }
}
