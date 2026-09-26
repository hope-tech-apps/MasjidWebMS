/**
 * How the form builder reads a stored `settings.fee`: which of its pricing choices the
 * form uses. Kept out of FormBuilder.vue so it can be tested on its own
 * (tests/form-fee-pricing.test.ts), because a fee read as the wrong choice is saved back
 * as that choice.
 *
 * 'perQuantity': the price x a number question's answer (settings.fee.perQuantityOf).
 * 'choice': priced by the answer to a choice question (settings.fee.byChoice), which only
 * form:import sets up; the builder offers it only for a form that already has it, and
 * saves it back untouched (Ramadan giving, 2026-09-25).
 */
export type FeePricing = 'none' | 'flat' | 'perEntry' | 'dateSteps' | 'count' | 'perQuantity' | 'choice';

/** A stored price as a number, or null when there is none (a blank, junk, not finite). */
export const feeAmountOf = (value: unknown): number | null => {
    if (typeof value === 'number') return Number.isFinite(value) ? value : null;

    if (typeof value === 'string' && value.trim() !== '') {
        const parsed = Number(value);
        return Number.isNaN(parsed) ? null : parsed;
    }

    return null;
};

/**
 * Which pricing a stored fee uses. A fee with no amount, no date steps, no prices by
 * number of entries and no prices by answer charges nothing (Form::feeRule() is null),
 * so it reads as No price.
 *
 * Prices by answer are checked first: such a form has no amount, and read as No price it
 * would be saved without its prices.
 */
export const feePricingOf = (fee: Record<string, any>): FeePricing => {
    if (fee.byChoice && typeof fee.byChoice === 'object') return 'choice';
    if (Array.isArray(fee.countTiers) && fee.countTiers.length) return 'count';
    if (Array.isArray(fee.tiers) && fee.tiers.length) return 'dateSteps';
    if (feeAmountOf(fee.amount) === null) return 'none';
    if (typeof fee.perQuantityOf === 'string' && fee.perQuantityOf) return 'perQuantity';
    return typeof fee.perEntryOfSection === 'string' && fee.perEntryOfSection ? 'perEntry' : 'flat';
};

/**
 * The `settings.fee` keys the builder edits itself. Everything else in a stored fee (a key
 * the builder has no control for, such as the imported prices by answer) is carried through
 * a save untouched (preservedFeeOf()). A key missing from here would be carried through
 * AND written, so switching pricing away from it could never remove it: a "price for each"
 * switched to a flat price would still be multiplied by its number question.
 *
 * `pricing` is Form::feeRule()'s computed marker, never part of what is saved.
 */
export const MANAGED_FEE_KEYS = ['amount', 'currency', 'perEntryOfSection', 'perQuantityOf', 'tiers', 'countTiers', 'pricing'] as const;

const without = (record: Record<string, any>, keys: readonly string[]): Record<string, any> => {
    const copy = { ...record };
    keys.forEach(key => delete copy[key]);
    return copy;
};

/** What of a stored fee a save carries through untouched: every key the builder does not edit. */
export const preservedFeeOf = (fee: Record<string, any>): Record<string, any> => without(fee, MANAGED_FEE_KEYS);

/** The builder's fee choices, as buildFee() reads them. Tiers arrive already built. */
export type FeeDraft = {
    pricing: FeePricing;
    currency: string;
    amount: number | null;
    perEntryOfSection: string | null;
    perQuantityOf: string | null;
    /** The date steps, built; read only under 'dateSteps'. */
    tiers: Record<string, any>[];
    /** The prices by number of entries, built; read only under 'count'. */
    countTiers: Record<string, any>[];
};

/**
 * The `settings.fee` a save sends, or null for none (the form is free). Only the chosen
 * pricing is sent: the server refuses countTiers beside an amount or date steps, prices by
 * answer beside any other price, and a quantity question beside a per-entry count.
 *
 *  - 'choice': the imported prices by answer go back exactly as loaded (the builder has no
 *    editor for them), with the currency and the quantity question.
 *  - any other pricing drops `byChoice`: switching an imported iftar form to a flat price
 *    must not leave its levels behind, which the server would refuse beside the amount.
 *  - `perQuantityOf` is sent under 'perQuantity', and under 'dateSteps' charged once per
 *    submission (an imported per-person price with date steps); never under a flat price,
 *    per entry or count.
 */
export const buildFee = (preservedFee: Record<string, any>, draft: FeeDraft): Record<string, any> | null => {
    const { pricing } = draft;
    const currency = (draft.currency || 'USD').toUpperCase();
    const withoutChoice = without(preservedFee, ['byChoice']);

    if (pricing === 'none') return null;

    if (pricing === 'choice') {
        return {
            ...preservedFee,
            currency,
            ...(draft.perQuantityOf ? { perQuantityOf: draft.perQuantityOf } : {})
        };
    }

    if (pricing === 'count') {
        if (!draft.countTiers.length) return null;

        return {
            ...withoutChoice,
            currency,
            perEntryOfSection: draft.perEntryOfSection || null,
            countTiers: draft.countTiers
        };
    }

    // A fee is an amount, price steps, or both: the festival form has steps and no amount.
    // With neither there is no fee, and the form is free.
    const tiers = pricing === 'dateSteps' ? draft.tiers : [];

    if (draft.amount === null && !tiers.length) return null;

    const fee: Record<string, any> = {
        ...withoutChoice,
        currency,
        perEntryOfSection: pricing === 'flat' || pricing === 'perQuantity' ? null : (draft.perEntryOfSection || null)
    };

    if (draft.amount !== null) fee.amount = draft.amount;
    if (tiers.length) fee.tiers = tiers;

    const quantity = pricing === 'perQuantity' || (pricing === 'dateSteps' && !fee.perEntryOfSection)
        ? draft.perQuantityOf
        : null;
    if (quantity) fee.perQuantityOf = quantity;

    return fee;
};
