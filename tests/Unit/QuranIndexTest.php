<?php

namespace Tests\Unit;

use App\Support\QuranIndex;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Qur'anic coordinate system behind ḥifẓ tracking (PLAN T-014).
 *
 * QuranIndex is a hand-written table of 114 ayah counts and 30 juz boundaries,
 * and everything downstream — range validation, current position, juz completion
 * — is arithmetic over it. A single mistyped number would therefore not fail
 * loudly; it would quietly refuse a real ayah a teacher tried to record, or
 * report a juz complete that is not. These tests are the checksum on that table.
 *
 * The strongest of them is the total: 6236 ayahs in the Kufan/Ḥafṣ counting is a
 * number no typo in the table can survive.
 *
 * No database — this class reads nothing but its own constants.
 */
class QuranIndexTest extends TestCase
{
    #[Test]
    public function the_mushaf_has_one_hundred_and_fourteen_surahs_and_6236_ayahs(): void
    {
        $this->assertCount(114, QuranIndex::SURAHS);

        $total = array_sum(array_column(QuranIndex::SURAHS, 'ayahs'));

        // The checksum on the whole table. See the class docblock for the
        // counting convention this belongs to.
        $this->assertSame(QuranIndex::TOTAL_AYAHS, $total);
        $this->assertSame(6236, $total);
    }

    #[Test]
    public function the_surah_table_is_contiguous_and_every_entry_is_complete(): void
    {
        $this->assertSame(range(1, 114), array_keys(QuranIndex::SURAHS));

        foreach (QuranIndex::SURAHS as $number => $surah) {
            $this->assertArrayHasKey('name', $surah, "Surah {$number} has no name");
            $this->assertNotSame('', $surah['name']);
            $this->assertGreaterThanOrEqual(3, $surah['ayahs'], "Surah {$number} has too few ayahs");
        }
    }

    #[Test]
    public function the_familiar_landmarks_are_where_they_should_be(): void
    {
        // Spot checks a ḥāfiẓ would catch instantly if they were wrong.
        $this->assertSame(7, QuranIndex::ayahsIn(1));
        $this->assertSame(286, QuranIndex::ayahsIn(2));
        $this->assertSame(6, QuranIndex::ayahsIn(114));
        $this->assertSame('Al-Fatihah', QuranIndex::name(1));
        $this->assertSame('An-Nas', QuranIndex::name(114));

        // The two ends of the ordinal scale.
        $this->assertSame(1, QuranIndex::ordinal(1, 1));
        $this->assertSame(QuranIndex::TOTAL_AYAHS, QuranIndex::ordinal(114, 6));
        // Al-Baqarah begins right after al-Fātiḥah's seven.
        $this->assertSame(8, QuranIndex::ordinal(2, 1));
    }

    #[Test]
    public function a_coordinate_that_does_not_exist_is_refused_rather_than_guessed(): void
    {
        // Al-Fātiḥah has seven ayahs; there is no eighth.
        $this->assertFalse(QuranIndex::isAyah(1, 8));
        $this->assertNull(QuranIndex::ordinal(1, 8));
        $this->assertNull(QuranIndex::describe(1, 8));

        // Nor a surah 0, a surah 115, or an ayah 0.
        $this->assertFalse(QuranIndex::isSurah(0));
        $this->assertFalse(QuranIndex::isSurah(115));
        $this->assertNull(QuranIndex::ayahsIn(115));
        $this->assertNull(QuranIndex::ordinal(2, 0));
    }

    #[Test]
    public function the_thirty_juz_start_in_order_and_partition_the_whole_mushaf(): void
    {
        $this->assertCount(30, QuranIndex::JUZ_STARTS);
        $this->assertSame(range(1, 30), array_keys(QuranIndex::JUZ_STARTS));

        $previousEnd = 0;

        for ($juz = 1; $juz <= 30; $juz++) {
            [$start, $end] = QuranIndex::juzSpan($juz);

            // No gap and no overlap: each juz begins exactly where the last one
            // ended. A partition, not thirty independent ranges.
            $this->assertSame($previousEnd + 1, $start, "Juz {$juz} does not begin where juz " . ($juz - 1) . ' ends');
            $this->assertGreaterThan($start, $end);

            $previousEnd = $end;
        }

        // And together they cover the muṣḥaf exactly.
        $this->assertSame(QuranIndex::TOTAL_AYAHS, $previousEnd);
    }

    #[Test]
    public function an_ayah_reports_the_juz_it_actually_falls_in(): void
    {
        $this->assertSame(1, QuranIndex::juzFor(1, 1));
        // Juz 2 begins at al-Baqarah 142, so 141 is still juz 1.
        $this->assertSame(1, QuranIndex::juzFor(2, 141));
        $this->assertSame(2, QuranIndex::juzFor(2, 142));
        // Juz 30 begins at an-Naba 1 and runs to the end.
        $this->assertSame(30, QuranIndex::juzFor(78, 1));
        $this->assertSame(30, QuranIndex::juzFor(114, 6));
        // Āyat al-Kursī sits in juz 3.
        $this->assertSame(3, QuranIndex::juzFor(2, 255));
    }

    // ------------------------------------------------------------- coverage

    #[Test]
    public function overlapping_and_touching_ranges_merge_into_one(): void
    {
        // Monday 1-10, Tuesday 5-20: twenty ayahs memorised, not twenty-six.
        $merged = QuranIndex::mergeSpans([[1, 10], [5, 20]]);

        $this->assertSame([[1, 20]], $merged);
        $this->assertSame(20, QuranIndex::ayahsCovered($merged));

        // Touching ranges join too — 1-10 then 11-20 is a continuous twenty,
        // and leaving them apart would make juz completion unreachable.
        $this->assertSame([[1, 20]], QuranIndex::mergeSpans([[1, 10], [11, 20]]));

        // A genuine gap survives as a gap.
        $this->assertSame([[1, 10], [21, 30]], QuranIndex::mergeSpans([[21, 30], [1, 10]]));
        $this->assertSame(20, QuranIndex::ayahsCovered(QuranIndex::mergeSpans([[21, 30], [1, 10]])));

        $this->assertSame([], QuranIndex::mergeSpans([]));
        $this->assertSame(0, QuranIndex::ayahsCovered([]));
    }

    #[Test]
    public function a_juz_counts_as_complete_only_when_every_ayah_of_it_is_covered(): void
    {
        [$start, $end] = QuranIndex::juzSpan(30);

        $this->assertSame([30], QuranIndex::completedJuz([[$start, $end]]));
        // One ayah short is not a completed juz.
        $this->assertSame([], QuranIndex::completedJuz([[$start, $end - 1]]));
        // Touched, though — which is the revision question, not the completion one.
        $this->assertSame([30], QuranIndex::juzTouched([[$start, $end - 1]]));
    }

    #[Test]
    public function completion_is_coverage_so_memorising_backwards_reports_the_truth(): void
    {
        // The common case this module must get right: a beginner starts at juz
        // 30 and works BACKWARDS. Their absolute position is near the end of the
        // muṣḥaf, so any "position ÷ juz length" reading would claim thirty
        // completed juz. Coverage says one.
        [$start29] = QuranIndex::juzSpan(29);
        [, $end30] = QuranIndex::juzSpan(30);
        [$start30] = QuranIndex::juzSpan(30);

        $this->assertSame([30], QuranIndex::completedJuz([[$start30, $end30]]));

        // Then juz 29 as well: two completed, and they merge into one span.
        $merged = QuranIndex::mergeSpans([[$start30, $end30], [$start29, $start30 - 1]]);
        $this->assertSame([29, 30], QuranIndex::completedJuz($merged));
    }

    #[Test]
    public function completed_surahs_are_counted_the_same_way(): void
    {
        [$start, $end] = QuranIndex::surahSpan(112);

        $this->assertSame([112], QuranIndex::completedSurahs([[$start, $end]]));
        $this->assertSame([], QuranIndex::completedSurahs([[$start, $end - 1]]));

        // The last four surahs together.
        [$startOf111] = QuranIndex::surahSpan(111);
        $this->assertSame(
            [111, 112, 113, 114],
            QuranIndex::completedSurahs([[$startOf111, QuranIndex::TOTAL_AYAHS]])
        );
    }

    #[Test]
    public function a_described_coordinate_carries_its_name_and_its_juz(): void
    {
        $described = QuranIndex::describe(78, 40);

        $this->assertSame(78, $described['surah']);
        $this->assertSame('An-Naba', $described['surah_name']);
        $this->assertSame(40, $described['ayah']);
        $this->assertSame(30, $described['juz']);
        $this->assertSame(QuranIndex::ordinal(78, 40), $described['ordinal']);
    }
}
