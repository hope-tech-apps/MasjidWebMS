<?php

namespace App\Support;

/**
 * The Qur'anic coordinate system — surah -> ayah counts, and the 30 juz
 * boundaries laid over them (PLAN T-014).
 *
 * ## Why this is a PHP class and not a seeded table
 *
 * Ḥifẓ tracking needs three things from the muṣḥaf and nothing more: is this a
 * real ayah, does this range run forwards, and which juz does it fall in. That
 * is 114 numbers plus 30 boundaries — reference data that has been fixed for
 * fourteen centuries and cannot be edited by a tenant, imported, or versioned.
 * Putting it in a migration-seeded table would buy nothing and cost a great
 * deal: every validation would become a query, a half-seeded tenant would
 * silently validate nothing, and a table an admin can reach is a table an admin
 * can corrupt. `.claude/rules/verticals.md` makes the same call for org types
 * and `GroupMembership::ROLES` for roles — a fixed set is a PHP constant.
 *
 * This is deliberately NOT a Qur'an text store. There is no Arabic text here, no
 * translation, no word index and no page map — only what the tracker must
 * arithmetic over. A recitation record says WHERE a student recited, never what
 * the words are.
 *
 * ## Source and counting convention
 *
 * Ayah counts follow the KUFAN counting transmitted with Ḥafṣ ʿan ʿĀṣim — the
 * reckoning of the standard Madani muṣḥaf, and the one every ḥifẓ classroom this
 * module serves recites by. Its checksum is the familiar total of 6236 ayahs,
 * asserted in tests/Unit/QuranIndexTest.php so a typo in the table below cannot
 * ship. Other recognised countings (Basran, Damascene, Meccan) differ by a
 * handful of ayah divisions; if a tenant ever needs one, it is a second table
 * chosen per tenant, NOT an edit to this one.
 *
 * Juz boundaries are the thirty standard starting points of the same muṣḥaf.
 * They are stored as (surah, ayah) pairs rather than absolute numbers so they
 * stay checkable by eye against a printed muṣḥaf.
 *
 * ## Positions are ORDINALS, never percentages
 *
 * Everything downstream compares positions by their absolute ayah index (1 for
 * al-Fātiḥah:1 through 6236 for an-Nās:6), which is what makes "did this range
 * run backwards?", "is juz 30 complete?" and "how much is memorised?" ordinary
 * arithmetic. A student's progress is a POSITION in the muṣḥaf; this class never
 * produces a percentage, and nothing built on it should either.
 */
class QuranIndex
{
    /** Total ayahs in the Kufan/Ḥafṣ counting — the checksum on the table below. */
    public const TOTAL_AYAHS = 6236;

    /** Number of juz the muṣḥaf is divided into. */
    public const TOTAL_JUZ = 30;

    /**
     * Every surah in order: its common transliterated name and its ayah count.
     *
     * The name is carried alongside the count so an API response can say
     * "An-Naba" and not merely "78". Without it every client — the Vue admin,
     * the parent app, a CSV export — would have to keep its own list of 114
     * names, and the first one that drifts turns an off-by-one surah into an
     * invisible error in a child's record.
     *
     * @var array<int,array{name:string,ayahs:int}>
     */
    public const SURAHS = [
        1 => ['name' => 'Al-Fatihah', 'ayahs' => 7],
        2 => ['name' => 'Al-Baqarah', 'ayahs' => 286],
        3 => ['name' => "Ali 'Imran", 'ayahs' => 200],
        4 => ['name' => 'An-Nisa', 'ayahs' => 176],
        5 => ['name' => "Al-Ma'idah", 'ayahs' => 120],
        6 => ['name' => "Al-An'am", 'ayahs' => 165],
        7 => ['name' => "Al-A'raf", 'ayahs' => 206],
        8 => ['name' => 'Al-Anfal', 'ayahs' => 75],
        9 => ['name' => 'At-Tawbah', 'ayahs' => 129],
        10 => ['name' => 'Yunus', 'ayahs' => 109],
        11 => ['name' => 'Hud', 'ayahs' => 123],
        12 => ['name' => 'Yusuf', 'ayahs' => 111],
        13 => ['name' => "Ar-Ra'd", 'ayahs' => 43],
        14 => ['name' => 'Ibrahim', 'ayahs' => 52],
        15 => ['name' => 'Al-Hijr', 'ayahs' => 99],
        16 => ['name' => 'An-Nahl', 'ayahs' => 128],
        17 => ['name' => 'Al-Isra', 'ayahs' => 111],
        18 => ['name' => 'Al-Kahf', 'ayahs' => 110],
        19 => ['name' => 'Maryam', 'ayahs' => 98],
        20 => ['name' => 'Ta-Ha', 'ayahs' => 135],
        21 => ['name' => 'Al-Anbiya', 'ayahs' => 112],
        22 => ['name' => 'Al-Hajj', 'ayahs' => 78],
        23 => ['name' => "Al-Mu'minun", 'ayahs' => 118],
        24 => ['name' => 'An-Nur', 'ayahs' => 64],
        25 => ['name' => 'Al-Furqan', 'ayahs' => 77],
        26 => ['name' => "Ash-Shu'ara", 'ayahs' => 227],
        27 => ['name' => 'An-Naml', 'ayahs' => 93],
        28 => ['name' => 'Al-Qasas', 'ayahs' => 88],
        29 => ['name' => "Al-'Ankabut", 'ayahs' => 69],
        30 => ['name' => 'Ar-Rum', 'ayahs' => 60],
        31 => ['name' => 'Luqman', 'ayahs' => 34],
        32 => ['name' => 'As-Sajdah', 'ayahs' => 30],
        33 => ['name' => 'Al-Ahzab', 'ayahs' => 73],
        34 => ['name' => 'Saba', 'ayahs' => 54],
        35 => ['name' => 'Fatir', 'ayahs' => 45],
        36 => ['name' => 'Ya-Sin', 'ayahs' => 83],
        37 => ['name' => 'As-Saffat', 'ayahs' => 182],
        38 => ['name' => 'Sad', 'ayahs' => 88],
        39 => ['name' => 'Az-Zumar', 'ayahs' => 75],
        40 => ['name' => 'Ghafir', 'ayahs' => 85],
        41 => ['name' => 'Fussilat', 'ayahs' => 54],
        42 => ['name' => 'Ash-Shura', 'ayahs' => 53],
        43 => ['name' => 'Az-Zukhruf', 'ayahs' => 89],
        44 => ['name' => 'Ad-Dukhan', 'ayahs' => 59],
        45 => ['name' => 'Al-Jathiyah', 'ayahs' => 37],
        46 => ['name' => 'Al-Ahqaf', 'ayahs' => 35],
        47 => ['name' => 'Muhammad', 'ayahs' => 38],
        48 => ['name' => 'Al-Fath', 'ayahs' => 29],
        49 => ['name' => 'Al-Hujurat', 'ayahs' => 18],
        50 => ['name' => 'Qaf', 'ayahs' => 45],
        51 => ['name' => 'Adh-Dhariyat', 'ayahs' => 60],
        52 => ['name' => 'At-Tur', 'ayahs' => 49],
        53 => ['name' => 'An-Najm', 'ayahs' => 62],
        54 => ['name' => 'Al-Qamar', 'ayahs' => 55],
        55 => ['name' => 'Ar-Rahman', 'ayahs' => 78],
        56 => ['name' => "Al-Waqi'ah", 'ayahs' => 96],
        57 => ['name' => 'Al-Hadid', 'ayahs' => 29],
        58 => ['name' => 'Al-Mujadilah', 'ayahs' => 22],
        59 => ['name' => 'Al-Hashr', 'ayahs' => 24],
        60 => ['name' => 'Al-Mumtahanah', 'ayahs' => 13],
        61 => ['name' => 'As-Saff', 'ayahs' => 14],
        62 => ['name' => "Al-Jumu'ah", 'ayahs' => 11],
        63 => ['name' => 'Al-Munafiqun', 'ayahs' => 11],
        64 => ['name' => 'At-Taghabun', 'ayahs' => 18],
        65 => ['name' => 'At-Talaq', 'ayahs' => 12],
        66 => ['name' => 'At-Tahrim', 'ayahs' => 12],
        67 => ['name' => 'Al-Mulk', 'ayahs' => 30],
        68 => ['name' => 'Al-Qalam', 'ayahs' => 52],
        69 => ['name' => 'Al-Haqqah', 'ayahs' => 52],
        70 => ['name' => "Al-Ma'arij", 'ayahs' => 44],
        71 => ['name' => 'Nuh', 'ayahs' => 28],
        72 => ['name' => 'Al-Jinn', 'ayahs' => 28],
        73 => ['name' => 'Al-Muzzammil', 'ayahs' => 20],
        74 => ['name' => 'Al-Muddaththir', 'ayahs' => 56],
        75 => ['name' => 'Al-Qiyamah', 'ayahs' => 40],
        76 => ['name' => 'Al-Insan', 'ayahs' => 31],
        77 => ['name' => 'Al-Mursalat', 'ayahs' => 50],
        78 => ['name' => 'An-Naba', 'ayahs' => 40],
        79 => ['name' => "An-Nazi'at", 'ayahs' => 46],
        80 => ['name' => "'Abasa", 'ayahs' => 42],
        81 => ['name' => 'At-Takwir', 'ayahs' => 29],
        82 => ['name' => 'Al-Infitar', 'ayahs' => 19],
        83 => ['name' => 'Al-Mutaffifin', 'ayahs' => 36],
        84 => ['name' => 'Al-Inshiqaq', 'ayahs' => 25],
        85 => ['name' => 'Al-Buruj', 'ayahs' => 22],
        86 => ['name' => 'At-Tariq', 'ayahs' => 17],
        87 => ['name' => "Al-A'la", 'ayahs' => 19],
        88 => ['name' => 'Al-Ghashiyah', 'ayahs' => 26],
        89 => ['name' => 'Al-Fajr', 'ayahs' => 30],
        90 => ['name' => 'Al-Balad', 'ayahs' => 20],
        91 => ['name' => 'Ash-Shams', 'ayahs' => 15],
        92 => ['name' => 'Al-Layl', 'ayahs' => 21],
        93 => ['name' => 'Ad-Duha', 'ayahs' => 11],
        94 => ['name' => 'Ash-Sharh', 'ayahs' => 8],
        95 => ['name' => 'At-Tin', 'ayahs' => 8],
        96 => ['name' => "Al-'Alaq", 'ayahs' => 19],
        97 => ['name' => 'Al-Qadr', 'ayahs' => 5],
        98 => ['name' => 'Al-Bayyinah', 'ayahs' => 8],
        99 => ['name' => 'Az-Zalzalah', 'ayahs' => 8],
        100 => ['name' => "Al-'Adiyat", 'ayahs' => 11],
        101 => ['name' => "Al-Qari'ah", 'ayahs' => 11],
        102 => ['name' => 'At-Takathur', 'ayahs' => 8],
        103 => ['name' => "Al-'Asr", 'ayahs' => 3],
        104 => ['name' => 'Al-Humazah', 'ayahs' => 9],
        105 => ['name' => 'Al-Fil', 'ayahs' => 5],
        106 => ['name' => 'Quraysh', 'ayahs' => 4],
        107 => ['name' => "Al-Ma'un", 'ayahs' => 7],
        108 => ['name' => 'Al-Kawthar', 'ayahs' => 3],
        109 => ['name' => 'Al-Kafirun', 'ayahs' => 6],
        110 => ['name' => 'An-Nasr', 'ayahs' => 3],
        111 => ['name' => 'Al-Masad', 'ayahs' => 5],
        112 => ['name' => 'Al-Ikhlas', 'ayahs' => 4],
        113 => ['name' => 'Al-Falaq', 'ayahs' => 5],
        114 => ['name' => 'An-Nas', 'ayahs' => 6],
    ];

    /**
     * Where each juz BEGINS, as [surah, ayah]. A juz runs up to the ayah before
     * the next one starts; the thirtieth runs to the end of the muṣḥaf.
     *
     * @var array<int,array{0:int,1:int}>
     */
    public const JUZ_STARTS = [
        1 => [1, 1],
        2 => [2, 142],
        3 => [2, 253],
        4 => [3, 93],
        5 => [4, 24],
        6 => [4, 148],
        7 => [5, 82],
        8 => [6, 111],
        9 => [7, 88],
        10 => [8, 41],
        11 => [9, 93],
        12 => [11, 6],
        13 => [12, 53],
        14 => [15, 1],
        15 => [17, 1],
        16 => [18, 75],
        17 => [21, 1],
        18 => [23, 1],
        19 => [25, 21],
        20 => [27, 56],
        21 => [29, 46],
        22 => [33, 31],
        23 => [36, 28],
        24 => [39, 32],
        25 => [41, 47],
        26 => [46, 1],
        27 => [51, 31],
        28 => [58, 1],
        29 => [67, 1],
        30 => [78, 1],
    ];

    /**
     * Running ayah offset before each surah, memoised on first use. Derived from
     * SURAHS rather than written out a second time: two hand-maintained tables
     * of the same fact drift, and the one that drifts silently is the one nobody
     * reads.
     *
     * @var array<int,int>|null
     */
    private static ?array $offsets = null;

    /** @var array<int,int>|null */
    private static ?array $juzStartOrdinals = null;

    public static function isSurah(int $surah): bool
    {
        return isset(self::SURAHS[$surah]);
    }

    /** How many ayahs surah `$surah` holds, or null if there is no such surah. */
    public static function ayahsIn(int $surah): ?int
    {
        return self::SURAHS[$surah]['ayahs'] ?? null;
    }

    public static function name(int $surah): ?string
    {
        return self::SURAHS[$surah]['name'] ?? null;
    }

    /** Is (surah, ayah) an ayah that actually exists? */
    public static function isAyah(int $surah, int $ayah): bool
    {
        $count = self::ayahsIn($surah);

        return $count !== null && $ayah >= 1 && $ayah <= $count;
    }

    /**
     * The absolute index of (surah, ayah) — 1 for al-Fātiḥah:1, 6236 for
     * an-Nās:6 — or null when the coordinate does not exist.
     *
     * This is the whole reason ranges, ordering checks and juz completion are
     * arithmetic rather than special cases.
     */
    public static function ordinal(int $surah, int $ayah): ?int
    {
        if (! self::isAyah($surah, $ayah)) {
            return null;
        }

        return self::offsets()[$surah] + $ayah;
    }

    /** Which juz (1..30) contains this ayah, or null for a coordinate that does not exist. */
    public static function juzFor(int $surah, int $ayah): ?int
    {
        $ordinal = self::ordinal($surah, $ayah);

        if ($ordinal === null) {
            return null;
        }

        $found = 1;

        foreach (self::juzStartOrdinals() as $juz => $start) {
            if ($ordinal >= $start) {
                $found = $juz;

                continue;
            }

            break;
        }

        return $found;
    }

    /**
     * The absolute [start, end] span of one juz, or null for a juz outside 1..30.
     *
     * @return array{0:int,1:int}|null
     */
    public static function juzSpan(int $juz): ?array
    {
        $starts = self::juzStartOrdinals();

        if (! isset($starts[$juz])) {
            return null;
        }

        $end = isset($starts[$juz + 1]) ? $starts[$juz + 1] - 1 : self::TOTAL_AYAHS;

        return [$starts[$juz], $end];
    }

    /**
     * The absolute [start, end] span of one surah, or null if there is no such
     * surah.
     *
     * @return array{0:int,1:int}|null
     */
    public static function surahSpan(int $surah): ?array
    {
        $count = self::ayahsIn($surah);

        if ($count === null) {
            return null;
        }

        $start = self::offsets()[$surah] + 1;

        return [$start, $start + $count - 1];
    }

    /**
     * One coordinate, described for a client: number, name, ayah and juz.
     *
     * Null when the coordinate does not exist, so a caller that somehow holds a
     * corrupt stored value renders nothing rather than inventing a surah.
     *
     * @return array<string,mixed>|null
     */
    public static function describe(int $surah, int $ayah): ?array
    {
        if (! self::isAyah($surah, $ayah)) {
            return null;
        }

        return [
            'surah' => $surah,
            'surah_name' => self::name($surah),
            'ayah' => $ayah,
            'juz' => self::juzFor($surah, $ayah),
            'ordinal' => self::ordinal($surah, $ayah),
        ];
    }

    /**
     * Collapse a set of absolute [start, end] spans into the smallest set of
     * disjoint spans covering the same ayahs.
     *
     * This is what makes "how much has this student memorised?" an honest
     * number: a child who recited al-Baqarah 1-10 on Monday and 5-20 on Tuesday
     * has memorised 20 ayahs, not 26. Touching spans are merged as well as
     * overlapping ones (`end + 1 >= next start`), because 1-10 followed by 11-20
     * is a continuous twenty ayahs, and reporting it as two blocks would make
     * juz completion unreachable in practice.
     *
     * @param  array<int,array{0:int,1:int}>  $spans
     * @return array<int,array{0:int,1:int}>
     */
    public static function mergeSpans(array $spans): array
    {
        $spans = array_values(array_filter(
            $spans,
            fn ($span) => is_array($span) && isset($span[0], $span[1]) && $span[0] <= $span[1]
        ));

        if ($spans === []) {
            return [];
        }

        usort($spans, fn (array $a, array $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $merged = [];
        $current = array_shift($spans);

        foreach ($spans as $span) {
            if ($span[0] <= $current[1] + 1) {
                $current[1] = max($current[1], $span[1]);

                continue;
            }

            $merged[] = $current;
            $current = $span;
        }

        $merged[] = $current;

        return $merged;
    }

    /**
     * How many ayahs a set of ALREADY MERGED spans covers.
     *
     * @param  array<int,array{0:int,1:int}>  $merged
     */
    public static function ayahsCovered(array $merged): int
    {
        $total = 0;

        foreach ($merged as $span) {
            $total += $span[1] - $span[0] + 1;
        }

        return $total;
    }

    /**
     * Which juz are COMPLETELY covered by these merged spans.
     *
     * Completeness is checked per juz rather than derived from a position,
     * because ḥifẓ is not always memorised front to back: a child who starts at
     * juz 30 and works backwards has completed juz 30 while their absolute
     * position is near the end of the muṣḥaf, and a linear "position ÷ juz
     * length" would report thirty completed juz for them. Coverage is the only
     * measure that is true for both orders.
     *
     * @param  array<int,array{0:int,1:int}>  $merged
     * @return array<int,int>
     */
    public static function completedJuz(array $merged): array
    {
        return self::completedUnits($merged, self::TOTAL_JUZ, fn (int $juz) => self::juzSpan($juz));
    }

    /**
     * Which surahs are completely covered by these merged spans — the measure a
     * younger student's family actually asks about ("she has finished Tabāraka").
     *
     * @param  array<int,array{0:int,1:int}>  $merged
     * @return array<int,int>
     */
    public static function completedSurahs(array $merged): array
    {
        return self::completedUnits($merged, count(self::SURAHS), fn (int $surah) => self::surahSpan($surah));
    }

    /**
     * Which juz these merged spans TOUCH at all — the revision question ("what
     * has been revised this month?"), as opposed to the completion question.
     *
     * @param  array<int,array{0:int,1:int}>  $merged
     * @return array<int,int>
     */
    public static function juzTouched(array $merged): array
    {
        $touched = [];

        for ($juz = 1; $juz <= self::TOTAL_JUZ; $juz++) {
            [$start, $end] = self::juzSpan($juz);

            foreach ($merged as $span) {
                if ($span[0] <= $end && $span[1] >= $start) {
                    $touched[] = $juz;

                    break;
                }
            }
        }

        return $touched;
    }

    /**
     * Shared body of completedJuz/completedSurahs: a unit counts only when ONE
     * merged span swallows it whole. Two adjacent spans cannot jointly complete
     * a unit, and they do not have to — mergeSpans() has already joined anything
     * that touches.
     *
     * @param  array<int,array{0:int,1:int}>  $merged
     * @param  callable(int):(array{0:int,1:int}|null)  $spanOf
     * @return array<int,int>
     */
    private static function completedUnits(array $merged, int $count, callable $spanOf): array
    {
        $complete = [];

        for ($unit = 1; $unit <= $count; $unit++) {
            $span = $spanOf($unit);

            if ($span === null) {
                continue;
            }

            foreach ($merged as $covered) {
                if ($covered[0] <= $span[0] && $covered[1] >= $span[1]) {
                    $complete[] = $unit;

                    break;
                }
            }
        }

        return $complete;
    }

    /** @return array<int,int> */
    private static function offsets(): array
    {
        if (self::$offsets === null) {
            $offsets = [];
            $running = 0;

            foreach (self::SURAHS as $number => $surah) {
                $offsets[$number] = $running;
                $running += $surah['ayahs'];
            }

            self::$offsets = $offsets;
        }

        return self::$offsets;
    }

    /** @return array<int,int> */
    private static function juzStartOrdinals(): array
    {
        if (self::$juzStartOrdinals === null) {
            $starts = [];

            foreach (self::JUZ_STARTS as $juz => [$surah, $ayah]) {
                $starts[$juz] = self::offsets()[$surah] + $ayah;
            }

            self::$juzStartOrdinals = $starts;
        }

        return self::$juzStartOrdinals;
    }
}
