<?php

namespace App\Support;

use App\Models\Masjid;

/** Feature dispatch only. No group/staff/subject lookup is allowed after an OFF result. */
final class ClassSubjectMode
{
    public const HTTP = 'class_subjects_http';
    private const DECISIONS = 'class_subjects_decisions';
    private const ROWS = 'class_subjects_rows';

    private static function http(): bool
    {
        return app()->bound('request') && request()->attributes->get(self::HTTP) === true;
    }

    public static function clear(\Illuminate\Http\Request $request): void
    {
        foreach ([self::HTTP, self::DECISIONS, self::ROWS] as $key) $request->attributes->remove($key);
    }

    /** Reuse complete rows main already loaded, without changing its statement order. */
    public static function rememberLoaded(Masjid $org): void
    {
        if (! self::http()) return;
        $attributes = $org->getAttributes();
        if (! $org->id || ! array_key_exists('org_type', $attributes) || ! array_key_exists('capability_overrides', $attributes)) return;
        $rows = request()->attributes->get(self::ROWS, []);
        $rows[(int) $org->id] ??= $org;
        request()->attributes->set(self::ROWS, $rows);
    }

    public static function forget(int $id): void
    {
        if (! self::http()) return;
        foreach ([self::DECISIONS, self::ROWS] as $key) {
            $values = request()->attributes->get($key, []);
            unset($values[$id]);
            request()->attributes->set($key, $values);
        }
    }

    public static function enabled(int|string|null $masjidId, ?Masjid $loaded = null): bool
    {
        if ($masjidId === null) return false;
        $id = (int) $masjidId;
        $decisions = self::http() ? request()->attributes->get(self::DECISIONS, []) : [];
        if (array_key_exists($id, $decisions)) return $decisions[$id];
        $rows = self::http() ? request()->attributes->get(self::ROWS, []) : [];
        $org = $loaded !== null && (int) $loaded->getKey() === $id ? $loaded : ($rows[$id] ?? null);
        // Archived parents still exist for FK purposes; the OFF path must not fail on them.
        // deleted_at rides along: the calendar's request memo reuses this row and must still refuse an archived one.
        $org ??= Masjid::withTrashed()->select(['id', 'org_type', 'capability_overrides', 'deleted_at'])->find($id);
        return self::rememberResponseMode($id, SchoolSettings::classSubjects($org));
    }

    /** No memo exists outside the HTTP middleware lifetime. ON writers still recheck under locks. */
    public static function rememberResponseMode(int $masjidId, bool $enabled): bool
    {
        if (! self::http()) return $enabled;
        $decisions = request()->attributes->get(self::DECISIONS, []);
        $decisions[$masjidId] = $enabled;
        request()->attributes->set(self::DECISIONS, $decisions);
        return $enabled;
    }

    public static function responseEnabled(int|string|null $masjidId): bool
    {
        return self::enabled($masjidId);
    }

    public static function responseForGroup(int $groupId): bool
    {
        $bound = app(TenantContext::class)->get();
        return $bound !== null ? self::responseEnabled($bound) : self::forGroup($groupId);
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
