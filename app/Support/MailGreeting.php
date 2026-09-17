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
 * So a name is printed only when it is made of letters (any script), with the
 * spaces, apostrophes, hyphens and full stops real names use, is at most
 * MAX_NAME_LENGTH characters, and has no full stop directly before a letter
 * ("evil.example" is a web address to a mail app). Anything else, including
 * digits, "@", ":", "/", and invisible or right-to-left control characters,
 * drops the name, and the greeting is "Assalamu alaikum,". Leaving a real but
 * unusual name out costs a word of warmth; printing a link costs a phishing
 * email with the organisation's name on it.
 */
final class MailGreeting
{
    public const MAX_NAME_LENGTH = 40;

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

        // Letters and combining marks, plus the space, apostrophes (' and ’),
        // hyphen and full stop that real names carry. Starts with a letter.
        if (preg_match('/^\p{L}[\p{L}\p{M} \'’.\-]*$/u', $name) !== 1) {
            return null;
        }

        // "evil.example", "www.x": a full stop directly before a letter is what
        // makes a mail app draw a link. "Abd. Rahman" and "Mohd." stay.
        if (preg_match('/\.\p{L}/u', $name) === 1) {
            return null;
        }

        return $name;
    }
}
