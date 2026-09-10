<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Compiles a staging-scrub strategy name into an SQL expression.
 *
 * ## Why the fakes are computed in SQL and derived from the primary key
 *
 * A production copy of `contacts` or `group_messages` is hundreds of thousands
 * of rows. Pulling each one into PHP to invent a name would turn a two-minute
 * refresh into an afternoon, so every strategy that CAN be expressed as an
 * `UPDATE … SET col = <expr>` is. Only the json strategies need PHP, and the
 * command handles those separately.
 *
 * Deriving the fake from the row's own id (never from `rand()`, never from a
 * constant) buys three things at once:
 *
 *  1. **Unique indexes survive.** `users.email`, `masjids.email`,
 *     `masjids.phone`, `contacts(masjid_id, login_email)`,
 *     `contact_cards(contact_id, last4)` and
 *     `masjid_social_media_links(masjid_id, type, value)` are all UNIQUE. One
 *     constant value would abort the UPDATE on the second row.
 *  2. **Re-running is idempotent.** The second run writes the same bytes, so an
 *     interrupted refresh can simply be repeated.
 *  3. **A staging bug stays traceable.** "contact-4182 cannot check out" maps
 *     back to production row 4182 without anyone storing that person's name.
 *
 * ## Why the outputs are unreachable, not merely fake
 *
 * Emails end in `.invalid`, the TLD RFC 2606 reserves precisely so that it can
 * never resolve: a staging box with a real mailer misconfigured into it still
 * cannot deliver. Phones are `+1 555 01xx xxx` — NANP area code 555 is
 * permanently unassignable, so the number cannot be dialled or texted even if
 * an SMS provider were somehow live. `StagingScrub`'s verification pass
 * re-reads every such column and fails the run if one value escaped.
 *
 * ## Driver differences that matter here
 *
 * Production staging is MySQL 8; the test suite is in-memory sqlite. The two
 * disagree about string concatenation (`CONCAT(...)` vs `||`), zero padding
 * (`LPAD` exists only on MySQL) and date arithmetic (`DATE_ADD` vs the
 * `datetime(col, '<n> days')` modifier form). Everything divergent is funnelled
 * through the three private helpers at the bottom, so a new strategy gets both
 * dialects for free. `%` (modulo) and `CASE` are spelled identically on both
 * and are used directly.
 */
final class ScrubStrategies
{
    /**
     * RFC 2606 reserves `.invalid` as a TLD guaranteed never to resolve. Nothing
     * addressed here can be delivered, by anyone, ever.
     */
    public const EMAIL_DOMAIN = 'staging.invalid';

    /**
     * NANP area code 555 is permanently unassignable, and 555-01xx is the block
     * explicitly reserved for fiction. Five padded digits of row id follow, so
     * the whole thing is a valid 11-digit E.164 US number that cannot ring.
     */
    public const PHONE_PREFIX = '+155501';

    /** What every scrubbed free-text column says, so an operator knows why it is empty of meaning. */
    public const FREE_TEXT = '[scrubbed for staging]';

    /** What json_replace writes in place of each leaf value, keeping the key. */
    public const JSON_PLACEHOLDER = '[scrubbed]';

    /**
     * Given names and surnames. Sixteen of each, chosen by `id % 16`, because a
     * staging dataset of "First1, First2, First3" is unusable for the thing
     * staging is for — eyeballing a roster, a receipt, a class list. Uniqueness
     * is not required of either column: no index in the schema is unique on a
     * person's name.
     */
    private const GIVEN_NAMES = [
        'Aisha', 'Bilal', 'Fatima', 'Hamza', 'Layla', 'Musa', 'Noor', 'Omar',
        'Rania', 'Salim', 'Tahira', 'Usman', 'Yasmin', 'Zaid', 'Amina', 'Idris',
    ];

    private const SURNAMES = [
        'Rahman', 'Siddiqui', 'Farouk', 'Haddad', 'Nasser', 'Qureshi', 'Bakri', 'Chowdhury',
        'Diallo', 'Ellison', 'Ghazi', 'Hakim', 'Iqbal', 'Jamal', 'Karim', 'Lodhi',
    ];

    /**
     * Strategies that cannot be expressed in SQL and are applied row by row in
     * PHP by the command. Kept here so both the command and the coverage test
     * agree on the list.
     *
     * @var list<string>
     */
    public const PHP_STRATEGIES = ['json_replace', 'json_contacts'];

    /**
     * Every strategy this class understands, for config validation. `fixed:` and
     * `label:` take an argument and are matched by prefix.
     *
     * @var list<string>
     */
    public const SIMPLE_STRATEGIES = [
        'first_name', 'last_name', 'full_name', 'email', 'phone', 'address',
        'free_text', 'date_shift', 'digits4', 'device_id', 'password',
    ];

    /**
     * Compile one strategy into an SQL expression for `SET <column> = <expr>`.
     *
     * @param  string  $driver  'mysql' or 'sqlite'
     * @param  string  $key     the quoted primary key column, e.g. "id"
     * @param  array{password_hash?: string}  $context  run-scoped values
     */
    public static function expression(
        string $strategy,
        string $table,
        string $column,
        string $key,
        string $driver,
        array $context = [],
    ): string {
        if (in_array($strategy, self::PHP_STRATEGIES, true)) {
            throw new InvalidArgumentException(
                "`{$strategy}` is applied in PHP, not SQL; StagingScrub must not ask for an expression."
            );
        }

        if (str_starts_with($strategy, 'fixed:')) {
            return self::quote(substr($strategy, strlen('fixed:')));
        }

        if (str_starts_with($strategy, 'label:')) {
            return self::concat($driver, self::quote(substr($strategy, strlen('label:')).' '), self::text($driver, $key));
        }

        return match ($strategy) {
            'first_name' => self::caseOver($key, self::GIVEN_NAMES),
            'last_name' => self::caseOver($key, self::SURNAMES),
            'full_name' => self::concat(
                $driver,
                self::caseOver($key, self::GIVEN_NAMES),
                self::quote(' '),
                self::caseOver($key, self::SURNAMES),
            ),

            // "<singular table>[-<qualifier>]-<id>@staging.invalid". The
            // qualifier keeps `contacts.email` and `contacts.login_email`
            // distinct on the SAME row, which matters because the portal treats
            // them as different identities.
            'email' => self::concat(
                $driver,
                self::quote(self::emailLocalPrefix($table, $column)),
                self::text($driver, $key),
                self::quote('@'.self::EMAIL_DOMAIN),
            ),

            'phone' => self::concat(
                $driver,
                self::quote(self::PHONE_PREFIX),
                self::lpad($driver, $key, 5),
            ),

            'address' => self::concat(
                $driver,
                self::text($driver, $key),
                self::quote(' Staging Way, Springfield, NC 27000'),
            ),

            'free_text' => self::quote(self::FREE_TEXT),

            // Four digits, zero padded. UNIQUE(contact_id, last4) survives
            // because two cards on ONE contact would have to have ids exactly a
            // multiple of 10000 apart to collide; the column is `string(4)`, so
            // the modulo is not optional.
            'digits4' => self::lpad($driver, "({$key} % 10000)", 4),

            'device_id' => self::concat($driver, self::quote('staging-device-'), self::text($driver, $key)),

            // One hash, computed once per run by the command and passed in.
            // Bcrypting per row would dominate the runtime of the whole scrub.
            'password' => self::quote(
                $context['password_hash']
                    ?? throw new InvalidArgumentException('The `password` strategy needs a `password_hash` in its context.')
            ),

            // Shift by a deterministic -30..+30 days. Blurs a date without
            // destroying its ordering or its relationship to the row's other
            // timestamps. Currently unused: the schema's only date-of-birth
            // column, `appointment_requests.date_of_birth`, is an `encrypted`
            // cast on a table that is dropped wholesale, so there is nothing
            // left to shift. It stays implemented because the first plain-date
            // birthday column to land will need it, and a strategy invented
            // under deadline is a strategy nobody reviews.
            'date_shift' => self::dateShift($driver, $column, $key),

            default => throw new InvalidArgumentException("Unknown scrub strategy `{$strategy}` for {$table}.{$column}."),
        };
    }

    /**
     * Replace every leaf VALUE in a decoded json document with a placeholder,
     * keeping every KEY and the document's shape.
     *
     * `form_responses.data` is the answers to an arbitrary tenant-authored form:
     * names, dates of birth, medical notes, addresses. There is no per-key
     * scrub, because the tenant wrote the keys. But the admin response table and
     * the CSV export render one column per key, so blanking the document to
     * `{}` would make every one of those screens untestable on staging. Keeping
     * the keys keeps the screens honest and the answers gone.
     */
    public static function jsonReplace(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn ($inner) => self::jsonReplace($inner), $value);
        }

        // Scalars are the leaves. `null` stays null so "unanswered" still reads
        // as unanswered rather than as an answer of "[scrubbed]".
        return $value === null ? null : self::JSON_PLACEHOLDER;
    }

    /**
     * Replace only the address-shaped and phone-shaped string values in a json
     * document, recursively.
     *
     * `forms.settings` mixes real configuration (which notification channel is
     * on, whether the form is capped) with the addresses a submission is
     * notified to. Blanking the whole document would break form rendering on
     * staging and buy no privacy that this does not.
     */
    public static function jsonContacts(mixed $value, string $path = ''): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $inner) {
                $out[$k] = self::jsonContacts($inner, is_string($k) ? $k : $path);
            }

            return $out;
        }

        if (! is_string($value)) {
            return $value;
        }

        if (str_contains($value, '@') && preg_match('/^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/', trim($value)) === 1) {
            return 'settings-'.substr(md5($value), 0, 8).'@'.self::EMAIL_DOMAIN;
        }

        // A phone number in a settings blob: seven or more digits once the
        // formatting characters are stripped, and nothing else of substance.
        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';
        if (strlen($digits) >= 7 && preg_match('/^[0-9 ()+\-.]+$/', trim($value)) === 1) {
            return self::PHONE_PREFIX.substr(str_pad((string) crc32($value), 5, '0', STR_PAD_LEFT), -5);
        }

        return $value;
    }

    /**
     * The local part before the row id: `contact-`, `contact-login-`,
     * `form_response-respondent-`. Naive singularisation is enough — every table
     * name in this schema that reaches here is a plain plural.
     */
    public static function emailLocalPrefix(string $table, string $column): string
    {
        $singular = preg_replace('/s$/', '', $table) ?? $table;

        // `email` itself contributes nothing; anything else (login_email,
        // respondent_email, customer_email) becomes a dash-separated qualifier.
        $qualifier = preg_replace('/_?email$/', '', $column) ?? '';

        return $qualifier === '' || $qualifier === 'email'
            ? $singular.'-'
            : $singular.'-'.str_replace('_', '-', $qualifier).'-';
    }

    /** `CASE (id % n) WHEN 0 THEN '…' … END` — spelled identically on MySQL and sqlite. */
    private static function caseOver(string $key, array $values): string
    {
        $count = count($values);
        $sql = "CASE ({$key} % {$count})";

        foreach ($values as $i => $value) {
            $sql .= " WHEN {$i} THEN ".self::quote($value);
        }

        // The ELSE can only be reached by a NULL or negative key, neither of
        // which any table here has; it exists so the expression is never NULL.
        return $sql.' ELSE '.self::quote($values[0]).' END';
    }

    /** MySQL has CONCAT(); sqlite has ||. */
    private static function concat(string $driver, string ...$parts): string
    {
        return $driver === 'mysql'
            ? 'CONCAT('.implode(', ', $parts).')'
            : '('.implode(' || ', $parts).')';
    }

    /**
     * Zero-pad to a MINIMUM width, never a fixed one.
     *
     * The obvious spelling — MySQL's `LPAD(id, 5, '0')`, or sqlite's
     * `SUBSTR('00000' || id, -5)` — silently TRUNCATES once the value is longer
     * than the width. On a production copy of `contacts` that is not academic:
     * row 123456 and row 123457 would both pad to `12345`, and two different
     * people would end up sharing a phone number. Worse, on a UNIQUE column
     * (`masjids.phone`, `masjid_social_media_links(masjid_id, type, value)`)
     * the duplicate aborts the UPDATE and rolls the whole table back.
     *
     * So the pad is built from the shortfall instead. Past 99,999 rows the fake
     * simply grows a digit — a 12-digit number in an area code that cannot be
     * dialled is a far better outcome than either a collision or a mid-run
     * abort, and staging has no SMS provider to hand it to in any case.
     */
    private static function lpad(string $driver, string $expr, int $length): string
    {
        $pad = str_repeat('0', $length);

        if ($driver === 'mysql') {
            $value = "CAST({$expr} AS CHAR)";

            return "CONCAT(LEFT('{$pad}', GREATEST(0, {$length} - CHAR_LENGTH({$value}))), {$value})";
        }

        $value = "CAST({$expr} AS TEXT)";

        // sqlite's two-argument MAX() is the scalar maximum, not the aggregate.
        return "(SUBSTR('{$pad}', 1, MAX(0, {$length} - LENGTH({$value}))) || {$value})";
    }

    /** An integer expression rendered as text so it can be concatenated. */
    private static function text(string $driver, string $expr): string
    {
        return $driver === 'mysql'
            ? "CAST({$expr} AS CHAR)"
            : "CAST({$expr} AS TEXT)";
    }

    private static function dateShift(string $driver, string $column, string $key): string
    {
        // -30..+30 days, decided by the row id.
        $days = "(({$key} % 61) - 30)";

        return $driver === 'mysql'
            ? "DATE_ADD({$column}, INTERVAL {$days} DAY)"
            : "datetime({$column}, CAST({$days} AS TEXT) || ' days')";
    }

    /**
     * Single-quote an SQL string literal.
     *
     * Every value that reaches here comes from this repository's own config
     * file or from `Hash::make()`, never from the database being scrubbed or
     * from user input — but doubling the quote costs nothing and means a
     * `fixed:` value containing an apostrophe is a typo rather than a broken
     * statement.
     */
    private static function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
