<?php

namespace App\Services\Registrations;

use RuntimeException;

/**
 * A registration the service refuses to perform: a closed offering, a
 * cross-tenant reference, an inactive or mismatched fee plan, a post-checkout
 * adjustment, an unrecognized money kind.
 *
 * Deliberately ONE domain exception (not a hierarchy): T-006c's public
 * endpoints map it to a 422 with its message, and T-006d's admin surfaces do
 * the same. Intake ANSWER failures are not this — they throw Laravel's
 * ValidationException so the field-level error bag reaches the renderer.
 *
 * The named constructors keep the refusal messages in one place so the tests
 * pin behaviour (which refusal fired) without string-matching prose.
 */
class RegistrationException extends RuntimeException
{
    public static function crossTenant(string $what): self
    {
        return new self("Cross-tenant reference refused: {$what} does not belong to this organization.");
    }

    public static function planMismatch(): self
    {
        return new self('This fee plan does not belong to this offering.');
    }

    public static function planInactive(): self
    {
        return new self('This fee plan is no longer available.');
    }

    public static function unknownPlanKind(string $kind): self
    {
        // Money kinds never degrade (.claude/rules/registration-billing-data.md):
        // guessing "free" would waive a fee, guessing "one_time" could charge
        // the wrong shape. Fail loudly instead.
        return new self("Unrecognized fee plan kind '{$kind}' — money kinds never degrade.");
    }

    public static function offeringClosed(): self
    {
        return new self('This offering is not currently accepting registrations.');
    }

    public static function rosterMisconfigured(): self
    {
        return new self('This offering\'s roster group could not be resolved for its organization.');
    }

    public static function notConfirmable(string $status): self
    {
        return new self("Only a pending registration can be confirmed (status: {$status}).");
    }

    public static function adjustmentAfterCheckout(): self
    {
        return new self('Adjustments are strictly pre-checkout; this registration already has a payment leg.');
    }

    public static function adjustmentNotAReduction(): self
    {
        return new self('An adjustment is an unsigned reduction — it can never be negative or a surcharge.');
    }

    public static function unknownAdjustmentKind(string $kind): self
    {
        return new self("Unrecognized adjustment kind '{$kind}'.");
    }

    // ----------------------------------------------------- T-006c (checkout)

    public static function notCheckoutable(string $status): self
    {
        return new self("Only a pending registration can be checked out (status: {$status}).");
    }

    /**
     * The $0 branch of the ratified design: a free plan, or aid that waived the
     * total, has NO Stripe leg — it confirms in-request. Minting a zero-amount
     * Checkout Session is never the answer.
     */
    public static function nothingToCharge(): self
    {
        return new self('This registration has nothing left to pay — it does not use Stripe Checkout.');
    }

    /**
     * Installment/recurring plans need a subscription + schedule (T-006e).
     * Money kinds never degrade: refuse rather than charge the wrong shape.
     */
    public static function checkoutKindUnsupported(string $kind): self
    {
        return new self("Fee plan kind '{$kind}' cannot be checked out yet — one-time payments only.");
    }

    public static function orgCannotCollectPayments(): self
    {
        return new self('This organization is not able to accept online payments yet.');
    }

    // -------------------------------------------------- T-006d (admin surface)

    /**
     * Promotion moves someone OFF the waitlist; a pending/confirmed/cancelled
     * registration is not on it, so there is nothing to promote.
     */
    public static function notPromotable(string $status): self
    {
        return new self("Only a waitlisted registration can be promoted (status: {$status}).");
    }

    /**
     * The capacity invariant, re-checked under the offering's row lock: an
     * admin promoting off the waitlist can never oversell the offering.
     */
    public static function offeringFull(): self
    {
        return new self('This offering is full — free a seat before promoting from the waitlist.');
    }

    /**
     * Fee plans are IMMUTABLE once created (deactivate-and-replace): T-006a/b
     * snapshot the price onto every registration, so editing a live plan would
     * retroactively restate what somebody already agreed to pay.
     */
    public static function feePlanImmutable(): self
    {
        return new self('Fee plans are immutable — deactivate this plan and create a replacement instead of editing it.');
    }

    /**
     * Deleting an offering that still has live registrations would strand
     * people who hold seats (and, for paid ones, money already collected).
     */
    public static function offeringHasLiveRegistrations(int $count): self
    {
        return new self("This offering still has {$count} live registration(s) — deactivate it instead of deleting it.");
    }
}
