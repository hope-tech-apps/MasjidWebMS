<?php

namespace App\Support;

use App\Models\HifzEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A student's ḥifẓ progress, DERIVED from their recitation entries (PLAN T-014).
 *
 * ## Why this exists at all
 *
 * Nothing in this module stores "where student X is". `hifz_entries` is the only
 * writer of anything, and the position is read back out of it here. That is the
 * whole design decision: a denormalised `current_surah` / `current_ayah` would
 * need every writer guarded the way `registrations.registration_count` had to
 * be, and it would still be wrong in the case that matters most — a mis-recorded
 * sabak that a teacher strikes has to move the position BACKWARDS, and a stored
 * column quietly would not.
 *
 * ## What "position" means, and what it does not
 *
 *   - ONLY SABAK ADVANCES A STUDENT. Sabqi and manzil are revision of
 *     memorisation already held, so a manzil entry over al-Baqarah says nothing
 *     about where a child memorising juz 30 has reached. Revision is reported
 *     separately, as COVERAGE over a window, which is the question a teacher
 *     actually asks of it ("has this been revised lately?").
 *   - CURRENT position is the LATEST sabak entry, not the furthest one. A child
 *     sent back to re-take an earlier lesson is at that lesson today; reporting
 *     the high-water mark instead would tell a parent their child is somewhere
 *     they are not. Both are served, because they are different facts and
 *     conflating them is the lie.
 *   - HOW MUCH is memorised is the union of every sabak range, never a
 *     percentage. Progress in ḥifẓ is a position in the muṣḥaf; "62% of the
 *     Qur'an" is a number no ḥalaqa uses and no ijāza recognises.
 *   - JUZ COMPLETION is per-juz coverage, not position ÷ juz length, because
 *     ḥifẓ is commonly memorised from juz 30 backwards. A linear reading would
 *     report thirty completed juz for a beginner who started at an-Naba.
 *
 * ## Privacy
 *
 * This class NEVER decides who may see anything. It is handed a query that
 * App\Support\GroupAudience has already constrained, so an entry the caller may
 * not read is never fetched and therefore never folded into a total — the same
 * arrangement the behaviour summary uses (T-013). Passing an unconstrained query
 * here would leak through the aggregates even though no row is serialized; do
 * not.
 *
 * The read is one query per student and the arithmetic is O(n log n) over that
 * student's own entries — a few thousand rows after years of daily ḥalaqa. It is
 * deliberately per student: there is no group-wide variant, for the same reason
 * T-013 ships no leaderboard.
 */
class HifzProgress
{
    /**
     * Summarise one student, from a query already constrained to them AND to
     * what the caller may read.
     *
     * `$windowDays` bounds the REVISION block only. The position and the
     * memorisation totals are deliberately never date-filtered: memorisation is
     * cumulative, and a "progress since March" figure would describe a student
     * who does not exist.
     *
     * @return array<string,mixed>
     */
    public static function summarize(Builder $entries, int $windowDays): array
    {
        /** @var Collection<int,HifzEntry> $all */
        $all = $entries
            ->orderBy('recited_at')
            ->orderBy('id')
            ->get();

        $sabak = $all->filter(fn (HifzEntry $entry) => $entry->isSabak())->values();

        return [
            'current_position' => self::currentPosition($sabak),
            'furthest_position' => self::furthestPosition($sabak),
            'memorized' => self::memorized($sabak),
            'revision' => self::revision($all, $windowDays),
            'totals' => self::totals($all),
        ];
    }

    /**
     * Where the student is TODAY: the end of the most recent sabak.
     *
     * The collection arrives ordered by (recited_at, id), so the last element is
     * the latest lesson even when two were entered for the same evening.
     *
     * @param  Collection<int,HifzEntry>  $sabak
     * @return array<string,mixed>|null
     */
    private static function currentPosition(Collection $sabak): ?array
    {
        $latest = $sabak->last();

        if ($latest === null) {
            return null;
        }

        $position = QuranIndex::describe((int) $latest->to_surah, (int) $latest->to_ayah);

        if ($position === null) {
            // A corrupt range must not be reported as a place in the muṣḥaf.
            return null;
        }

        return $position + [
            'entry_id' => $latest->id,
            'recited_at' => optional($latest->recited_at)->toIso8601String(),
            'quality' => $latest->quality(),
            // The lesson itself, so a teacher opening the record sees what was
            // last heard and not only where it ended.
            'from' => QuranIndex::describe((int) $latest->from_surah, (int) $latest->from_ayah),
        ];
    }

    /**
     * The furthest point ever reached through sabak — the high-water mark.
     *
     * Differs from the current position exactly when a student has been sent
     * back over earlier material, which is ordinary and is why both are served.
     *
     * @param  Collection<int,HifzEntry>  $sabak
     * @return array<string,mixed>|null
     */
    private static function furthestPosition(Collection $sabak): ?array
    {
        $furthest = null;
        $ordinal = 0;

        foreach ($sabak as $entry) {
            $span = $entry->span();

            if ($span !== null && $span[1] > $ordinal) {
                $ordinal = $span[1];
                $furthest = $entry;
            }
        }

        return $furthest === null
            ? null
            : QuranIndex::describe((int) $furthest->to_surah, (int) $furthest->to_ayah);
    }

    /**
     * How much this student holds: the union of every sabak range.
     *
     * @param  Collection<int,HifzEntry>  $sabak
     * @return array<string,mixed>
     */
    private static function memorized(Collection $sabak): array
    {
        $merged = QuranIndex::mergeSpans(self::spans($sabak));

        $juz = QuranIndex::completedJuz($merged);
        $surahs = QuranIndex::completedSurahs($merged);

        return [
            'ayahs' => QuranIndex::ayahsCovered($merged),
            // Served alongside so a client can render "231 of 6236" without
            // hardcoding the denominator — NOT so it can compute a percentage.
            'total_ayahs' => QuranIndex::TOTAL_AYAHS,
            'juz_completed' => count($juz),
            'juz_completed_list' => $juz,
            'surahs_completed' => count($surahs),
        ];
    }

    /**
     * What has been REVISED recently, per kind.
     *
     * Coverage rather than a count of sessions: "sabqi, 4 entries" says a
     * teacher heard four portions, while "sabqi, juz 29-30" says what is
     * actually under active revision — the thing a ḥalaqa's rotation is meant to
     * guarantee.
     *
     * @param  Collection<int,HifzEntry>  $all
     * @return array<string,mixed>
     */
    private static function revision(Collection $all, int $windowDays): array
    {
        $since = now()->subDays($windowDays);

        $recent = $all->filter(
            fn (HifzEntry $entry) => in_array($entry->kind(), HifzEntry::REVISION_KINDS, true)
                && $entry->recited_at !== null
                && $entry->recited_at->greaterThanOrEqualTo($since)
        );

        $coverage = [];

        foreach (HifzEntry::REVISION_KINDS as $kind) {
            $ofKind = $recent->filter(fn (HifzEntry $entry) => $entry->kind() === $kind);
            $merged = QuranIndex::mergeSpans(self::spans($ofKind));

            $coverage[$kind] = [
                'entries' => $ofKind->count(),
                'ayahs' => QuranIndex::ayahsCovered($merged),
                'juz' => QuranIndex::juzTouched($merged),
            ];
        }

        return [
            'window_days' => $windowDays,
            'since' => $since->toDateString(),
        ] + $coverage;
    }

    /**
     * Lifetime counts across every kind, plus the mistake tallies.
     *
     * Major and minor stay separate here as they are in the table: a wrong word
     * and a tajwīd refinement are different lessons, and a single "mistakes"
     * number would tell a parent nothing about which.
     *
     * @param  Collection<int,HifzEntry>  $all
     * @return array<string,mixed>
     */
    private static function totals(Collection $all): array
    {
        $byKind = array_fill_keys(HifzEntry::KINDS, 0);

        foreach ($all as $entry) {
            $byKind[$entry->kind()]++;
        }

        return [
            'entries' => $all->count(),
            'by_kind' => $byKind,
            'major_mistakes' => (int) $all->sum('major_mistakes'),
            'minor_mistakes' => (int) $all->sum('minor_mistakes'),
        ];
    }

    /**
     * The absolute spans of a set of entries, dropping any whose stored
     * coordinates do not describe real ayahs — a corrupt row contributes
     * nothing rather than a nonsense span.
     *
     * @param  Collection<int,HifzEntry>  $entries
     * @return array<int,array{0:int,1:int}>
     */
    private static function spans(Collection $entries): array
    {
        return $entries
            ->map(fn (HifzEntry $entry) => $entry->span())
            ->filter()
            ->values()
            ->all();
    }
}
