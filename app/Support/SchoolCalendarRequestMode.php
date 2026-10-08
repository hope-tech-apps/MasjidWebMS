<?php

namespace App\Support;

use App\Models\Masjid;
use Illuminate\Database\QueryException;

/** Memoises only while the HTTP kernel middleware owns the current request. */
final class SchoolCalendarRequestMode
{
    private const ATTRIBUTE = 'school_calendar_terms_decisions';
    private const ROWS = 'school_calendar_terms_rows';

    private static function httpRequest(): bool
    {
        return app()->bound('request') && request()->attributes->get('school_calendar_terms_http') === true;
    }

    public static function remember(Masjid $org): void
    {
        if (! self::httpRequest()) return;
        // Partial projections cannot establish the capability. Full rows loaded
        // by main's middleware, owner relation or calendar can all establish it.
        $attributes = $org->getAttributes();
        if (! $org->id || ! array_key_exists('org_type', $attributes) || ! array_key_exists('capability_overrides', $attributes)) return;
        // An archived school is off, and a row that says so settles it without another read.
        if (array_key_exists('deleted_at', $attributes) && $org->trashed()) {
            $decisions = request()->attributes->get(self::ATTRIBUTE, []);
            $decisions[(int) $org->id] ??= false;
            request()->attributes->set(self::ATTRIBUTE, $decisions);
            return;
        }
        // A projection without deleted_at cannot say whether the school is archived.
        if (! array_key_exists('deleted_at', $attributes)) return;
        // Capture without resolving on every row of unrelated organisation lists.
        $rows = request()->attributes->get(self::ROWS, []);
        $rows[(int) $org->id] ??= $org;
        request()->attributes->set(self::ROWS, $rows);
    }

    public static function enabled(int $id): bool
    {
        $decisions = self::httpRequest() ? request()->attributes->get(self::ATTRIBUTE, []) : [];
        if (array_key_exists($id, $decisions)) return $decisions[$id];
        $rows = self::httpRequest() ? request()->attributes->get(self::ROWS, []) : [];
        if (isset($rows[$id])) return self::set($id, SchoolSettings::calendarTerms($rows[$id]));
        try {
            // Nonlocking primary-key read, only if main has not loaded this row
            // before dispatch. Missing organisations must reach main's own path.
            $org = Masjid::find($id);
        } catch (QueryException) {
            // This optional read must not substitute its failure for main's
            // validation/404/write error. Main's own statements still execute.
            $org = null;
        }
        return self::set($id, SchoolSettings::calendarTerms($org));
    }

    /** Called only after SchoolCalendar::for has already attempted main's read. */
    public static function afterLegacyRead(int $id): bool
    {
        if (! self::httpRequest()) return self::enabled($id);
        $decisions = request()->attributes->get(self::ATTRIBUTE, []);
        if (array_key_exists($id, $decisions)) return $decisions[$id];
        $rows = request()->attributes->get(self::ROWS, []);
        return self::set($id, SchoolSettings::calendarTerms($rows[$id] ?? null));
    }

    public static function set(int $id, bool $enabled): bool
    {
        if (! self::httpRequest()) return $enabled;
        $decisions = request()->attributes->get(self::ATTRIBUTE, []);
        $decisions[$id] = $enabled;
        request()->attributes->set(self::ATTRIBUTE, $decisions);
        return $enabled;
    }

    /** A switch changed by this request must be visible to its own response. */
    public static function forget(int $id): void
    {
        if (! self::httpRequest()) return;
        SchoolCalendarReaders::forget($id);
        $decisions = request()->attributes->get(self::ATTRIBUTE, []);
        unset($decisions[$id]);
        request()->attributes->set(self::ATTRIBUTE, $decisions);
        $rows = request()->attributes->get(self::ROWS, []);
        unset($rows[$id]);
        request()->attributes->set(self::ROWS, $rows);
    }
}
