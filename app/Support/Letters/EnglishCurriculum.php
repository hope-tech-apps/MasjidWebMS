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
 * ## One drill per letter
 *
 * Arabic's drill ids are compound (`ba`, `ba.fatha`, `ba.madd_alif`) because a
 * letter is practised many ways. An English letter is practised one way, so the
 * drill id IS the letter id: `a`..`z`. Twenty-six drills, twenty-six cells, and
 * nothing to reassemble on the way in or out.
 *
 * ## The phonics cues are the ordinary school words
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
        self::STAGE_LETTERS => 'Recognise and name all 26 letters, upper case and lower case, and the sound each makes.',
    ];

    /** Position ids, mirroring Arabic's contextual forms: two cases rather than four shapes. */
    public const POSITION_UPPER = 'upper';
    public const POSITION_LOWER = 'lower';

    public const POSITIONS = [self::POSITION_UPPER, self::POSITION_LOWER];

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
    public static function normaliseStage(?string $stage): string
    {
        return self::STAGE_LETTERS;
    }

    // --------------------------------------------------------------- drills

    /** @return array<int,string> */
    public static function syllabus(?string $stage): array
    {
        return array_keys(self::LETTERS);
    }

    /** @return array<int,string> */
    public static function drillsForLetter(string $letter, ?string $stage): array
    {
        return isset(self::LETTERS[$letter]) ? [$letter] : [];
    }

    public static function isValidDrill(string $drillId, ?string $stage): bool
    {
        return isset(self::LETTERS[$drillId]);
    }

    /**
     * The SAME keys the Arabic description carries, so the card component does
     * not have to know which alphabet it is drawing. `arabic_name` is null
     * rather than absent, and `text` is the case pair — the two forms are the
     * thing being learned, so showing one would be showing half the drill.
     */
    public static function describeDrill(string $drillId): ?array
    {
        if (! isset(self::LETTERS[$drillId])) {
            return null;
        }

        [$name, $word] = self::LETTERS[$drillId];

        return [
            'id' => $drillId,
            'letter' => $drillId,
            'text' => $name.$drillId,
            'label' => $name,
            'arabic_name' => null,
            'sound' => "{$drillId} as in {$word}",
            'stage' => self::STAGE_LETTERS,
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
