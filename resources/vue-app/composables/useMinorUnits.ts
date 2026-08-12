/**
 * INTEGER MINOR UNITS — the only place in the SPA that is allowed to turn a
 * money integer into text, or a typed amount into a money integer.
 *
 * All money in this system is an integer number of minor units (cents), never a
 * float (.claude/rules/stripe-payments.md). Two conversions are unavoidable at
 * a UI boundary and both are done here, once:
 *
 *  1. RENDER: `formatMinor(4999, 'usd')` → "$49.99". Division happens for
 *     display only; the result is a string and can never travel back to the API.
 *  2. ACCEPT: `parseMajorToMinor('49.99', 'usd')` → 4999. Parsed from the
 *     DECIMAL STRING the admin typed, digit by digit — never `Number(x) * 100`,
 *     which turns 49.99 into 4998.999999999999 and then, after a careless
 *     `Math.round` somewhere else, into whatever that rounds to. The integer it
 *     returns is the ONLY value that is ever sent.
 *
 * The exponent (2 for USD, 0 for JPY) comes from `Intl` rather than a hardcoded
 * 100, so a zero-decimal currency is not silently inflated a hundredfold.
 *
 * NOTHING ELSE BELONGS HERE. This module does not sum a ledger, does not
 * subtract an adjustment from a total, and does not divide a commitment into
 * installments — the server owns every one of those numbers, and a figure this
 * SPA invented would look exactly as authoritative as one the API served.
 */

/** Used when a currency code is unknown or `Intl` refuses it. */
const DEFAULT_EXPONENT = 2;

/**
 * The largest number of MAJOR-unit digits `parseMajorToMinor` will accept.
 *
 * 10^12 major units × 100 = 10^14, comfortably inside IEEE-754's exact-integer
 * range (2^53 ≈ 9.007 × 10^15), so every accepted input converts exactly. Above
 * that the arithmetic could round, so the parse refuses instead of quietly
 * returning a number that is off by a few cents.
 */
const MAX_MAJOR_DIGITS = 12;

export type MinorParseResult =
    | { ok: true; minor: number }
    | { ok: false; reason: string };

/** `usd` → `USD`; blank/absent degrades to USD rather than crashing `Intl`. */
function normalizeCode(currency: string | null | undefined): string {
    const code = String(currency ?? '').trim();

    return (code === '' ? 'usd' : code).toUpperCase();
}

/**
 * How many decimal places this currency has: 2 for USD/CAD/EUR, 0 for JPY, 3
 * for KWD. Read from `Intl` so the app never assumes "cents = hundredths".
 */
export function currencyExponent(currency: string | null | undefined): number {
    try {
        const options = new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: normalizeCode(currency)
        }).resolvedOptions();

        const digits = options.maximumFractionDigits;

        return typeof digits === 'number' && digits >= 0 ? digits : DEFAULT_EXPONENT;
    } catch (e) {
        return DEFAULT_EXPONENT;
    }
}

/**
 * Integer minor units → a localized currency string.
 *
 * Takes a REQUIRED number: a screen that might not have an amount must use
 * `formatMinorOrDash` and get an em dash, never a fabricated "$0.00".
 */
export function formatMinor(amountMinor: number, currency: string | null | undefined): string {
    const code = normalizeCode(currency);
    const exponent = currencyExponent(code);
    const minor = Number.isFinite(amountMinor) ? amountMinor : 0;
    const major = minor / Math.pow(10, exponent);

    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: code }).format(major);
    } catch (e) {
        // An unexpected code: still show the number and say what it is in.
        return `${major.toFixed(exponent)} ${code}`;
    }
}

/**
 * The same, but an em dash when the API served no value.
 *
 * `stripe_fee_minor` and `net_minor` are null unless a balance transaction was
 * expanded on the webhook payload — null there means UNKNOWN, not zero, and
 * printing "$0.00" would assert that Stripe took no fee.
 */
export function formatMinorOrDash(
    amountMinor: number | null | undefined,
    currency: string | null | undefined
): string {
    if (amountMinor === null || amountMinor === undefined || !Number.isFinite(Number(amountMinor))) {
        return '—';
    }

    return formatMinor(Number(amountMinor), currency);
}

/**
 * What an admin typed ("150", "49.99") → integer minor units.
 *
 * Refuses rather than guesses, every time:
 *  - anything that is not plain digits with at most one decimal point (no minus
 *    sign — the fee-plan endpoint takes an unsigned amount; no currency symbol;
 *    no exponent notation);
 *  - MORE decimal places than the currency has, because silently dropping the
 *    third digit of "10.999" would charge a different price than the one on
 *    screen;
 *  - an amount so large the conversion could not be exact.
 *
 * Thousands separators and spaces are stripped first, so a pasted "1,500.00"
 * works. Everything else is a message the form shows next to the field.
 */
export function parseMajorToMinor(
    input: string,
    currency: string | null | undefined
): MinorParseResult {
    const code = normalizeCode(currency);
    const exponent = currencyExponent(code);
    const raw = String(input ?? '').trim().replace(/[\s,]/g, '');

    if (raw === '') {
        return { ok: false, reason: 'Enter an amount.' };
    }

    if (!/^\d+(\.\d*)?$/.test(raw)) {
        return {
            ok: false,
            reason: 'Use digits only — for example 150 or 150.00. No currency symbol and no minus sign.'
        };
    }

    const [whole, fraction = ''] = raw.split('.');

    if (whole.length > MAX_MAJOR_DIGITS) {
        return { ok: false, reason: 'That amount is too large to charge in one plan.' };
    }

    if (fraction.length > exponent) {
        return {
            ok: false,
            reason: exponent === 0
                ? `${code} has no decimal places — enter a whole amount.`
                : `${code} has ${exponent} decimal place${exponent === 1 ? '' : 's'}; that has ${fraction.length}.`
        };
    }

    // Pad the typed fraction out to the currency's exponent so "49.5" is 4950,
    // not 495. Both halves are integers, so the sum is exact.
    const padded = exponent === 0 ? '' : (fraction + '0'.repeat(exponent)).slice(0, exponent);
    const minor = Number(whole) * Math.pow(10, exponent) + (padded === '' ? 0 : Number(padded));

    if (!Number.isSafeInteger(minor)) {
        return { ok: false, reason: 'That amount is too large to charge in one plan.' };
    }

    return { ok: true, minor };
}

/** Everything above, for use inside a `<script setup>` block. */
export function useMinorUnits() {
    return { currencyExponent, formatMinor, formatMinorOrDash, parseMajorToMinor };
}
