<?php

namespace App\Services\Sms;

/**
 * Phone numbers, normalised to E.164 — or refused (T-009).
 *
 * ## Why this tiny class is load-bearing
 *
 * The suppression list is keyed on the NUMBER (`sms_suppressions.phone_e164`),
 * not on a contact id, because that is the only key that survives a delete, a
 * re-import and a merge. That key is only as good as the normalisation behind
 * it: if "(613) 555-0142" and "+16135550142" do not collapse to the same
 * string, a person who texted STOP gets texted again the moment somebody
 * re-types their number with different punctuation. Every comparison in this
 * feature — suppression matching, merge reconciliation, resolving the tenant
 * from the number an inbound message was sent to — goes through here.
 *
 * ## It REFUSES rather than guesses
 *
 * A number this class cannot resolve with confidence returns null, and a
 * contact whose number returns null is reported as unusable and never
 * messaged. That is deliberately fail-closed: a WRONG normalisation is worse
 * than no normalisation, because it produces a plausible-looking number that
 * does not match the suppression row belonging to the human it actually
 * reaches. Silently dropping a digit and texting a stranger is the failure this
 * refusal exists to prevent.
 *
 * Concretely, with the default country code 1 (config services.sms.default_country_code):
 *
 *   +1 613 555 0142  -> +16135550142   (explicit country code, trusted as given)
 *   (613) 555-0142   -> +16135550142   (10 NANP digits, default country applied)
 *   1-613-555-0142   -> +16135550142   (11 digits led by the default code)
 *   00441632960961   -> +441632960961  (international access prefix)
 *   441632960961     -> null           (11-15 bare digits, country ambiguous)
 *   555-0142         -> null           (too short to be a real destination)
 *   ext. / letters   -> null
 *
 * ## Why not libphonenumber
 *
 * `giggsey/libphonenumber-for-php` is ~20MB of generated metadata and a
 * recurring update obligation, bought for validation this feature does not
 * perform: the provider is the authority on whether a number is reachable and
 * rejects the ones that are not, per message, with an error code. What we need
 * from normalisation is a STABLE, EXACT key, and the refusal above gives us
 * that without adding a dependency to composer.lock. If international sending
 * ever becomes a real requirement rather than a possibility, this class is the
 * one seam that changes.
 */
final class PhoneNumber
{
    /** Shortest and longest a real E.164 subscriber number can be. */
    private const MIN_DIGITS = 8;
    private const MAX_DIGITS = 15;

    /**
     * Normalise a raw, human-typed number to E.164, or null when it cannot be
     * resolved with confidence.
     */
    public static function e164(?string $raw, ?string $defaultCountryCode = null): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        // Letters are never part of a dialable number here. A vanity number, an
        // "ext. 4" suffix or a free-text note ("call the office") must refuse
        // rather than be silently stripped down to something dialable.
        if (preg_match('/[a-zA-Z]/', $raw)) {
            return null;
        }

        $explicitCountry = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        // "00" is the international access prefix in most of the world — the
        // typed equivalent of a leading +.
        if (! $explicitCountry && str_starts_with($digits, '00')) {
            $explicitCountry = true;
            $digits = substr($digits, 2);
        }

        if ($explicitCountry) {
            return self::wrap($digits);
        }

        $country = ltrim((string) ($defaultCountryCode ?? config('services.sms.default_country_code', '1')), '+');
        $country = preg_replace('/\D+/', '', $country) ?? '';

        if ($country === '') {
            return null;
        }

        // A bare national number: apply the default country code. Only the NANP
        // shape (10 digits) is safe to assume, because it is the only one whose
        // length is fixed.
        if ($country === '1') {
            if (strlen($digits) === 10) {
                return self::wrap('1' . $digits);
            }

            if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                return self::wrap($digits);
            }

            // 11-15 bare digits that do NOT start with the default code could be
            // any country. Refuse; do not guess.
            return null;
        }

        // Non-NANP default: only accept a number already led by that country
        // code, since national number lengths vary and prefixing blindly would
        // invent a destination.
        return str_starts_with($digits, $country) ? self::wrap($digits) : null;
    }

    /** True when the raw value resolves to a number we are willing to text. */
    public static function isUsable(?string $raw): bool
    {
        return self::e164($raw) !== null;
    }

    /**
     * The last seven digits — a cheap, driver-portable SQL prefilter for
     * "which contacts might be this number?" before the exact E.164 comparison
     * runs in PHP. Never used as an identity on its own.
     */
    public static function matchFragment(string $e164): string
    {
        $digits = preg_replace('/\D+/', '', $e164) ?? '';

        return strlen($digits) > 7 ? substr($digits, -7) : $digits;
    }

    private static function wrap(string $digits): ?string
    {
        $length = strlen($digits);

        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
            return null;
        }

        // E.164 country codes never begin with 0.
        if (str_starts_with($digits, '0')) {
            return null;
        }

        return '+' . $digits;
    }
}
