<?php

namespace App\Support;

use App\Models\Form;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use LogicException;

/**
 * What a form response owes, in integer cents — the one place a form's
 * decimal-dollar fee (`settings.fee`, read through Form::feeRule()) becomes
 * money (DECISIONS.md 2026-09-11).
 *
 * Everything downstream is written from what this returns and never recomputed
 * from the float again: the row's amount_due_minor / fee_covered_minor /
 * total_minor snapshot, the Stripe line items, and a staff member's cash total.
 * A second conversion is how the page, the row and Stripe come to disagree by a
 * cent. The legacy `amount_due` decimal stays what FormSchema::amountDue() says;
 * the two agree because a paying form's prices are whole cents
 * (StoreFormRequest::crossCheck() refuses anything else).
 *
 * Amounts never come from the request. A payer's only say is the yes/no on
 * covering the card fee, and its amount is StripeFees::coverage() computed here
 * — the LunchOrderExtras rule.
 *
 * Pinned by tests/Unit/FormPaymentTest.php.
 */
final class FormPayment
{
    /**
     * Stripe's minimum charge in US dollars. Every price on a form that takes
     * payment must clear it (StoreFormRequest::crossCheck()), so a tier can never
     * put a paying form into the "$0, nothing to charge" state.
     */
    public const MIN_CHARGE_MINOR = 50;

    /** Stripe's maximum charge in US dollars ($999,999.99), for the checkout preflight. */
    public const MAX_CHARGE_MINOR = 99_999_999;

    /** Named on the hosted page: the payer agreed to a fee, so it is not folded into the price. */
    public const FEE_LINE_NAME = 'Card processing fee';

    /**
     * THE float-to-cents conversion. round() before the cast: 19.99 * 100 is
     * 1998.9999999999998 in binary floating point, and a bare (int) would charge
     * 1998.
     */
    public static function toMinor(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * The amount in cents when it is a whole number of cents (at most two
     * decimal places), otherwise null — including for anything not numeric.
     *
     * What the settings cross-check asks of every price on a paying form: 12.345
     * has no honest cent value, and rounding it on the way to Stripe would charge
     * an amount nobody typed.
     */
    public static function wholeMinor(mixed $amount): ?int
    {
        if (! is_int($amount) && ! is_float($amount) && ! (is_string($amount) && is_numeric($amount))) {
            return null;
        }

        $cents = ((float) $amount) * 100;

        // A tolerance, not equality: 19.99 * 100 is not exactly 1999 either. It is
        // far below half a cent and far above float error for any price the
        // settings rules allow (at most $1,000,000, i.e. 1e8 cents).
        if (! is_finite($cents) || abs($cents - round($cents)) > 1e-6) {
            return null;
        }

        return self::toMinor($amount);
    }

    /**
     * The price of one unit — one entry of the section the fee is charged per
     * entry of, or the whole registration on a flat fee — in force at $at, in
     * cents. The tier is resolved in the masjid's timezone (Form::feeRule()).
     * Null when the form charges nothing.
     */
    public static function unitMinor(Form $form, ?CarbonInterface $at = null): ?int
    {
        $fee = $form->feeRule($at);

        return $fee === null ? null : self::toMinor($fee['amount']);
    }

    /**
     * How many units a submission is charged for: the row count of the
     * `perEntryOfSection` section — the count FormSchema::amountDue() multiplies
     * by — or 1 on a flat fee.
     *
     * NOT `entry_count`. That counts the FIRST repeatable section and never goes
     * below 1 (FormSchema::entryCount()), so on a form with two repeatable
     * sections, or an empty attendee list, it disagrees with what is owed — and a
     * Stripe line built from it would not sum to the total.
     *
     * @param  array<string,mixed>  $data  the cleaned submission (FormSchema::only())
     */
    public static function quantity(Form $form, array $data): int
    {
        return self::quantityFor($form->feeRule(), $data);
    }

    /**
     * What a submission owes before any card fee — unit × quantity, in cents.
     * Null when the form charges nothing, the null `amount_due` has always meant.
     *
     * @param  array<string,mixed>  $data
     */
    public static function amountDueMinor(Form $form, array $data, ?CarbonInterface $at = null): ?int
    {
        return self::quote($form, $data, false, false, $at)['amount_due_minor'] ?? null;
    }

    /**
     * The card fee a payer asked to cover, in cents.
     *
     * The client says yes or no and nothing else: the amount is
     * StripeFees::coverage() of what is owed, so the organisation nets the full
     * price. Only when the form offers it (Form::allowsFeeCoverage(), which also
     * requires card payment to be on) and only for a CARD payment — cash at the
     * gate has no card fee to cover.
     */
    public static function feeCoveredMinor(Form $form, int $amountDueMinor, bool $coverFees, bool $online): int
    {
        return ($coverFees && $online && $form->allowsFeeCoverage())
            ? StripeFees::coverage($amountDueMinor)
            : 0;
    }

    /** The form's currency as Stripe and the money columns spell it: lower-case ISO-4217. */
    public static function currencyFor(Form $form): string
    {
        return self::currencyOf($form->feeRule());
    }

    /**
     * Everything a response's money leg is written from, out of ONE resolution
     * of the fee rule, so the unit price and its tier label cannot come from
     * either side of a tier's midnight.
     *
     * `line_items` are Stripe's shape and are what the checkout sends: one line
     * of `quantity` units at the unit price, named after the form and the tier in
     * force, plus the named card-fee line when a fee is covered. They must sum to
     * `total_minor`, and that is asserted HERE — so a mismatch throws while the
     * caller has written nothing, instead of after the row is committed.
     *
     * A total of 0 carries no lines (Stripe refuses a zero-quantity line, and
     * there is never a $0 session). Whether a paying form may owe nothing is the
     * caller's to refuse — with a 422, never the free path — and `total_minor`
     * is what it reads.
     *
     * Null when the form charges nothing.
     *
     * @param  array<string,mixed>  $data  the cleaned submission (FormSchema::only())
     * @return array{
     *     currency: string,
     *     unit_minor: int,
     *     quantity: int,
     *     amount_due_minor: int,
     *     fee_covered_minor: int,
     *     total_minor: int,
     *     tier_label: ?string,
     *     line_items: array<int,array{quantity:int,price_data:array{currency:string,unit_amount:int,product_data:array{name:string}}}>
     * }|null
     *
     * @throws LogicException when a price is negative or the lines do not add up
     */
    public static function quote(
        Form $form,
        array $data,
        bool $coverFees = false,
        bool $online = false,
        ?CarbonInterface $at = null
    ): ?array {
        $fee = $form->feeRule($at);

        if ($fee === null) {
            return null;
        }

        $unit = self::toMinor($fee['amount']);

        // The settings rules refuse a negative price; this is the backstop for a
        // row written some other way. A negative line is a refund nobody asked for.
        if ($unit < 0) {
            throw new LogicException("Form {$form->id} has a negative price, so nothing can be charged for it.");
        }

        $quantity = self::quantityFor($fee, $data);
        $amountDue = $unit * $quantity;
        $feeCovered = self::feeCoveredMinor($form, $amountDue, $coverFees, $online);
        $total = $amountDue + $feeCovered;
        $currency = self::currencyOf($fee);
        $tierLabel = self::tierLabel($fee);

        $lines = [];

        if ($amountDue > 0) {
            $lines[] = self::line($currency, $unit, $quantity, self::lineName($form, $tierLabel));
        }

        if ($feeCovered > 0) {
            $lines[] = self::line($currency, $feeCovered, 1, self::FEE_LINE_NAME);
        }

        self::assertLinesMatchTotal($lines, $total);

        return [
            'currency' => $currency,
            'unit_minor' => $unit,
            'quantity' => $quantity,
            'amount_due_minor' => $amountDue,
            'fee_covered_minor' => $feeCovered,
            'total_minor' => $total,
            'tier_label' => $tierLabel,
            'line_items' => $lines,
        ];
    }

    /**
     * Throw unless the Stripe line items add up to exactly `$totalMinor`.
     *
     * Public so the checkout can ask it again of the exact params it is about to
     * send, right before the Stripe call: the hosted page must never show one
     * total while the row records another.
     *
     * @param  array<int,array<string,mixed>>  $lines
     *
     * @throws LogicException
     */
    public static function assertLinesMatchTotal(array $lines, int $totalMinor): void
    {
        $sum = 0;

        foreach ($lines as $line) {
            $quantity = is_array($line) ? ($line['quantity'] ?? null) : null;
            $unit = is_array($line) ? ($line['price_data']['unit_amount'] ?? null) : null;

            if (! is_int($quantity) || ! is_int($unit) || $quantity < 1 || $unit < 0) {
                throw new LogicException('A payment line needs a whole quantity of at least 1 and a whole unit amount in cents.');
            }

            $sum += $quantity * $unit;
        }

        if ($sum !== $totalMinor) {
            throw new LogicException("The payment lines add up to {$sum} cents but the total is {$totalMinor} cents.");
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string,mixed>|null  $fee  Form::feeRule()
     * @param  array<string,mixed>  $data
     */
    private static function quantityFor(?array $fee, array $data): int
    {
        // Exactly FormSchema::amountDue()'s reading, so the cents and the legacy
        // decimal never count differently.
        $perEntry = $fee['perEntryOfSection'] ?? null;

        if ($perEntry === null) {
            return 1;
        }

        $rows = Arr::get($data, $perEntry, []);

        return is_array($rows) ? count($rows) : 0;
    }

    /** @param  array<string,mixed>|null  $fee */
    private static function currencyOf(?array $fee): string
    {
        $currency = strtolower(trim((string) ($fee['currency'] ?? '')));

        return $currency !== '' ? $currency : 'usd';
    }

    /** @param  array<string,mixed>  $fee */
    private static function tierLabel(array $fee): ?string
    {
        $label = $fee['currentTier']['label'] ?? null;

        if (! is_string($label) || trim($label) === '') {
            return null;
        }

        return trim($label);
    }

    private static function lineName(Form $form, ?string $tierLabel): string
    {
        $name = trim((string) $form->name);
        $name = $name !== '' ? $name : 'Registration';

        return $tierLabel === null ? $name : "{$name} ({$tierLabel})";
    }

    /** @return array{quantity:int,price_data:array{currency:string,unit_amount:int,product_data:array{name:string}}} */
    private static function line(string $currency, int $unitMinor, int $quantity, string $name): array
    {
        return [
            'quantity' => $quantity,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $unitMinor,
                'product_data' => ['name' => $name],
            ],
        ];
    }
}
