<?php

namespace App\Services\Shop;

use Illuminate\Support\Str;

/**
 * Text as the live-uniqueness indexes compare it (shop slice B2): a product's slug per organisation
 * and a size's label per product are unique among LIVE rows, by a unique index.
 *
 * SQLite (the suite) compares those bytes exactly. Production's MySQL compares them under the
 * column's collation, `utf8mb4_unicode_ci` (config/database.php), which ignores CASE and ACCENTS:
 * `Polo` = `polo`, `M` = `m`, `Médium` = `Medium`, `Straße` = `Strasse`. A check that only
 * compares bytes passes text the index then refuses, and the office sees a 500 instead of a
 * sentence (the e-mail look-alike hole of tests/Support/FoldsAccentsLikeUnicodeCi, in a new place).
 * So every clash check the app makes of its own, the label validation and the slug suffixing,
 * compares through fold(), which errs on the side of calling two texts the same: a clean 422 or a
 * suffix is a cheap mistake, a unique-violation 500 is not.
 *
 * Only Latin letters (and their combining marks) are transliterated; any other script is left as it
 * is, only lower-cased, so two different Arabic or Cyrillic labels are never merged by a
 * transliteration the collation does not share.
 */
final class LiveText
{
    public static function fold(string $value): string
    {
        $latin = preg_replace_callback(
            '/[\p{Latin}\p{M}]+/u',
            static fn (array $run): string => Str::ascii($run[0]),
            $value
        );

        return mb_strtolower($latin ?? $value);
    }
}
