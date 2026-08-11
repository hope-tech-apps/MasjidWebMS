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
}
