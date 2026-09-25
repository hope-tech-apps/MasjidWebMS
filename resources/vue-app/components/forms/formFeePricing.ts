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
