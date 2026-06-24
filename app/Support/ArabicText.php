<?php

namespace App\Support;

/**
 * Arabic text normalization for diacritic-insensitive search.
 *
 * The problem: hadith/azkar text is stored WITH tashkeel (harakat) — e.g.
 * "الإِيمَان" — but users on the mobile app type the bare word "الايمان"
 * (no marks, plain hamza-less alif). A raw SQL `LIKE %الايمان%` then misses
 * the row because the stored bytes differ.
 *
 * The fix: normalize BOTH the stored text (into a dedicated *_normalized
 * column, populated on save) and the incoming query the same way, so an
 * unmarked query matches marked stored text. Normalization:
 *
 *   1. Strip the combining tashkeel marks (fathatan..sukun, U+064B–U+0652),
 *      the superscript alef (U+0670), and the Quranic/maddah annotation
 *      marks (U+0653–U+0655, U+0656–U+065F, U+0640 tatweel).
 *   2. Fold the hamza-carrier alef variants أ/إ/آ/ٱ → ا.
 *   3. Fold standalone/ya-seated hamza variants ؤ/ئ/ء to a bare carrier so
 *      casual spelling differences still match.
 *   4. Fold alef maqsura ى → ي and ta marbuta ة → ه (common informal typing).
 *
 * This mirrors the marks listed in the task brief (U+064B–U+0652, U+0670, the
 * superscript / maddah marks) and the hamza folding (أ/إ/آ → ا).
 */
class ArabicText
{
    /**
     * Combining diacritics (tashkeel) and Arabic-specific formatting marks to strip.
     * Listed explicitly (rather than a \p{M} regex) so the behaviour is identical
     * whether run in PHP or replicated in SQL, and so we never strip a base letter.
     */
    private const DIACRITICS = [
        "\u{0610}", "\u{0611}", "\u{0612}", "\u{0613}", "\u{0614}", "\u{0615}", // Quranic annotation signs
        "\u{0616}", "\u{0617}", "\u{0618}", "\u{0619}", "\u{061A}",
        "\u{064B}", "\u{064C}", "\u{064D}", // fathatan, dammatan, kasratan
        "\u{064E}", "\u{064F}", "\u{0650}", // fatha, damma, kasra
        "\u{0651}", "\u{0652}",             // shadda, sukun
        "\u{0653}", "\u{0654}", "\u{0655}", // maddah above, hamza above, hamza below (combining)
        "\u{0656}", "\u{0657}", "\u{0658}", "\u{0659}", "\u{065A}",
        "\u{065B}", "\u{065C}", "\u{065D}", "\u{065E}", "\u{065F}",
        "\u{0670}",                          // superscript alef (dagger alef)
        "\u{0640}",                          // tatweel (kashida) — pure elongation, no meaning
    ];

    /**
     * Letter folds applied after diacritics are stripped. Maps each variant to a
     * single canonical base letter so spelling/orthography differences collapse.
     */
    private const LETTER_FOLDS = [
        "\u{0623}" => "\u{0627}", // أ alef with hamza above -> ا
        "\u{0625}" => "\u{0627}", // إ alef with hamza below -> ا
        "\u{0622}" => "\u{0627}", // آ alef with madda above -> ا
        "\u{0671}" => "\u{0627}", // ٱ alef wasla -> ا
        "\u{0624}" => "\u{0648}", // ؤ waw with hamza -> و
        "\u{0626}" => "\u{064A}", // ئ ya with hamza -> ي
        "\u{0649}" => "\u{064A}", // ى alef maqsura -> ي
        "\u{0629}" => "\u{0647}", // ة ta marbuta -> ه
        "\u{0621}" => "",          // ء standalone hamza -> removed
    ];

    /**
     * Normalize an Arabic (or mixed) string for storage in a *_normalized column
     * or for comparison against one. Non-Arabic characters pass through untouched,
     * so Latin titles/searches keep working.
     */
    public static function normalize(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // 1. Strip diacritics / formatting marks.
        $value = str_replace(self::DIACRITICS, '', $value);

        // 2. Fold letter variants to a canonical base.
        $value = strtr($value, self::LETTER_FOLDS);

        // 3. Collapse runs of whitespace and trim for stable matching.
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }
}
