<?php

namespace App\Support\Arabic;

use App\Support\Letters\LetterCurriculum;

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
 *
 * ## One of two alphabets, since the school asked for A-Z as well
 *
 * This class answers `App\Support\Letters\LetterCurriculum` so `LetterTracker`
 * can serve the Arabic and the English track through the same code. Nothing
 * about the qāʿidah changed to make that fit: the contract was drawn around the
 * questions this class already answered, and the methods below that implement it
 * are the same statics every existing caller uses. The interface is static for
 * exactly that reason — see its docblock.
 */
class ArabicCurriculum implements LetterCurriculum
{
    public const ZWJ = "\u{200D}";

    /** The value stored in `arabic_letter_progress.alphabet` for this track. */
    public const ALPHABET = 'arabic';

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

    // -------------------------------------------------------- letter groups

    /**
     * How a letter is SOUNDED, as against which letter it is.
     *
     * These three are not stages and must never join the ladder. A stage is a
     * step a class moves through, each one including everything before it; a
     * group is a property the letter has always had. They also overlap — غ and
     * خ are throat letters AND heavy letters — so a single letter would have to
     * sit in two stages at once, which `stageIndex()` cannot express.
     *
     * They therefore carry their OWN drills and their own totals, and are
     * deliberately absent from `syllabus()`. Adding twenty-eight drills to the
     * qāʿidah denominator would have moved every existing class's progress bar
     * backwards overnight for a change nobody asked them about.
     *
     * ## Why some letters are missing on purpose
     *
     * Each list holds only letters whose membership is UNCONDITIONAL, because a
     * drill tile has no honest way to say "sometimes". The conditional cases are
     * named in `note` for the teacher instead of being drawn as a tile a child
     * would be marked right or wrong on:
     *
     *  - **Throat** is classically SIX letters: ء ه ع ح غ خ. The first is hamza,
     *    which is not one of the twenty-eight — this alphabet's first letter is
     *    alif (ا), a different thing. Alif is NOT a throat letter and is not
     *    listed; the note tells the teacher where the sixth went.
     *  - **Heavy** is the seven ḥurūf al-istiʿlāʾ of خُصَّ ضَغْطٍ قِظْ, and only those.
     *  - **Light** is not a list in the books at all: it is every letter that is
     *    not one of the seven. ر, ل and ا are left out of BOTH groups because
     *    each is heavy in some positions and light in others.
     */
    public const GROUP_HALQ = 'halq';
    public const GROUP_TAFKHEEM = 'tafkheem';
    public const GROUP_TARQEEQ = 'tarqeeq';

    /** In teaching order. Unlike stages, this order implies no prerequisite. */
    /**
     * The seven ḥurūf al-istiʿlāʾ — the only letters that are heavy wherever
     * they appear. This is the list the books enumerate and the one a child
     * memorises.
     */
    public const HEAVY_LETTERS = ['kha', 'sad', 'dad', 'ghayn', 'taa', 'qaf', 'zaa'];

    /**
     * The three letters that are neither always heavy nor always light, and so
     * belong to NEITHER group:
     *
     *  - ر  heavy on fatḥa or ḍamma (or sākin after one), light on kasra.
     *  - ل  heavy only in the name الله, and only after fatḥa or ḍamma.
     *  - ا  has no weight of its own; it copies the letter before it.
     *
     * They are named in the groups' `note` so a teacher is told where they went.
     * Drawing them as a tile in either group would mark a child right or wrong
     * on something that depends on the word in front of them.
     */
    public const CONDITIONAL_LETTERS = ['ra', 'lam', 'alif'];

    public const GROUPS = [
        self::GROUP_HALQ => [
            'label' => 'Throat Letters',
            'arabic_name' => 'حُرُوف الحَلْق',
            'summary' => 'The letters sounded from the throat, deepest to nearest the mouth.',
            'note' => 'Classically six: ء ه ع ح غ خ. Hamza (ء) is not one of the twenty-eight letters here, so five are shown. Alif (ا) is a different letter, sounded from the chest, and is not a throat letter.',
        ],
        self::GROUP_TAFKHEEM => [
            'label' => 'Heavy Letters',
            'arabic_name' => 'حُرُوف الاسْتِعْلاء',
            'summary' => 'The seven letters that are heavy wherever they appear — خُصَّ ضَغْطٍ قِظْ.',
            'note' => 'ر, ل and ا are not shown here: each is heavy in some words and light in others, so they are taught as their own rules rather than marked as heavy letters.',
        ],
        self::GROUP_TARQEEQ => [
            'label' => 'Light Letters',
            'arabic_name' => 'حُرُوف الاسْتِفال',
            // Captioned by the RULE, not by a count. No source prints "18":
            // the books say istifāl is 28 − 7 = 21 and count ا, ل and ر among
            // them, so a screen claiming eighteen light letters would be
            // contradicted by the first parent who looks it up.
            'summary' => 'Every letter that is not one of the seven heavy letters — light is the default.',
            'note' => 'ر, ل and ا are not shown here either, for the same reason they are absent from the heavy letters.',
        ],
    ];

    /** The prefix that marks a drill as belonging to a group, not a stage. */
    public const GROUP_DRILL_PREFIX = 'group_';

    public static function groupIds(): array
    {
        return array_keys(self::GROUPS);
    }

    public static function isGroup(?string $group): bool
    {
        return $group !== null && isset(self::GROUPS[$group]);
    }

    /**
     * The groups as payloads, so a client renders them without hardcoding the
     * names, the membership or the caveats.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function groups(): array
    {
        $out = [];

        foreach (self::GROUPS as $id => $group) {
            $out[] = [
                'id' => $id,
                'label' => $group['label'],
                'arabic_name' => $group['arabic_name'],
                'summary' => $group['summary'],
                'note' => $group['note'],
                'letters' => self::groupLetters($id),
            ];
        }

        return $out;
    }

    /**
     * The letters in one group, in hijāʾī order.
     *
     * Light is DERIVED — every letter that is neither always heavy nor
     * conditional — rather than typed out. A typed list is where ح and ه get
     * confused for one another, and it would silently stop agreeing with
     * HEAVY_LETTERS the first time that list was edited.
     *
     * @return array<int,string>
     */
    public static function groupLetters(string $group): array
    {
        return match ($group) {
            self::GROUP_HALQ => ['ha', 'ayn', 'haa', 'ghayn', 'kha'],
            self::GROUP_TAFKHEEM => self::HEAVY_LETTERS,
            self::GROUP_TARQEEQ => array_values(array_diff(
                array_keys(self::LETTERS),
                self::HEAVY_LETTERS,
                self::CONDITIONAL_LETTERS
            )),
            default => [],
        };
    }

    /**
     * The drills for one group — one per letter in it.
     *
     * @return array<int,string> drill ids
     */
    public static function groupDrills(string $group): array
    {
        return array_map(
            static fn (string $letter): string => $letter.'.'.self::GROUP_DRILL_PREFIX.$group,
            self::groupLetters($group)
        );
    }

    /** Every group drill on the track, for a caller that wants the lot. */
    public static function groupSyllabus(): array
    {
        $drills = [];

        foreach (self::groupIds() as $group) {
            $drills = array_merge($drills, self::groupDrills($group));
        }

        return $drills;
    }

    /**
     * The group a drill id belongs to, or null if it is not a group drill.
     *
     * Membership is checked, not just the prefix: `sad.group_halq` parses but
     * ṣād is not a throat letter, and accepting it would let a client invent a
     * drill the group's own totals do not count.
     */
    public static function groupOfDrill(string $drillId): ?string
    {
        [$letter, $suffix] = array_pad(explode('.', $drillId, 2), 2, null);

        if ($suffix === null || ! str_starts_with($suffix, self::GROUP_DRILL_PREFIX)) {
            return null;
        }

        $group = substr($suffix, strlen(self::GROUP_DRILL_PREFIX));

        return in_array($letter, self::groupLetters($group), true) ? $group : null;
    }

    // -------------------------------------------------------------- identity

    public static function alphabetId(): string
    {
        return self::ALPHABET;
    }

    public static function label(): string
    {
        return 'Arabic';
    }

    public static function direction(): string
    {
        return 'rtl';
    }

    // ----------------------------------------------------------------- rules

    /**
     * The stage ladder as payloads, so a client renders it without hardcoding
     * either the names or their order.
     *
     * @return array<int,array{id:string,label:string,summary:string}>
     */
    public static function stages(): array
    {
        return array_map(
            static fn (string $stage): array => [
                'id' => $stage,
                'label' => self::STAGE_LABELS[$stage],
                'summary' => self::STAGE_SUMMARIES[$stage],
            ],
            self::STAGES
        );
    }

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

    /**
     * The drills a stage introduces ON ITS OWN — `syllabus()` minus everything
     * the earlier stages already covered.
     *
     * `syllabus()` is cumulative and must stay so: it is the progress
     * denominator, and a class on Long Vowels is still accountable for its
     * letters. But "mark all the Long Vowels drills" is a different question
     * from "mark all 336 drills", and until this existed the two had the same
     * answer — the confirmation named one stage and the action marked five.
     *
     * @return array<int,string> drill ids
     */
    public static function stageDrills(?string $stage): array
    {
        $stage = self::normaliseStage($stage);
        $letters = array_keys(self::LETTERS);

        if ($stage === self::STAGE_LETTERS) {
            return $letters;
        }

        $drills = [];

        foreach (self::MARKS as $mark => $definition) {
            if ($definition[4] !== $stage) {
                continue;
            }

            foreach ($letters as $letter) {
                $drills[] = "{$letter}.{$mark}";
            }
        }

        if ($stage === self::STAGE_MADD) {
            foreach (array_keys(self::MADD) as $madd) {
                foreach ($letters as $letter) {
                    $drills[] = "{$letter}.madd_{$madd}";
                }
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

    /**
     * A group drill is valid at ANY stage: how a letter is sounded is not
     * unlocked by the qāʿidah ladder, and a class practising throat letters in
     * its first term must be able to record that.
     */
    public static function isValidDrill(string $drillId, ?string $stage): bool
    {
        if (self::groupOfDrill($drillId) !== null) {
            return true;
        }

        return in_array($drillId, self::syllabus($stage), true);
    }

    // ------------------------------------------------------------ rendering

    /** @return array<int,string> the 28 ids, in hijāʾī order */
    public static function letters(): array
    {
        return array_keys(self::LETTERS);
    }

    /**
     * One letter as the tracker's grid renders it — the constant above unpacked
     * into named keys, so a caller never indexes `LETTERS[$id][3]` and has to
     * remember what 3 was.
     */
    public static function letter(string $id): ?array
    {
        if (! isset(self::LETTERS[$id])) {
            return null;
        }

        [$glyph, $arabicName, $translit, $joins] = self::LETTERS[$id];

        return [
            'id' => $id,
            'glyph' => $glyph,
            'arabic_name' => $arabicName,
            'transliteration' => $translit,
            'connects_forward' => $joins,
        ];
    }

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
                'sound' => null, 'stage' => self::STAGE_LETTERS, 'group' => null,
            ];
        }

        if (str_starts_with($suffix, self::GROUP_DRILL_PREFIX)) {
            $group = self::groupOfDrill($drillId);

            if ($group === null) {
                return null;
            }

            return [
                'id' => $drillId, 'letter' => $letter, 'text' => $glyph,
                'label' => $translit, 'arabic_name' => $arabicName,
                // A group drill is the letter sounded, not a new shape to read,
                // so there is nothing to spell out as a syllable.
                'sound' => null,
                // Deliberately null: this drill belongs to no stage, and a
                // client that groups by stage must not file it under one.
                'stage' => null,
                'group' => $group,
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
                'sound' => $sound, 'stage' => self::STAGE_MADD, 'group' => null,
            ];
        }

        if (! isset(self::MARKS[$suffix])) {
            return null;
        }

        [$mark, $markArabic, $label, $sound, $stage] = self::MARKS[$suffix];

        return [
            'id' => $drillId, 'letter' => $letter, 'text' => $glyph.$mark,
            'label' => $label, 'arabic_name' => $markArabic,
            'sound' => $sound, 'stage' => $stage, 'group' => null,
        ];
    }
}
