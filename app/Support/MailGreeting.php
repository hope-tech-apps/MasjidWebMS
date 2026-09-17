<?php

namespace App\Support;

/**
 * "Assalamu alaikum {first name}," for the account mails, with the name left
 * out whenever it does not look like a name.
 *
 * WHY A NAME HAS TO BE CHECKED BEFORE IT IS PRINTED
 *
 * `contacts.first_name` is not always something the reader typed or the office
 * checked. The public registration form (POST /api/v1/offerings/{slug}/register)
 * needs no sign-in, and it saves the first word of whatever name it is given
 * next to whatever address it is given. When that address later signs in to the
 * app, the app links to that record by its address and keeps its name. So a
 * stranger could make a genuine "Your sign-in code" or "Your password was set"
 * email, from the organisation's real sender, open with
 * "Assalamu alaikum https://evil.example/secure-your-account," and mail apps
 * turn that into a link.
 *
 * So a name is printed only when all of these hold:
 *
 *  - it is at most MAX_NAME_LENGTH characters;
 *  - it holds no invisible character and no letter or mark that looks like a
 *    full stop (NEVER_PRINTED). A zero-width mark after each dot turns
 *    "www.evil.example" into a string the next two checks would pass, and the
 *    reader still sees "www.evil.example";
 *  - it starts with a letter (any script) and is made of letters, the spaces,
 *    apostrophes, hyphens and full stops real names use, and combining marks
 *    that sit directly after a letter or another mark (the vowel marks of
 *    Arabic, the accents of a decomposed "Zoë");
 *  - no full stop comes before a letter, even with marks between them
 *    ("evil.example" is a web address to a mail app). "Abd. Rahman" and
 *    "Mohd." stay.
 *
 * Anything else, including digits, "@", ":", "/", other punctuation and
 * symbols, and every control or formatting character, drops the name, and the
 * greeting is "Assalamu alaikum,". Leaving a real but unusual name out costs a
 * word of warmth; printing a link costs a phishing email with the
 * organisation's name on it.
 *
 * Not covered: letters and marks that look like "/" or ":" (a Japanese "ノ", a
 * Devanagari visarga) stay, because real names use them. Without a full stop
 * they cannot spell an address a reader could visit.
 */
final class MailGreeting
{
    public const MAX_NAME_LENGTH = 40;

    /**
     * A regex character class body.
     *
     * Every Default_Ignorable_Code_Point in Unicode 15 (ICU's list; the ones
     * that are letters or marks are U+034F, U+115F, U+1160, U+17B4, U+17B5,
     * U+180B-U+180D, U+180F, U+3164, U+FE00-U+FE0F, U+FFA0 and
     * U+E0100-U+E01EF, and the others are also refused by the shape check),
     * plus the two letters or marks ICU's confusables data maps to ".":
     * U+A4F8 LISU LETTER TONE MYA TI and U+1D16D MUSICAL SYMBOL COMBINING
     * AUGMENTATION DOT. A mail app does not turn those into a link, but the
     * reader still sees a web address.
     */
    public const NEVER_PRINTED = '\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{17B4}\x{17B5}'
        . '\x{180B}-\x{180F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{3164}'
        . '\x{FE00}-\x{FE0F}\x{FEFF}\x{FFA0}\x{FFF0}-\x{FFF8}\x{1BCA0}-\x{1BCA3}'
        . '\x{1D173}-\x{1D17A}\x{E0000}-\x{E0FFF}'
        . '\x{A4F8}\x{1D16D}';

    public static function for(?string $name): string
    {
        $safe = self::safeName($name);

        return $safe === null ? 'Assalamu alaikum,' : "Assalamu alaikum {$safe},";
    }

    /** The name as it may be printed in an email, or null when it must be left out. */
    public static function safeName(?string $name): ?string
    {
        $name = trim((string) preg_replace('/[ \t]+/u', ' ', (string) $name));

        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return null;
        }

        // Invisible characters and full-stop lookalikes, wherever they are.
        if (preg_match('/[' . self::NEVER_PRINTED . ']/u', $name) !== 0) {
            return null;
        }

        // Starts with a letter. Letters, each followed by any combining marks,
        // plus the space, apostrophes (' and ’), hyphen and full stop that real
        // names carry. A mark may not follow the space or the punctuation.
        if (preg_match('/^\p{L}\p{M}*(?:\p{L}\p{M}*|[ \'’.\-])*$/u', $name) !== 1) {
            return null;
        }

        // "evil.example", "www.x": a full stop before a letter is what makes a
        // mail app draw a link. Marks in between do not change that.
        if (preg_match('/\.\p{M}*\p{L}/u', $name) !== 0) {
            return null;
        }

        return $name;
    }
}
