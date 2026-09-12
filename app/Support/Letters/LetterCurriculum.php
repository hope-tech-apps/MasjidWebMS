<?php

namespace App\Support\Letters;

/**
 * What a tracker needs to know about ONE alphabet.
 *
 * The Letters tab began as the Arabic qāʿidah and nothing else, so
 * `ArabicCurriculum` was both the rules and the only rules there were. The
 * school then asked for A–Z tracked the same way on the same tab, and the
 * choice was between a second tracker that happens to look like the first, or
 * one tracker that asks an alphabet the handful of questions it actually needs.
 * This is that list of questions. Everything the payload shows — the letters,
 * the shapes, the drills behind them, the progress denominator — is answered
 * HERE, so a new alphabet is a new class and not a new branch in every read.
 *
 * ## The methods are STATIC, deliberately
 *
 * `ArabicCurriculum` is a table of constants with static readers, and roughly
 * every caller in the module — `Group::arabicStage()`, the mark endpoint, the
 * form requests, `tests/Unit/ArabicCurriculumTest.php` — is written against
 * that static contract. An instance-method interface would have meant either
 * changing all of them or wrapping the real class in a shim that exists only to
 * satisfy a type hint. PHP lets an interface declare static methods and lets a
 * static method be reached through an instance, so the real class implements
 * this directly and `CurriculumRegistry` still hands out objects that can be
 * type-hinted and passed around.
 *
 * ## positionsFor() names positions; shape() draws them
 *
 * `positionsFor()` returns position IDS and `shape()` turns one into the text to
 * render, which is the split Arabic already had and English needs just as much:
 * Arabic's four contextual forms are produced with zero-width joiners rather
 * than stored, and English's two are upper and lower case. A client gets
 * `[['id' => 'upper', 'text' => 'A'], ['id' => 'lower', 'text' => 'a']]` either
 * way, assembled once in `LetterTracker` instead of twice here.
 */
interface LetterCurriculum
{
    /** The value stored in `arabic_letter_progress.alphabet` for this track. */
    public static function alphabetId(): string;

    /** Human name for the track — 'Arabic', 'English'. */
    public static function label(): string;

    /** 'rtl' or 'ltr'. The payload carries it so a client never infers it from the alphabet id. */
    public static function direction(): string;

    /**
     * Every stage in teaching order, each as ['id' => …, 'label' => …,
     * 'summary' => …]. Payloads rather than bare ids because a client renders
     * the ladder from this and must not hardcode either the names or their
     * order. An alphabet with a single stage returns one entry, not none.
     *
     * @return array<int,array{id:string,label:string,summary:string}>
     */
    public static function stages(): array;

    /** An unset or unrecognised stage is the FIRST stage, never an error. */
    public static function normaliseStage(?string $stage): string;

    /**
     * Every drill a stage covers. This is the progress denominator, so it is
     * also exactly what the tracker must show.
     *
     * @return array<int,string> drill ids
     */
    public static function syllabus(?string $stage): array;

    /**
     * The drills for ONE letter at a stage — one student's letter card, and a
     * slice of the same syllabus above.
     *
     * @return array<int,string> drill ids
     */
    public static function drillsForLetter(string $letter, ?string $stage): array;

    public static function isValidDrill(string $drillId, ?string $stage): bool;

    /**
     * One drill, described for a client: id, letter, text, label, arabic_name,
     * sound, stage. Null when the id names nothing this alphabet teaches.
     *
     * @return array{id:string,letter:string,text:string,label:string,arabic_name:?string,sound:?string,stage:string}|null
     */
    public static function describeDrill(string $drillId): ?array;

    /** @return array<int,string> letter ids, in teaching order */
    public static function letters(): array;

    /**
     * One letter, in the shape the tracker's grid renders. `arabic_name` and
     * `connects_forward` are answered by every alphabet — null and false for a
     * script that has no such notion — because the grid is one component and a
     * key that appears only for Arabic would make it two.
     *
     * @return array{id:string,glyph:string,arabic_name:?string,transliteration:string,connects_forward:bool}|null
     */
    public static function letter(string $id): ?array;

    /** @return array<int,string> position ids, in the order they are shown */
    public static function positionsFor(string $letter): array;

    /** The text to draw for one letter in one position. */
    public static function shape(string $letter, string $position): string;
}
