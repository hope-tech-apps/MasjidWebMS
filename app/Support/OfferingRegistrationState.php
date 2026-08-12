<?php

namespace App\Support;

use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Offering;

/**
 * CAN THIS OFFERING TAKE A REGISTRATION RIGHT NOW, AND IF NOT, WHY — decided in
 * ONE place, for the public page and for every admin screen alike.
 *
 * ## Why one place
 *
 * There were two answers to this question and they disagreed. The public
 * payload's `registration_state` checked the window and the intake form;
 * `OfferingSectionEditor.vue` checked the window and the ACTIVE FEE PLAN COUNT;
 * `OfferingsView.vue`'s Status column and `OfferingDetailView.vue`'s header
 * badge checked only `is_open`. So an offering with a form, an open window and
 * zero active fee plans reported:
 *
 *   public  registration_state = "open",  fee_plans = []   <- a parent fills the
 *                                                             form in and then
 *                                                             POST /quote 404s
 *                                                             "This fee plan is
 *                                                             not available."
 *   admin   a green "Open" badge on two screens
 *   editor  a correct warning, in the one surface a family never sees
 *
 * and an offering whose intake form an admin had tidied away reported:
 *
 *   public  registration_state = "closed", intake_form = null
 *   admin   is_open = true, closed_reason = null  -> a green "Open" badge
 *
 * Both are the codebase's recurring shape: a write that cannot succeed while
 * every surface reports success. A verdict computed in four places is four
 * chances to forget a clause, so the verdict is computed here and the clauses
 * are the WRITE PATH's, enumerated below.
 *
 * ## The clauses, and where each one is enforced on the write path
 *
 * `RegistrationService::register()` + `OfferingRegistrationsController` refuse a
 * registration for exactly these reasons, in this order:
 *
 *   1. `! $offering->is_active`                 -> offeringClosed()          [inactive]
 *   2. outside opens_at/closes_at               -> offeringClosed()          [not_yet_open|closed]
 *   3. the intake Form does not resolve         -> offeringClosed()          [no_intake_form]
 *      (forms SOFT-delete; `offerings.intake_form_id` is NOT NULL, so the
 *      column never goes empty — the row it points at goes away underneath it)
 *   4. no ACTIVE fee plan of a KNOWN kind       -> 404 "This fee plan is not
 *      available." from findFeePlan(), because `register` takes a
 *      `fee_plan_id` and there is no plan to name                            [no_fee_plan]
 *   5. every seat taken                         -> WAITLISTED, not refused
 *
 * Clause 5 is why `waitlist` is a state of its own and not a flavour of closed:
 * `register()` accepts the sign-up and queues it, so a page that said "closed"
 * would turn away people the organisation wants on its waitlist.
 *
 * Clause 4 must beat clause 5. A full offering with no purchasable plan is
 * refused at `findFeePlan` before capacity is ever consulted, so reporting
 * `waitlist` there would invite a family onto a queue they cannot join.
 *
 * ## What this deliberately does NOT do
 *
 * It does not re-derive the window. `Offering::getIsOpenAttribute()` owns the
 * null-bound convention and the server clock, and `getClosedReasonAttribute()`
 * owns the words for it; both are read verbatim here. Two implementations of
 * "is this within its window" is how they drift.
 *
 * It is an UNLOCKED, display-time read. The authoritative check is the
 * `lockForUpdate` re-check inside `RegistrationService::register`'s transaction
 * — a page can sit open in a tab long after an offering closed. Nothing here
 * may be used to skip that.
 */
final class OfferingRegistrationState
{
    /** Accepting sign-ups right now. */
    public const STATE_OPEN = 'open';

    /**
     * Accepting sign-ups, but every seat is taken — `register()` waitlists
     * rather than refusing. A renderer switching on this says "join the
     * waitlist", not "closed".
     */
    public const STATE_WAITLIST = 'waitlist';

    /** `register()` (or the fee-plan lookup in front of it) would refuse. */
    public const STATE_CLOSED = 'closed';

    /**
     * The three reasons that come STRAIGHT FROM `Offering::closed_reason` — the
     * model's own vocabulary, repeated here only so the full set of reasons is
     * readable in one place. Never re-spelled: the accessor is what produces
     * them.
     */
    public const REASON_INACTIVE = 'inactive';

    public const REASON_NOT_YET_OPEN = 'not_yet_open';

    public const REASON_WINDOW_CLOSED = 'closed';

    /**
     * The intake form this offering validates every registration through has
     * been soft-deleted. `register()` throws `offeringClosed()` when it cannot
     * load it, so the offering is shut even though its window is wide open.
     */
    public const REASON_NO_INTAKE_FORM = 'no_intake_form';

    /**
     * No ACTIVE fee plan of a known kind. `POST /offerings/{slug}/register`
     * takes a `fee_plan_id`, so there is nothing a registrant could name — even
     * a FREE offering needs a `free` plan for the write path to accept it.
     */
    public const REASON_NO_FEE_PLAN = 'no_fee_plan';

    /** The organisation has not finished Stripe onboarding, so nothing can be charged. */
    public const REASON_ORG_CANNOT_COLLECT = 'org_cannot_collect';

    /**
     * Every reason this can report, for the surfaces that switch on it and for
     * the tests that pin the set.
     *
     * @var list<string>
     */
    public const REASONS = [
        self::REASON_INACTIVE,
        self::REASON_NOT_YET_OPEN,
        self::REASON_WINDOW_CLOSED,
        self::REASON_NO_INTAKE_FORM,
        self::REASON_NO_FEE_PLAN,
    ];

    /**
     * The verdict, resolving both facts itself.
     *
     * Two extra queries per offering, which is why the admin LIST hands the
     * facts in through decide() from its eager loads instead of calling this
     * per row.
     *
     * @return array{state:string, reason:?string}
     */
    public static function for(Offering $offering): array
    {
        return self::decide(
            $offering,
            self::intakeFormExists($offering),
            self::purchasablePlanCount($offering)
        );
    }

    /**
     * The verdict from facts the caller has already established.
     *
     * @param  bool  $hasIntakeForm  the offering's intake Form resolves (not soft-deleted)
     * @param  int  $purchasablePlanCount  ACTIVE plans of a KNOWN kind — see
     *         purchasablePlanCount() for why the kind filter is part of the count
     * @return array{state:string, reason:?string}
     */
    public static function decide(
        Offering $offering,
        bool $hasIntakeForm,
        int $purchasablePlanCount,
        ?bool $organisationCanCollect = null
    ): array {
        // Clauses 1 + 2. `closed_reason` is the model's own word for which of
        // them it is, read verbatim — never re-derived here.
        if (! $offering->is_open) {
            return [
                'state' => self::STATE_CLOSED,
                'reason' => $offering->closed_reason ?? self::REASON_INACTIVE,
            ];
        }

        // Clause 3.
        if (! $hasIntakeForm) {
            return ['state' => self::STATE_CLOSED, 'reason' => self::REASON_NO_INTAKE_FORM];
        }

        // Clause 4, and it must be tested BEFORE capacity: findFeePlan() refuses
        // before capacity is ever consulted, so "full" would be the wrong story.
        if ($purchasablePlanCount <= 0) {
            return ['state' => self::STATE_CLOSED, 'reason' => self::REASON_NO_FEE_PLAN];
        }

        // Clause 5. THE ORGANISATION MUST BE ABLE TO TAKE THE MONEY.
        //
        // This clause lived only inside RegistrationCheckoutService, and was
        // reached AFTER the registration row, the form response and the seat
        // increment were already committed — where
        // OfferingRegistrationsController::register() swallows it
        // (`catch (\Throwable) { $checkoutUrl = null; }`) and answers 200.
        // Measured on an organisation with no `stripe_account_id`: the public
        // page said `registration_state: open`, a family filled the form in, a
        // pending seat was taken, and no way to pay ever appeared.
        //
        // Null means "not asked" — the caller has no organisation handy — and is
        // treated as no objection rather than as a refusal, so a payload that
        // cannot answer the question does not start closing offerings that are
        // genuinely open. Callers that CAN answer it pass it.
        //
        // Only paid offerings care: the free-path carve-out confirms in-request
        // and never opens a Stripe leg, so an org that cannot collect can still
        // run a free program.
        if ($organisationCanCollect === false && self::hasAChargeablePlan($offering)) {
            return ['state' => self::STATE_CLOSED, 'reason' => self::REASON_ORG_CANNOT_COLLECT];
        }

        // Clause 6. Full is not closed — the sign-up is queued, not refused.
        if ($offering->isAtCapacity()) {
            return ['state' => self::STATE_WAITLIST, 'reason' => null];
        }

        return ['state' => self::STATE_OPEN, 'reason' => null];
    }

    /**
     * How many plans a registrant could actually name in a `fee_plan_id`.
     *
     * The predicate is the one `OfferingPublicPayload::feePlans()` publishes on
     * and the one `OfferingRegistrationsController::findFeePlan()` accepts on,
     * and it must stay all three:
     *
     *  - `masjid_id` explicitly, because /api/v1 runs UNBOUND and the global
     *    scope adds no filter there;
     *  - `is_active`, because `register()` refuses a deactivated plan
     *    (planInactive) — plans are deactivate-and-replace, so superseded rows
     *    live forever;
     *  - `whereIn('kind', FeePlan::KINDS)`, because a row whose kind is not
     *    recognised is withheld from the page rather than guessed at
     *    (`listTotalFor()` throws on one, and FeePlan has no degrade helper on
     *    purpose). Counting such a row here would report `open` for an offering
     *    whose only plan the page refuses to publish.
     */
    public static function purchasablePlanCount(Offering $offering): int
    {
        return FeePlan::query()
            ->where('masjid_id', $offering->masjid_id)
            ->where('offering_id', $offering->id)
            ->where('is_active', true)
            ->whereIn('kind', FeePlan::KINDS)
            ->count();
    }

    /**
     * Whether the intake form still resolves — the same masjid-filtered lookup
     * `OfferingPublicPayload::intakeForm()` makes, and soft-deleted rows do not
     * resolve, which is the whole point.
     */
    public static function intakeFormExists(Offering $offering): bool
    {
        if (! $offering->intake_form_id) {
            return false;
        }

        return Form::query()
            ->where('masjid_id', $offering->masjid_id)
            ->whereKey($offering->intake_form_id)
            ->exists();
    }

    /**
     * Whether ANY of this offering's active plans would actually raise a charge.
     *
     * A free plan has no Stripe leg (the carve-out confirms in-request), so an
     * organisation that cannot collect must not have its FREE programs closed.
     */
    private static function hasAChargeablePlan(Offering $offering): bool
    {
        return $offering->feePlans
            ->where('is_active', true)
            ->contains(fn ($plan) => (int) $plan->amount_minor > 0);
    }
}
