<?php

namespace App\Support\Arabic;

/**
 * The Arabic qāʿidah: the twenty-eight letters, the vowel marks, and which of
 * them a class at a given stage is working on.
 *
 * A PORT of DeenQuest's `ArabicCurriculum`, deliberately kept identical in its
 * rules so a family using both does not meet two different alphabets. Where the
 * two differ is WHO the stage belongs to: DeenQuest asks the child's grade,
 * because the app is installed by one family; a school already models the grade
 * as the class the child sits in, so here the stage is a property of the group.
 *
 * ## The letter SHAPES are not stored
 *
 * Every letter takes up to four shapes depending on where it sits in a word.
 * Typing all 112 glyphs into a source file is 112 chances to paste a codepoint
 * that renders a letter which does not exist — and a wrong shape in a child's
 * alphabet is not a cosmetic bug, it teaches something false. Each shape is
 * instead produced by surrounding the letter with U+200D ZERO WIDTH JOINER,
 * which is precisely what the joiner is for: it tells the text engine that
 * something connects on that side, and the font selects the contextual form.
 *
 * Six letters — ا د ذ ر ز و — never join to what FOLLOWS them, so they have no
 * initial or medial shape. `connectsForward()` says so, and the UI shows two
 * shapes for them rather than inventing four.
 *
 * ## One place decides scope
 *
 * The class grid, one student's drill list and the progress denominator all ask
 * this class. If each decided for itself, a child could be shown a tanwīn drill
 * their progress bar did not count, and the bar would sit below 100% forever
 * with nothing visibly left to do.
 */
class ArabicCurriculum
{
    public const ZWJ = "\u{200D}";

    // ---------------------------------------------------------------- stages

    public const STAGE_LETTERS = 'letters';
    public const STAGE_SHORT_VOWELS = 'short_vowels';
    public const STAGE_SUKUN_SHADDA = 'sukun_shadda';
    public const STAGE_TANWEEN = 'tanween';
    public const STAGE_MADD = 'madd';

    /** In teaching order. A stage includes everything before it. */
    public const STAGES = [
        self::STAGE_LETTERS,
        self::STAGE_SHORT_VOWELS,
        self::STAGE_SUKUN_SHADDA,
        self::STAGE_TANWEEN,
        self::STAGE_MADD,
    ];

    public const STAGE_LABELS = [
        self::STAGE_LETTERS => 'The Letters',
        self::STAGE_SHORT_VOWELS => 'Short Vowels',
        self::STAGE_SUKUN_SHADDA => 'Sukun & Shadda',
        self::STAGE_TANWEEN => 'Tanween',
        self::STAGE_MADD => 'Long Vowels',
    ];

    public const STAGE_SUMMARIES = [
        self::STAGE_LETTERS => 'Recognise and name all 28 letters, and the shapes they take in a word.',
        self::STAGE_SHORT_VOWELS => 'Fatha, kasra and damma — the three short vowel sounds.',
        self::STAGE_SUKUN_SHADDA => 'Sukun stops a letter; shadda doubles it.',
        self::STAGE_TANWEEN => 'The doubled endings: an, in, un.',
        self::STAGE_MADD => 'Alif, waw and ya stretching a vowel to two counts.',
    ];

    // -------------------------------------------------------------- statuses

    public const STATUS_NOT_STARTED = 'not_started';
    public const STATUS_LEARNING = 'learning';
    public const STATUS_MASTERED = 'mastered';

    public const STATUSES = [self::STATUS_NOT_STARTED, self::STATUS_LEARNING, self::STATUS_MASTERED];

    // --------------------------------------------------------------- letters

    /**
     * The 28 letters in hijāʾī order: id => [glyph, arabic name, transliteration,
     * joins to the next letter?].
     */
    public const LETTERS = [
        'alif'  => ['ا', 'أَلِف', 'alif',  false],
        'ba'    => ['ب', 'بَاء',  'bāʾ',   true],
        'ta'    => ['ت', 'تَاء',  'tāʾ',   true],
        'tha'   => ['ث', 'ثَاء',  'thāʾ',  true],
        'jim'   => ['ج', 'جِيم',  'jīm',   true],
        'haa'   => ['ح', 'حَاء',  'ḥāʾ',   true],
        'kha'   => ['خ', 'خَاء',  'khāʾ',  true],
        'dal'   => ['د', 'دَال',  'dāl',   false],
        'dhal'  => ['ذ', 'ذَال',  'dhāl',  false],
        'ra'    => ['ر', 'رَاء',  'rāʾ',   false],
        'zay'   => ['ز', 'زَاي',  'zāy',   false],
        'sin'   => ['س', 'سِين',  'sīn',   true],
        'shin'  => ['ش', 'شِين',  'shīn',  true],
        'sad'   => ['ص', 'صَاد',  'ṣād',   true],
        'dad'   => ['ض', 'ضَاد',  'ḍād',   true],
        'taa'   => ['ط', 'طَاء',  'ṭāʾ',   true],
        'zaa'   => ['ظ', 'ظَاء',  'ẓāʾ',   true],
        'ayn'   => ['ع', 'عَين',  'ʿayn',  true],
        'ghayn' => ['غ', 'غَين',  'ghayn', true],
        'fa'    => ['ف', 'فَاء',  'fāʾ',   true],
        'qaf'   => ['ق', 'قَاف',  'qāf',   true],
        'kaf'   => ['ك', 'كَاف',  'kāf',   true],
        'lam'   => ['ل', 'لَام',  'lām',   true],
        'mim'   => ['م', 'مِيم',  'mīm',   true],
        'nun'   => ['ن', 'نُون',  'nūn',   true],
        'ha'    => ['ه', 'هَاء',  'hāʾ',   true],
        'waw'   => ['و', 'وَاو',  'wāw',   false],
        'ya'    => ['ي', 'يَاء',  'yāʾ',   true],
    ];

    /** The vowel marks: id => [combining mark, arabic name, label, sound on bāʾ, stage]. */
    public const MARKS = [
        'fatha'    => ["\u{064E}", 'فَتْحَة',        'Fatha',        'ba',  self::STAGE_SHORT_VOWELS],
        'kasra'    => ["\u{0650}", 'كَسْرَة',        'Kasra',        'bi',  self::STAGE_SHORT_VOWELS],
        'damma'    => ["\u{064F}", 'ضَمَّة',         'Damma',        'bu',  self::STAGE_SHORT_VOWELS],
        'sukun'    => ["\u{0652}", 'سُكُون',         'Sukun',        'b',   self::STAGE_SUKUN_SHADDA],
        'shadda'   => ["\u{0651}", 'شَدَّة',         'Shadda',       'bb',  self::STAGE_SUKUN_SHADDA],
        'fathatan' => ["\u{064B}", 'تَنْوِين فَتْح', 'Tanween Fath', 'ban', self::STAGE_TANWEEN],
        'kasratan' => ["\u{064D}", 'تَنْوِين كَسْر', 'Tanween Kasr', 'bin', self::STAGE_TANWEEN],
        'dammatan' => ["\u{064C}", 'تَنْوِين ضَمّ',  'Tanween Damm', 'bun', self::STAGE_TANWEEN],
    ];

    /**
     * The long vowels. NOT marks — each is a whole letter following a short
     * vowel, so nothing may treat بَا as one letter carrying a mark.
     * id => [short vowel that must precede it, the letter, label, sound].
     */
    public const MADD = [
        'alif' => ['fatha', 'ا', 'Madd Alif', 'baa'],
        'waw'  => ['damma', 'و', 'Madd Waw',  'buu'],
        'ya'   => ['kasra', 'ي', 'Madd Ya',   'bii'],
    ];

    // ----------------------------------------------------------------- rules

    public static function isStage(?string $stage): bool
    {
        return $stage !== null && in_array($stage, self::STAGES, true);
    }

    /** A group with no stage set is on the first one. */
    public static function normaliseStage(?string $stage): string
    {
        return self::isStage($stage) ? $stage : self::STAGE_LETTERS;
    }

    public static function stageIndex(?string $stage): int
    {
        return (int) array_search(self::normaliseStage($stage), self::STAGES, true);
    }

    /** Cumulative: a stage unlocks its own marks and every earlier stage's. */
    public static function marksUpTo(?string $stage): array
    {
        $limit = self::stageIndex($stage);

        return array_keys(array_filter(
            self::MARKS,
            static fn (array $mark): bool => self::stageIndex($mark[4]) <= $limit
        ));
    }

    public static function maddUpTo(?string $stage): array
    {
        return self::stageIndex($stage) >= self::stageIndex(self::STAGE_MADD)
            ? array_keys(self::MADD)
            : [];
    }

    /**
     * Every drill a stage covers: each letter alone, then each letter with each
     * unlocked mark, then each long vowel. This is the progress denominator, so
     * it is also exactly what the tracker must show.
     *
     * @return array<int,string> drill ids
     */
    public static function syllabus(?string $stage): array
    {
        $drills = [];

        foreach (array_keys(self::LETTERS) as $letter) {
            $drills[] = $letter;
        }

        foreach (self::marksUpTo($stage) as $mark) {
            foreach (array_keys(self::LETTERS) as $letter) {
                $drills[] = "{$letter}.{$mark}";
            }
        }

        foreach (self::maddUpTo($stage) as $madd) {
            foreach (array_keys(self::LETTERS) as $letter) {
                $drills[] = "{$letter}.madd_{$madd}";
            }
        }

        return $drills;
    }

    /** The drills for ONE letter at a stage — one student's letter card. */
    public static function drillsForLetter(string $letter, ?string $stage): array
    {
        if (! isset(self::LETTERS[$letter])) {
            return [];
        }

        $drills = [$letter];

        foreach (self::marksUpTo($stage) as $mark) {
            $drills[] = "{$letter}.{$mark}";
        }

        foreach (self::maddUpTo($stage) as $madd) {
            $drills[] = "{$letter}.madd_{$madd}";
        }

        return $drills;
    }

    public static function isValidDrill(string $drillId, ?string $stage): bool
    {
        return in_array($drillId, self::syllabus($stage), true);
    }

    // ------------------------------------------------------------ rendering

    public static function connectsForward(string $letter): bool
    {
        return self::LETTERS[$letter][3] ?? false;
    }

    /** isolated | initial | medial | final, shaped by the font via ZWJ. */
    public static function shape(string $letter, string $position): string
    {
        $glyph = self::LETTERS[$letter][0] ?? '';
        $joins = self::connectsForward($letter);

        return match ($position) {
            'initial' => $joins ? $glyph.self::ZWJ : $glyph,
            'medial'  => $joins ? self::ZWJ.$glyph.self::ZWJ : self::ZWJ.$glyph,
            'final'   => self::ZWJ.$glyph,
            default   => $glyph,
        };
    }

    public static function positionsFor(string $letter): array
    {
        return self::connectsForward($letter)
            ? ['isolated', 'initial', 'medial', 'final']
            : ['isolated', 'final'];
    }

    /**
     * One drill, described for a client: what to show, what it is called and
     * how it sounds.
     */
    public static function describeDrill(string $drillId): ?array
    {
        [$letter, $suffix] = array_pad(explode('.', $drillId, 2), 2, null);

        if (! isset(self::LETTERS[$letter])) {
            return null;
        }

        [$glyph, $arabicName, $translit] = self::LETTERS[$letter];

        if ($suffix === null) {
            return [
                'id' => $drillId, 'letter' => $letter, 'text' => $glyph,
                'label' => $translit, 'arabic_name' => $arabicName,
                'sound' => null, 'stage' => self::STAGE_LETTERS,
            ];
        }

        if (str_starts_with($suffix, 'madd_')) {
            $madd = substr($suffix, 5);
            if (! isset(self::MADD[$madd])) {
                return null;
            }
            [$short, $letterGlyph, $label, $sound] = self::MADD[$madd];

            return [
                'id' => $drillId, 'letter' => $letter,
                'text' => $glyph.self::MARKS[$short][0].$letterGlyph,
                'label' => $label, 'arabic_name' => null,
                'sound' => $sound, 'stage' => self::STAGE_MADD,
            ];
        }

        if (! isset(self::MARKS[$suffix])) {
            return null;
        }

        [$mark, $markArabic, $label, $sound, $stage] = self::MARKS[$suffix];

        return [
            'id' => $drillId, 'letter' => $letter, 'text' => $glyph.$mark,
            'label' => $label, 'arabic_name' => $markArabic,
            'sound' => $sound, 'stage' => $stage,
        ];
    }
}
