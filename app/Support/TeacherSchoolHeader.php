<?php

namespace App\Support;

use App\Models\Masjid;
use App\Models\SchoolYear;

/**
 * What the teacher shell's header shows about a school: its name, logo, type and
 * whether it has published a calendar.
 *
 * One builder for the two endpoints that serve it, so they cannot drift:
 *
 *   - `GET /api/teacher/masjids/{masjid_id}/school` — the school the SERVER BOUND
 *     for this request (the shell's source of truth, docs/multi-tenant-admin-design.md
 *     §4), and
 *   - the teacher branch of `GET /api/teacher/user`, which names the DEFAULT
 *     membership and is kept for compatibility.
 *
 * A header built from the default membership is the failure the admin work exists
 * to prevent: for a teacher at two schools it would paint school A's name over
 * school B's classes.
 */
final class TeacherSchoolHeader
{
    /**
     * @return array{id: int, name: string, logo_url: string|null, org_type: string|null, school_calendar_published: bool}
     */
    public static function for(Masjid $masjid): array
    {
        $logo = $masjid->logo()->first();

        return [
            'id' => (int) $masjid->id,
            'name' => (string) $masjid->name,
            // The shell reads `logo_url` and falls back to the Manara mark. Flattened
            // here rather than as an $appends on Masjid, which would widen the public
            // and mobile payloads too.
            'logo_url' => $logo?->original_url,
            'org_type' => $masjid->org_type,
            'school_calendar_published' => self::calendarPublished((int) $masjid->id),
        ];
    }

    /**
     * Whether the teacher shell offers its Calendar link: the school has at least
     * one school year. A fact about the school rather than the teacher. The school
     * is named explicitly because `/teacher/user` binds no tenant.
     */
    public static function calendarPublished(int $masjidId): bool
    {
        return SchoolYear::query()->where('masjid_id', $masjidId)->exists();
    }
}
