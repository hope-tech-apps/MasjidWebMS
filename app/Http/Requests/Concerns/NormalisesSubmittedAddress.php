<?php

namespace App\Http\Requests\Concerns;

use App\Support\ContactIdentity;

/**
 * Put a typed sign-in address into ASCII before it is validated, so an accented
 * look-alike never reaches a lookup, a stored code row or a mailer.
 *
 * Production's address columns are utf8mb4_unicode_ci, where `victim@gmail.com` =
 * `victim@gmaíl.com`. The lookups re-check every candidate byte for byte
 * (ContactIdentity::sameAddress), and this is the second layer at the door:
 * `gmaíl.com` becomes `xn--…`, which can never equal a stored ASCII domain, and
 * the mail goes to the mailbox that was really typed. A non-ASCII LOCAL part has
 * no such conversion and is left as typed, so the request's `ascii` rule refuses
 * it with the door's ordinary "not an email address" sentence. Production stores
 * no non-ASCII address, so nothing legitimate is turned away.
 *
 * An address that is already ASCII is not touched at all, and neither is a
 * request with no string in the field, so the rules that follow see exactly what
 * they saw before.
 *
 * Use with an `ascii` rule on the same field and a message for it that says what
 * the field's `email` rule says. See `asciiAddressMessage()`.
 */
trait NormalisesSubmittedAddress
{
    protected function normaliseSubmittedAddress(string $field = 'email'): void
    {
        $typed = $this->input($field);

        if (! is_string($typed) || preg_match('/[^\x00-\x7F]/', $typed) !== 1) {
            return;
        }

        $this->merge([$field => ContactIdentity::submittedAddress($typed) ?? $typed]);
    }

    /**
     * The sentence Laravel's own `email` rule gives, for the `ascii` rule to
     * borrow: a caller that was refused for a non-ASCII local part reads what
     * every malformed address reads, and learns nothing about whose address it
     * resembles.
     */
    protected static function asciiAddressMessage(): string
    {
        return 'The :attribute field must be a valid email address.';
    }
}
