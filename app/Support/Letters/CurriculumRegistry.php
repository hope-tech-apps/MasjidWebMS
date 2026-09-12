<?php

namespace App\Support\Letters;

use App\Support\Arabic\ArabicCurriculum;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The list of alphabets the Letters tab tracks, and the one place a string
 * turns into a curriculum.
 *
 * `arabic_letter_progress.alphabet` is a plain varchar, so the only thing
 * stopping a typo becoming a third alphabet with its own silent set of rows is
 * this allowlist. Every writer goes through it: the form requests validate
 * against `ALPHABETS`, the read endpoints resolve through `fromInput()`, and
 * `for()` refuses anything else outright rather than falling back to Arabic —
 * a fallback would answer `?alphabet=englsih` with the Arabic tracker and look
 * like the English rows had vanished.
 */
class CurriculumRegistry
{
    public const ALPHABET_ARABIC = ArabicCurriculum::ALPHABET;
    public const ALPHABET_ENGLISH = EnglishCurriculum::ALPHABET;

    /** In the order a client should offer them. Arabic first: it is what the tab was. */
    public const ALPHABETS = [self::ALPHABET_ARABIC, self::ALPHABET_ENGLISH];

    /** @var array<string,LetterCurriculum> */
    private static array $instances = [];

    /**
     * Resolve an alphabet id that has ALREADY been validated.
     *
     * Hard failure on anything unknown, because reaching here with a bad value
     * means a caller skipped the allowlist — a bug in this repo, not bad input
     * from a client, and it should read like one.
     */
    public static function for(string $alphabet): LetterCurriculum
    {
        if (! in_array($alphabet, self::ALPHABETS, true)) {
            throw new InvalidArgumentException(
                "Unknown alphabet [{$alphabet}]. The letter tracker knows: ".implode(', ', self::ALPHABETS).'.'
            );
        }

        return self::$instances[$alphabet] ??= match ($alphabet) {
            self::ALPHABET_ARABIC => new ArabicCurriculum,
            self::ALPHABET_ENGLISH => new EnglishCurriculum,
        };
    }

    /**
     * Resolve an alphabet straight off a request.
     *
     * Absent means Arabic: every client that existed before English did sends
     * no parameter at all, and their reads must keep returning the qāʿidah.
     * Anything present but unrecognised is a 422 rather than a 500 — it is
     * client input, and the message names what is allowed so the caller can fix
     * it without reading this file. A `ValidationException` raised here renders
     * in the SAME `{status: 'failed', data: {...}}` envelope a form request
     * produces (the JSON renderer in bootstrap/app.php), so a client cannot tell
     * which door refused it — which is the point: the GET routes have no form
     * request to put this rule in, and the reads must not answer differently
     * from the write.
     */
    public static function fromInput(mixed $alphabet): LetterCurriculum
    {
        if ($alphabet === null || $alphabet === '') {
            return self::for(self::ALPHABET_ARABIC);
        }

        if (! is_string($alphabet) || ! in_array($alphabet, self::ALPHABETS, true)) {
            throw ValidationException::withMessages([
                'alphabet' => 'The letter tracker knows these alphabets: '.implode(', ', self::ALPHABETS).'.',
            ]);
        }

        return self::for($alphabet);
    }
}
