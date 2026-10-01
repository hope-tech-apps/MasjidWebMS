<?php

namespace App\Services\Shop;

use Illuminate\Support\Str;
use Normalizer;

/**
 * Text as the live-uniqueness indexes compare it (shop slice B2): a product's slug per organisation
 * and a size's label per product are unique among LIVE rows, by a unique index.
 *
 * SQLite (the suite) compares those bytes exactly. Production's MySQL compares them under the
 * column's collation, `utf8mb4_unicode_ci` (config/database.php), which ignores CASE, ACCENTS and
 * default-ignorable characters, and is PAD SPACE in 8.4: `Polo` = `polo`, `M` = `m` = `M `,
 * `Médium` = `Medium`, `Straße` = `Strasse`, `Е` = `Ё`, `أ` = `ا` = `آ`, and a soft hyphen between
 * two letters is not there at all. A check that only compares bytes passes text the index then
 * refuses, and the office would see a 500 instead of a sentence (the e-mail look-alike hole of
 * tests/Support/FoldsAccentsLikeUnicodeCi, in a new place). So every clash check the app makes of
 * its own, the label validation and the slug suffixing, compares through fold(), which errs on the
 * side of calling two texts the same: a clean 422 or a suffix is a cheap mistake, a unique-violation
 * 500 is not. ProductWriter also catches the violation itself, for whatever this fold misses.
 *
 * The fold is script-independent, in this order:
 *
 *  1. Unicode compatibility decomposition (NFKD, the `intl` Normalizer), which splits every accented
 *     letter of every script into its base and its marks (Ё into Е and a diaeresis, أ into ا and a
 *     hamza, آ into ا and a madda) and folds compatibility forms (the `ﬁ` ligature into `fi`);
 *  2. every mark (`\p{M}`) is removed, then every format character (`\p{Cf}`: soft hyphen, ZWSP,
 *     ZWJ, ZWNJ, the directional marks) and the few other default-ignorable letters (the Hangul
 *     fillers) that the collation gives no weight;
 *  3. any run of white space becomes one space and the ends are trimmed (PAD SPACE: trailing spaces
 *     never counted; leading ones are folded away too, which errs the safe way, and TrimStrings
 *     has already removed both before a request reaches here);
 *  4. lower case;
 *  5. Latin letters, and only Latin letters, are transliterated to ASCII (`ß` to `ss`, `æ` to `ae`),
 *     so two different Arabic or Cyrillic labels are never merged by a transliteration the
 *     collation does not share.
 *
 * Without the `intl` extension step 1 is skipped (CI and the platform's PHP both load it; see
 * ASSUMPTIONS S-29): steps 2 to 5 still fold case, spaces, format characters, marks that arrive
 * already decomposed, and every Latin accent, so only the non-Latin letters that exist ONLY in
 * precomposed form (Ё, أ, آ) would then be told apart.
 */
final class LiveText
{
    /** Default-ignorable letters that are not marks or format characters: the Hangul fillers. */
    private const IGNORABLE = '\x{115F}\x{1160}\x{3164}\x{FFA0}';

    /**
     * @param  bool  $useIntl  false forces the fallback (what a PHP without `intl` does); tests use it
     */
    public static function fold(string $value, bool $useIntl = true): string
    {
        if ($useIntl && class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($value, Normalizer::FORM_KD);
            $value = $decomposed === false ? $value : $decomposed;
        }

        $value = self::strip($value);
        $value = trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $value));
        $value = mb_strtolower($value);

        // Lower-casing can make a mark (a dotted capital I becomes an i and a combining dot): strip again.
        $value = self::strip($value);

        // Only Latin runs are transliterated.
        $latin = preg_replace_callback(
            '/[\p{Latin}]+/u',
            static fn (array $run): string => Str::ascii($run[0]),
            $value
        );

        return $latin ?? $value;
    }

    /** Remove marks, format characters and the default-ignorable fillers. */
    private static function strip(string $value): string
    {
        return preg_replace('/[\p{M}\p{Cf}' . self::IGNORABLE . ']+/u', '', $value) ?? $value;
    }
}
