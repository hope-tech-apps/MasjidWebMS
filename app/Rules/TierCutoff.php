<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A fee tier's `until` — the date-stepped price cut-off — as ONE statement of
 * the contract, applied by every door that can write one.
 *
 * ## The three shapes, and what each means
 *
 *   absent / null / blank      NO CUT-OFF. This tier is the final, open-ended
 *                              price. `database/forms/camp-2026.json` spells it
 *                              by omitting the key; the builder spells it by
 *                              clearing the box.
 *   'YYYY-MM-DD', zero-padded  A calendar date, INCLUSIVE.
 *   anything else              REFUSED. Prose, an impossible date, an unpadded
 *                              one, a number — every value somebody TRIED to
 *                              make a date out of and failed.
 *
 * ## Why blank is "no cut-off" and not a refusal
 *
 * Because that is what this stack has always done, measurably, on the door
 * administrators actually use. `TrimStrings` and `ConvertEmptyStringsToNull`
 * are GLOBAL middleware here (bootstrap/app.php), so `''` and `'   '` are
 * already null by the time any rule can see them. Measured, one document,
 * `settings.fee.tiers[0].until`, reading the row back out of the database:
 *
 *     ''       POST 201 -> stored NULL        form:import -> stored ''
 *     '   '    POST 201 -> stored NULL        form:import -> stored ''
 *     null     POST 201 -> stored NULL        form:import -> stored NULL
 *
 * THAT is the divergence between the two doors, and it was invisible because it
 * lived in middleware rather than in either file: the API cannot see a blank,
 * and the importer stored one verbatim. Refusing blanks would have been a rule
 * only the importer could ever apply — a third meaning for one spelling, which
 * is the shape this review keeps finding. So the importer normalises instead
 * (`ImportFormCommand`), the two doors store the identical row, and
 * `Form::resolveTier()` reads a blank the same way for rows already written.
 *
 * ## What the FORMAT clause is for
 *
 * Round six made padding mandatory because `Form::resolveTier()` compares the
 * cut-off LEXICALLY, and `'2026-09-10' <= '2026-8-14'` is TRUE (`'0' < '8'` at
 * index 5), so an unpadded tier never expires — $40 per attendee, for four
 * months, on the committee's real camp form. Loosening this re-opens that.
 *
 * `$implicit = true` is what makes this run at all on a null or absent value;
 * without it Laravel treats the attribute as "not there" and no rule in the
 * list executes — which is exactly how `nullable|date_format:Y-m-d` never saw
 * the blank spellings. It is also why the null case is handled explicitly here
 * rather than left to a `nullable` alongside it.
 *
 * Deliberately NOT `strtotime()` / `Carbon::parse()`: both accept relative
 * strings ("next friday"), which would make a typo in a fee schedule silently
 * mean something.
 */
class TierCutoff implements ValidationRule
{
    /** Run even when the value is absent, null or blank — that is the point. */
    public bool $implicit = true;

    /**
     * The value a cut-off is STORED as — what the HTTP stack's
     * TrimStrings + ConvertEmptyStringsToNull already produce, so a door that
     * does not sit behind them can produce the same row.
     */
    public static function normalise(mixed $until): mixed
    {
        if (! is_string($until)) {
            return $until;
        }

        $trimmed = trim($until);

        return $trimmed === '' ? null : $trimmed;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // No cut-off at all: this tier is the open-ended final price. A blank
        // string is the same statement — see the class docblock; on every HTTP
        // door it has already become null before reaching here.
        if (self::normalise($value) === null) {
            return;
        }

        if (! is_string($value)) {
            $fail('A fee tier cut-off must be a calendar date written as YYYY-MM-DD.');

            return;
        }

        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m)) {
            $fail('A fee tier cut-off must be a calendar date written as YYYY-MM-DD, zero-padded (2026-08-14, not 2026-8-14).');

            return;
        }

        [, $year, $month, $day] = $m;

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            $fail('A fee tier cut-off must be a real calendar date.');
        }
    }
}
