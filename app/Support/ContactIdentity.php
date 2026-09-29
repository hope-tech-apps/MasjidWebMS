<?php

namespace App\Support;

use App\Models\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ===========================================================================
 * AN ABSENT VALUE IS NEVER AN IDENTITY MATCH.
 * ===========================================================================
 *
 * That is the whole of this class, and it is stated here once because the
 * application has now got it wrong by stating it twice.
 *
 * `RosterMergeService` asks, of a de-duplication, "did the identity this row's
 * authority is read through change?" — and a previous round answered it with
 *
 *     $holderIdentityChanged = $this->addressOf($source) !== $this->addressOf($target);
 *
 * where `addressOf()` returned `''` for a contact with no email. The method's
 * own docblock noticed the empty string and reasoned only about `'' != 'real@x'`
 * — a row nothing can resolve is not the same person as a row that resolves to a
 * real mailbox — and never about `'' == ''`. So TWO ADDRESS-LESS ADULTS WERE THE
 * SAME PERSON, and a merge carried a staff-confirmed guardianship, its
 * `confirmed_by_user_id` and its recorded media consent onto a different human,
 * reporting `unconfirmed: 0`. Measured end to end, from a caller with no account
 * and no token:
 *
 *     [P11] planted phantom #4 email=NULL phone=NULL
 *     [P11] roster block: {"moved":1,"dropped":0,"unconfirmed":0,
 *             "guardian_claims_reissued":0,"confirmed_guardian_edges_dropped":0,
 *             "family_logins_left_without_a_ward":0,"guardian_claims":[]}
 *     [P11] edge now: {"id":2,"contact_id":4,"guardian_of_contact_id":1,
 *                      "provenance":"confirmed","consent_scope":"media",
 *                      "confirmed_by_user_id":1}
 *     [P11] family-login panel: {"eligible":true,"ineligible_reason":null}
 *     [P11] enable at the attacker address -> 200
 *     [P11] attacker token: /groups 200 · /threads 200 "Safeguarding: incident
 *           on 3 Sept" · /awards 200 "Left the classroom without permission" ·
 *           /hifz 200 sabak 78:1-10
 *
 * The trigger is an ordinary school day. A school imports its parents by phone
 * with no email; children have none by construction. One unauthenticated
 * `POST /api/v1/offerings/{slug}/register` names a registrant with no address
 * (`registrants.*.email` is `nullable`), which plants a second address-less
 * contact under a real parent's name — and the registrar then merges what looks
 * like the same person.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A CLASS AND NOT A SENTENCE IN A COMMENT
 * ---------------------------------------------------------------------------
 *
 * The same file ALREADY refused this on the ward end, at length, in prose — and
 * the holder end got the opposite treatment twenty lines later. A rule that
 * lives in a paragraph is a rule the next writer can contradict without
 * noticing. So the rule is enforced by the SHAPE of this type instead:
 *
 *  - the address never leaves an identity object. There is no getter, no
 *    `__toString()`, no `label()` — nothing that hands back a scalar which
 *    could be compared with `===`, and therefore nothing that can make two
 *    absences equal;
 *  - the ONLY comparison of two identities is `isTheSamePersonAs()`, which is `false` unless BOTH
 *    sides resolve to something. Two unresolvable identities are not equal —
 *    not to each other, and not to themselves;
 *  - a reader who ignores all of that and writes `ContactIdentity::of($a) !== ContactIdentity::of($b)`
 *    compares two distinct object instances, which is always `true`. That reads
 *    as "the identity changed", which is the FAIL-CLOSED direction: it re-opens
 *    a row that did not need re-opening, costing a click, rather than carrying
 *    a confirmation onto a stranger.
 *
 * ---------------------------------------------------------------------------
 * WHAT AN "IDENTITY" IS HERE, AND WHAT IT IS NOT
 * ---------------------------------------------------------------------------
 *
 * It is THE ADDRESS A CREDENTIAL IS MINTED AGAINST. `GroupAudience::identitiesFor()`
 * resolves a staff principal to a person by `LOWER(email)` and nothing else, and
 * a parent portal sign-in is opened on an address. So "the same identity" means
 * "a credential opened on either row reaches the same mailbox", and nothing
 * wider.
 *
 * TWO USES THIS MUST NEVER BE PUT TO, both of which would re-open a measured
 * hole:
 *
 *  1. A WARD IS NOT READ THROUGH AN ADDRESS. A ward is a child; most have none,
 *     and the siblings who do share the household mailbox
 *     (`OfferingRegistrationsController::resolveRegistrantContact()` argues this
 *     at length and resolves children by name within a household for exactly
 *     this reason). Comparing two wards with this class would exempt two
 *     siblings on one mailbox from being told apart. There is NO address
 *     exemption on the ward end, and there must never be one: any change of ward
 *     retires and re-issues.
 *  2. IT IS NOT AN AUTHORIZATION. Answering `true` says only that a merge
 *     changed nothing a read path can see. Every access decision above the
 *     caller is unchanged.
 *
 * ---------------------------------------------------------------------------
 * A SUBMITTED ADDRESS IS NOT A STORED ADDRESS, EVEN WHEN THE DATABASE SAYS SO
 * ---------------------------------------------------------------------------
 *
 * The second half of this class answers a different question from the first:
 * not "are these two rows the same person?" but "did this typed address name
 * this row?" — see `sameAddress()`, `keepExactMatches()` and
 * `submittedAddress()`.
 *
 * Production's `contacts.login_email`, `contacts.email` and
 * `app_signup_codes.email` are `utf8mb4_unicode_ci` (read from production
 * 2026-09-29), and under that collation `'victim@gmail.com' = 'victim@gmaíl.com'`
 * is TRUE, as is `ß` = `ss`. `WHERE LOWER(login_email) = ?` therefore returns a
 * row for a look-alike address. Sign-in mails its code to the address that was
 * TYPED, so whoever owns the look-alike domain received a code and was then
 * linked to the victim's contact, with their password and their sessions. A
 * query can narrow the candidates (the index still earns its keep); only a
 * comparison in PHP can decide whether one of them is the address that was
 * typed. Other comments in this codebase call production `utf8mb4_bin`; for
 * these columns that is not what was measured, and no rule here may depend on
 * it.
 *
 * `PHONE IS NOT AN IDENTITY EITHER`, deliberately. A roster SCREEN falls back to
 * a phone number when there is no email, because a phone number is something an
 * operator can act on; but nothing in this application mints a credential
 * against one or resolves a principal by one, so it cannot answer "would a
 * credential reach the same person". Including it here would make two rows
 * "the same identity" on evidence no read path consults.
 */
final class ContactIdentity
{
    /**
     * @param  string|null  $address  the normalised address, or null for "nothing
     *                                on this row can be resolved to a person"
     */
    private function __construct(private readonly ?string $address)
    {
    }

    /**
     * The identity a contact is read through, or an unresolvable one.
     *
     * Normalised as `LOWER(TRIM(email))`, matching `GroupAudience::identitiesFor()`
     * and `OfferingRegistrationsController::normaliseEmail()` — production is
     * utf8mb4_bin (case-SENSITIVE) and the suite runs SQLite, and whether a
     * de-duplication keeps somebody's authority must not depend on which one it
     * is talking to.
     *
     * A null contact, a null email and an empty-or-whitespace email are ONE
     * state here on purpose. The database allows `''` as well as NULL — an
     * import or a hand edit writes one as readily as the other — and a rule that
     * held for NULL and not for `''` would be this defect again with a different
     * spelling.
     */
    public static function of(?Contact $contact): self
    {
        if (! $contact instanceof Contact) {
            return new self(null);
        }

        $address = Str::lower(trim((string) $contact->email));

        return new self($address === '' ? null : $address);
    }

    /**
     * Is there anything here that a read path could resolve to a person?
     *
     * Exposed because the answer is worth SAYING on a screen — an operator
     * deciding a merge needs to know that neither record can be told apart —
     * and never because a caller should branch on it before comparing.
     */
    public function isResolvable(): bool
    {
        return $this->address !== null;
    }

    /**
     * Would a credential opened on either row reach the same person?
     *
     * FALSE WHENEVER EITHER SIDE IS UNRESOLVABLE, including when BOTH are. Two
     * rows that nothing can resolve are not "the same person"; they are two rows
     * about which the question cannot be answered, and the only safe answer to a
     * question that cannot be answered is the one that grants nothing.
     */
    public function isTheSamePersonAs(self $other): bool
    {
        if ($this->address === null || $other->address === null) {
            return false;
        }

        return hash_equals($this->address, $other->address);
    }

    /**
     * Did a merge from `$from` onto `$to` change the identity the row is read
     * through?
     *
     * The negation is written HERE, once, rather than at each call site, so that
     * "not the same person" and "changed" cannot drift apart — and so that the
     * `!` that turns a safe answer into an unsafe one is not something a caller
     * has to remember to write.
     */
    public static function changed(?Contact $from, ?Contact $to): bool
    {
        return ! self::of($from)->isTheSamePersonAs(self::of($to));
    }

    /**
     * Is `$submitted` the address that is STORED, byte for byte, apart from case
     * and surrounding whitespace?
     *
     * This is the check that must follow every lookup by a typed address on a
     * `utf8mb4_unicode_ci` column. That collation compares accents and expansions
     * as equal (`é` = `e`, `ß` = `ss`), so the query alone cannot tell the
     * address a person typed from a look-alike registered on a domain somebody
     * else owns. Lower-casing both sides (`foldCase()`) and a strict `===` after
     * `trim()` can: two different byte strings are two different mailboxes.
     *
     * NOT plain `mb_strtolower()`. Multibyte lower-casing maps a few non-ASCII
     * characters onto ASCII ones (U+212A KELVIN SIGN becomes `k`), so a Kelvin
     * sign in `vicKtim@example.com` would equal `victim@example.com`, and a
     * character that merely looks like a letter would stand in for it. `foldCase()`
     * lower-cases the ASCII capitals and lets a non-ASCII letter change only into
     * another non-ASCII one, so nothing non-ASCII ever folds into ASCII here.
     *
     * An absent address is never a match, for the reason the class opens with:
     * null, `''` and whitespace on either side answer false, including against
     * each other.
     */
    public static function sameAddress(?string $stored, string $submitted): bool
    {
        if ($stored === null) {
            return false;
        }

        $stored = self::foldCase(trim($stored));
        $submitted = self::foldCase(trim($submitted));

        if ($stored === '' || $submitted === '') {
            return false;
        }

        return $stored === $submitted;
    }

    /**
     * The rows, of the candidates a query returned, whose `$column` is exactly
     * the address that was typed (see `sameAddress()`).
     *
     * Apply it BEFORE any rule counts the candidates. "Two rows is no row" is a
     * rule about two people who hold one address, and a candidate that matched
     * only through the collation is nobody's copy of it: counting it would let a
     * look-alike make a real address ambiguous, and skipping the count would let
     * it stand in for the real one.
     *
     * The query in front of this must not `limit()` the candidates. A limit taken
     * before this filter can cut the exact match off behind look-alikes, or
     * leave one exact row standing where there were two.
     *
     * @param  iterable<int, \Illuminate\Database\Eloquent\Model>  $rows
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public static function keepExactMatches(iterable $rows, string $column, string $submitted): Collection
    {
        return Collection::make($rows)
            ->filter(fn ($row) => self::sameAddress($row->getAttribute($column), $submitted))
            ->values();
    }

    /**
     * The typed address in the one form sign-in looks it up in: trimmed,
     * lower-cased, and with a non-ASCII domain converted to its punycode form.
     * Null when it cannot be made into one, and for a blank address.
     *
     * DEFENCE IN DEPTH behind `sameAddress()`, at the door rather than at the
     * comparison. `gmaíl.com` becomes `xn--…`, which can never equal a stored
     * `gmail.com` however a collation folds it, and the mail goes to the mailbox
     * that was actually typed. A non-ASCII LOCAL part has no such conversion and
     * is refused: production stores no non-ASCII address, so nothing legitimate
     * is turned away, and an accent in front of the `@` can only be a look-alike.
     *
     * An address that is already plain ASCII is returned exactly as
     * `strtolower(trim())` would, so every address that signs in today
     * signs in as before. Nothing is done to an ASCII domain: no `idn_to_ascii`
     * rules are applied to it, so an old, unusual-looking but real address is not
     * newly refused.
     *
     * `idn_to_ascii()` comes from ext-intl or, without it,
     * symfony/polyfill-intl-idn (composer.lock, non-dev). Neither present means
     * null: sign-in refuses rather than guessing.
     */
    public static function submittedAddress(string $typed): ?string
    {
        // `foldCase()`, not `mb_strtolower()` (see `sameAddress()`): a non-ASCII
        // letter that would fold onto an ASCII one, such as U+212A KELVIN SIGN,
        // must stay non-ASCII here and be refused in a local part like any other
        // accent. A non-ASCII domain is case-folded by IDNA itself.
        $address = self::foldCase(trim($typed));

        if ($address === '') {
            return null;
        }

        if (preg_match('/[^\x00-\x7F]/', $address) !== 1) {
            return $address;
        }

        $at = strrpos($address, '@');

        if ($at === false || $at === 0) {
            return null;
        }

        $local = substr($address, 0, $at);
        $domain = substr($address, $at + 1);

        if ($domain === '' || preg_match('/[^\x00-\x7F]/', $local) === 1 || ! function_exists('idn_to_ascii')) {
            return null;
        }

        $ascii = idn_to_ascii(
            $domain,
            IDNA_DEFAULT | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_NONTRANSITIONAL_TO_ASCII,
            INTL_IDNA_VARIANT_UTS46,
        );

        return is_string($ascii) && $ascii !== '' ? $local . '@' . $ascii : null;
    }

    /**
     * Lower-case for comparison, without letting anything non-ASCII become ASCII.
     *
     * The ASCII capitals are lower-cased by `strtr()` (byte for byte, unaffected
     * by the process locale that `strtolower()` follows before PHP 8.2). Every
     * other character is lower-cased with `mb_strtolower()` ONLY when the result
     * is still non-ASCII: `É` becomes `é`, so `GMAÍL` and `gmaíl` are one
     * spelling, but a character whose lower case is an ASCII letter (U+212A
     * KELVIN SIGN, whose lower case is `k`) is left as it is. Text that is not
     * valid UTF-8 keeps its non-ASCII bytes untouched.
     */
    private static function foldCase(string $value): string
    {
        $value = strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');

        if (preg_match('/[^\x00-\x7F]/', $value) !== 1) {
            return $value;
        }

        $folded = preg_replace_callback('/[^\x00-\x7F]/u', function (array $match): string {
            $lower = mb_strtolower($match[0]);

            return preg_match('/[\x00-\x7F]/', $lower) === 1 ? $match[0] : $lower;
        }, $value);

        return $folded ?? $value;
    }
}
