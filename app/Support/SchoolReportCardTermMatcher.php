<?php

namespace App\Support;

use App\Models\{ReportCard, SchoolYear, SchoolTerm};

/** Exact stored labels, using ReportCardController::period's accepted text shape. */
final class SchoolReportCardTermMatcher
{
    /** Caller takes the organisation PK mutex before requesting parent record locks. */
    public static function match(int $org, string $text, int $position, bool $lock = false): array
    {
        if (! preg_match('/^\d{4}-\d{4}$/', $text)) return ['year'=>null,'term'=>null,'reason'=>'no such year'];
        // Compare in PHP: MySQL's text collation must not loosen an exact stored-text match.
        $matches = SchoolYear::query()->where('masjid_id', $org)->orderBy('id')->get()
            ->filter(fn ($year) => $year->label === $text)->values();
        if ($matches->isEmpty()) return ['year'=>null,'term'=>null,'reason'=>'no such year'];
        if ($matches->count() !== 1) return ['year'=>null,'term'=>null,'reason'=>'two years match'];
        $year = $matches->first();
        if ($lock) $year = SchoolYear::query()->where('masjid_id',$org)->whereKey($year->id)->lockForUpdate()->firstOrFail();
        if (in_array($year->term_system, ['semesters','trimesters'], true)) return ['year'=>$year,'term'=>null,'reason'=>'year is on '.$year->term_system];
        $term = $year->term_system === 'quarters' && in_array($position, ReportCard::TERMS, true)
            ? SchoolTerm::query()->where('masjid_id',$org)->where('school_year_id',$year->id)->where('position',$position)->first() : null;
        // Equality on existing PRIMARY only: X record lock, no nonunique range/gap lock.
        if ($term && $lock) $term = SchoolTerm::query()->where('masjid_id',$org)->whereKey($term->id)->lockForUpdate()->firstOrFail();
        return ['year'=>$year,'term'=>$term,'reason'=>$term ? null : 'year has no quarter '.$position];
    }
}
