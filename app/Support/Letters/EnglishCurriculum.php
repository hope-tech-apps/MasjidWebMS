<?php

namespace App\Support\Letters;

/**
 * The English alphabet, tracked on the same tab and by the same rules as the
 * qāʿidah.
 *
 * ## One stage, and why that is not a missing feature
 *
 * Arabic has five stages because the qāʿidah genuinely gates what a child may
 * practise: a letter, then the same letter carrying a vowel, then tanwīn, then
 * madd — a tanwīn drill in a room still learning the letters is a tick in a cell
 * nothing shows. English has no such ladder. A class working through A–Z is
 * working through A–Z, and inventing "stage 2: consonant blends" here would
 * invent a syllabus the school never asked for and then make it the progress
 * denominator. So there is exactly one stage, `letters`, and
 * `normaliseStage()` collapses everything to it.
 *
 * That is also why `groups.arabic_stage` is untouched by this class. The column
 * says how far through the QĀʿIDAH a class is; it is not a general "letters
 * stage", and writing to it from an English screen would silently move a child's
 * Arabic denominator. The mark endpoint refuses to set a stage for English for
 * the same reason.
 *
 * ## Two drills per letter: `x.upper` and `x.lower`

A child learns the capital and the lower-case form separately (T-004.2,
2026-09-28: the school wanted them tracked apart), so each letter carries two
drills and the tracker draws two runs of 26 (Capitals, then Lower case), out of
52. The ids are `a.upper` / `a.lower`, NEVER `A` / `a`: production's `drill_id`
column is `utf8mb4_unicode_ci`, which is case-INSENSITIVE, so `A` and `a` are the
same key to the unique index and the second write would collide with the first.
The suffix makes the two ids differ in more than case. The same shape as
Arabic's `ba.fatha`, so `describeDrill()` and the tracker need no special case.

Before 2026-10 the drill id WAS the letter (`a`..`z`, one cell per letter). The
`split_english_letters_by_case` migration rewrote those rows; `isLegacyDrillId()`
exists so a browser tab still open from before answers "reload the page" rather
than a bare refusal.

## The phonics cues are the ordinary school words
 *
 * `a as in apple`, not a cue chosen to be interesting. `x as in fox` is the one
 * that looks wrong and is not: x almost never begins an English word a child
 * reads in their first year, and every classroom chart in use teaches it from
 * the ending.
 */
class EnglishCurriculum implements LetterCurriculum
{
    public const ALPHABET = 'english';

    /** The single stage. Named `letters` to match Arabic's first stage id — same idea, same word. */
    public const STAGE_LETTERS = 'letters';

    public const STAGES = [self::STAGE_LETTERS];

    public const STAGE_LABELS = [self::STAGE_LETTERS => 'Letters'];

    public const STAGE_SUMMARIES = [
        self::STAGE_LETTERS => 'Recognise and name all 26 capital letters and all 26 lower-case letters, and the sound each makes.',
    ];

    /** Position ids, mirroring Arabic's contextual forms: two cases rather than four shapes. */
    public const POSITION_UPPER = 'upper';
    public const POSITION_LOWER = 'lower';

    public const POSITIONS = [self::POSITION_UPPER, self::POSITION_LOWER];

    /**
     * The two SETS a tracker groups its drills into, in the order they are drawn.
     * A set is a property of a drill (which case), where a stage is a step the
     * class moves through. The set ids are the position ids on purpose: the
     * drill `a.upper` is the letter `a` in position `upper`.
     */
    public const SETS = [
        self::POSITION_UPPER => 'Capitals',
        self::POSITION_LOWER => 'Lower case',
    ];

    /**
     * The 26 letters in alphabetical order: id => [name, phonics word].
     *
     * The id is lower case and the name is upper case because that is how the
     * two are used — the id is a database value and the name is what a teacher
     * reads on the card.
     */
    public const LETTERS = [
        'a' => ['A', 'apple'],
        'b' => ['B', 'ball'],
        'c' => ['C', 'cat'],
        'd' => ['D', 'dog'],
        'e' => ['E', 'egg'],
        'f' => ['F', 'fish'],
        'g' => ['G', 'goat'],
        'h' => ['H', 'hat'],
        'i' => ['I', 'igloo'],
        'j' => ['J', 'jug'],
        'k' => ['K', 'kite'],
        'l' => ['L', 'leaf'],
        'm' => ['M', 'moon'],
        'n' => ['N', 'nest'],
        'o' => ['O', 'orange'],
        'p' => ['P', 'pen'],
        'q' => ['Q', 'queen'],
        'r' => ['R', 'rain'],
        's' => ['S', 'sun'],
        't' => ['T', 'tree'],
        'u' => ['U', 'umbrella'],
        'v' => ['V', 'van'],
        'w' => ['W', 'water'],
        'x' => ['X', 'fox'],
        'y' => ['Y', 'yarn'],
        'z' => ['Z', 'zip'],
    ];

    // ------------------------------------------------------------- identity

    public static function alphabetId(): string
    {
        return self::ALPHABET;
    }

    public static function label(): string
    {
        return 'English';
    }

    public static function direction(): string
    {
        return 'ltr';
    }

    // --------------------------------------------------------------- stages

    /** @return array<int,array{id:string,label:string,summary:string}> */
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

    /**
     * Always the one stage.
     *
     * A caller may well hand this the group's `arabic_stage` — the tracker does,
     * because the column is where a class's stage lives and asking the
     * curriculum to normalise it is what keeps the tracker free of alphabet
     * branches. An Arabic stage id means nothing here, and answering with the
     * only stage there is beats throwing at a caller who did nothing wrong.
     */
    /**
     * A–Z has no equivalent of the qāʿidah's sounding groups. Answering with an
     * empty list is the honest answer, not a gap: the tracker draws no group
     * sections and the teacher sees the screen they saw before.
     */
    /**
     * A–Z has one stage, so the stage owns the whole syllabus and "this stage"
     * and "everything" are the same set — which is the honest answer here, not
     * a shortcut.
     */
    public static function stageDrills(?string $stage): array
    {
        return self::syllabus($stage);
    }

    public static function groups(): array
    {
        return [];
    }

    /** @return array<int,array{id:string,label:string}> */
    public static function sets(): array
    {
        return array_map(
            static fn (string $id): array => ['id' => $id, 'label' => self::SETS[$id]],
            array_keys(self::SETS)
        );
    }

    /** `a.upper` -> `upper`. Null for an id this alphabet does not teach. */
    public static function set(string $drillId): ?string
    {
        return self::parseDrill($drillId)[1] ?? null;
    }

    /**
     * The drill id for a letter in a case. The ONE place the id is assembled,
     * so the migration, the curriculum and the tests cannot spell it two ways.
     */
    public static function drillId(string $letter, string $position): string
    {
        return $letter.'.'.$position;
    }

    /**
     * The pre-split drill id: a bare letter. Not a drill any more; recognised
     * only so the mark endpoint can tell a stale tab (`a`) from a typo.
     */
    public static function isLegacyDrillId(string $drillId): bool
    {
        return isset(self::LETTERS[strtolower($drillId)]);
    }

    /**
     * @return array{0:string,1:string}|null [letter, position] for a real drill.
     *
     * Exact match only: `A.upper`, `a.Upper` and `a.upper ` are not drills, so a
     * case-insensitive database can never be handed a second spelling of one.
     */
    private static function parseDrill(string $drillId): ?array
    {
        $parts = explode('.', $drillId, 2);

        if (count($parts) !== 2 || ! isset(self::LETTERS[$parts[0]]) || ! in_array($parts[1], self::POSITIONS, true)) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    public static function groupDrills(string $group): array
    {
        return [];
    }

    public static function normaliseStage(?string $stage): string
    {
        return self::STAGE_LETTERS;
    }

    // --------------------------------------------------------------- drills

    /** @return array<int,string> */
    public static function syllabus(?string $stage): array
    {
        $drills = [];

        foreach (array_keys(self::LETTERS) as $letter) {
            foreach (self::POSITIONS as $position) {
                $drills[] = self::drillId($letter, $position);
            }
        }

        return $drills;
    }

    /** @return array<int,string> */
    public static function drillsForLetter(string $letter, ?string $stage): array
    {
        if (! isset(self::LETTERS[$letter])) {
            return [];
        }

        return array_map(static fn (string $p): string => self::drillId($letter, $p), self::POSITIONS);
    }

    public static function isValidDrill(string $drillId, ?string $stage): bool
    {
        return self::parseDrill($drillId) !== null;
    }

    /**
     * The SAME keys the Arabic description carries, so the card component does
     * not have to know which alphabet it is drawing. `arabic_name` is null
     * rather than absent. `text` is the ONE form this drill is about (the
     * capital for `a.upper`, the small letter for `a.lower`): since the split,
     * each case is its own drill and its own cell.
     */
    public static function describeDrill(string $drillId): ?array
    {
        $parsed = self::parseDrill($drillId);

        if ($parsed === null) {
            return null;
        }

        [$letter, $position] = $parsed;
        [$name, $word] = self::LETTERS[$letter];
        $upper = $position === self::POSITION_UPPER;

        return [
            'id' => $drillId,
            'letter' => $letter,
            'text' => $upper ? $name : $letter,
            'label' => ($upper ? 'Capital ' : 'Lower case ').($upper ? $name : $letter),
            'arabic_name' => null,
            'sound' => "{$letter} as in {$word}",
            'stage' => self::STAGE_LETTERS,
            'set' => $position,
        ];
    }

    // -------------------------------------------------------------- letters

    /** @return array<int,string> */
    public static function letters(): array
    {
        return array_keys(self::LETTERS);
    }

    public static function letter(string $id): ?array
    {
        if (! isset(self::LETTERS[$id])) {
            return null;
        }

        [$name] = self::LETTERS[$id];

        return [
            'id' => $id,
            'glyph' => $name.$id,
            // No Arabic name and no joining behaviour — answered rather than
            // omitted so the grid stays one component. See LetterCurriculum.
            'arabic_name' => null,
            'transliteration' => $name,
            'connects_forward' => false,
        ];
    }

    /** @return array<int,string> */
    public static function positionsFor(string $letter): array
    {
        return isset(self::LETTERS[$letter]) ? self::POSITIONS : [];
    }

    public static function shape(string $letter, string $position): string
    {
        if (! isset(self::LETTERS[$letter])) {
            return '';
        }

        [$name] = self::LETTERS[$letter];

        return match ($position) {
            self::POSITION_UPPER => $name,
            self::POSITION_LOWER => $letter,
            // Anything else gets the pair, which is what the letter "is" here —
            // the same fallback Arabic makes to the isolated glyph.
            default => $name.$letter,
        };
    }
}
