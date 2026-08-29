<?php

namespace App\Support;

use App\Models\FeePlan;
use App\Models\Form;
use App\Models\Masjid;
use App\Models\Offering;
use App\Services\Registrations\RegistrationService;
use Illuminate\Support\Collection;

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
 *   4. no PURCHASABLE fee plan                  -> 404 "This fee plan is not
 *      available." from findFeePlan(), because `register` takes a
 *      `fee_plan_id` and there is no plan to name       [no_fee_plan|org_cannot_collect]
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
 * ## Clause 4 is PER PLAN. This class only aggregates it.
 *
 * `isPurchasable()` below is the whole of "could a registrant name this in
 * `fee_plan_id` and be charged the right thing" — one function, four callers
 * (this class, `OfferingPublicPayload::feePlans`,
 * `OfferingRegistrationsController::findFeePlan`, and the admin list). The
 * OFFERING-level verdict is nothing more than "how many of its plans survive
 * that predicate", which is why there is no separate offering-level rule for
 * any of the facts inside it.
 *
 * That shape was got wrong twice and both defects had the same root: a
 * per-PLAN fact answered once for the whole offering.
 *
 *  - `org_cannot_collect` shipped as a sixth offering-level clause, using a
 *    private `hasAChargeablePlan()` that was the ONLY plan predicate in this
 *    file omitting `whereIn('kind', FeePlan::KINDS)`. Measured: a masjid
 *    running "Weekend school $450" beside "Weekend school — fee waived" whose
 *    Stripe onboarding had lapsed published `closed / org_cannot_collect` for
 *    the WHOLE program — including to the scholarship families whose free
 *    registration the write path went on confirming 200 in the same breath.
 *    The clause is now a term of `isPurchasable()`, so the $450 tier is
 *    withheld and the fee-waived tier stays open, which is what both surfaces
 *    already did on their own.
 *  - `billing_interval` and `installment_count` were enforced only where money
 *    moves. Measured (`kind='recurring'`, `billing_interval` NULL at DB level):
 *    page `open`, plan published at 2500, quote 200, register 200 "your place
 *    is held", checkout 422 "This plan bills on a schedule but names no valid
 *    billing interval." — a held seat and no way to pay for it, from a row
 *    `StoreFeePlanRequest` blocks at create time exactly as it blocks the
 *    `sliding_scale` kind that got the same treatment.
 *
 * A plan this predicate refuses is WITHHELD from the page and is the same 404
 * as a plan that never existed. Withholding and refusing are one fact.
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
     * No PURCHASABLE fee plan, and none was withheld for want of Stripe
     * onboarding. `POST /offerings/{slug}/register` takes a `fee_plan_id`, so
     * there is nothing a registrant could name — even a FREE offering needs a
     * `free` plan for the write path to accept it.
     */
    public const REASON_NO_FEE_PLAN = 'no_fee_plan';

    /**
     * Every plan this offering has WOULD be purchasable but raises a charge,
     * and the organisation has not finished Stripe onboarding, so there is
     * nowhere for the money to land.
     *
     * Distinct from `no_fee_plan` because the two need different actions from
     * different people: `no_fee_plan` means somebody must add a plan, this one
     * means somebody must finish Connect onboarding — and the plans are already
     * there, so telling an admin they have none would send them looking in the
     * wrong place.
     *
     * It is reported ONLY when nothing at all survives `isPurchasable()`. An
     * offering that also has a free tier keeps reporting `open`: the free path
     * confirms in-request and opens no Stripe leg, so an organisation that
     * cannot collect can still run its free programs and its scholarship
     * places.
     */
    public const REASON_ORG_CANNOT_COLLECT = 'org_cannot_collect';

    /**
     * Every reason this can report, for the surfaces that switch on it and for
     * the tests that pin the set.
     *
     * CLOSED, and pinned closed by
     * `OfferingRegistrationStateTest::the_reason_vocabulary_is_closed_and_covers_every_write_path_refusal`,
     * which produces every member from a real fixture. `org_cannot_collect` was
     * missing from this list while `decide()` produced it — a renderer that
     * switches on a value the declared vocabulary says cannot occur falls
     * through to its default, which is how the one reason fixable in two clicks
     * came to render as a deliberate grey "Closed".
     *
     * The mirrors that must be updated with it:
     * `resources/vue-app/core/types/data/masjid-related/Offering.ts`
     * (`OfferingRegistrationStateReason`) and the three maps plus
     * `registrationStateIsFault` in
     * `resources/vue-app/composables/useOfferingDisplay.ts`.
     *
     * @var list<string>
     */
    public const REASONS = [
        self::REASON_INACTIVE,
        self::REASON_NOT_YET_OPEN,
        self::REASON_WINDOW_CLOSED,
        self::REASON_NO_INTAKE_FORM,
        self::REASON_NO_FEE_PLAN,
        self::REASON_ORG_CANNOT_COLLECT,
    ];

    /**
     * The verdict, resolving every fact itself — the form, the plans, and
     * whether the organisation can take money.
     *
     * Three extra queries per offering, which is why the admin LIST hands the
     * facts in through decide() from its eager loads instead of calling this
     * per row.
     *
     * THE ORGANISATION IS RESOLVED HERE, not left to the caller. The public
     * quote endpoint calls this while `OfferingPublicPayload` called `decide()`
     * WITH the organisation, under a docblock claiming the two were "the SAME
     * call … so the page and the quote cannot disagree". They were not and they
     * did: measured on an org with `stripe_account_id = null`, the page said
     * `closed / org_cannot_collect` while the quote answered 200 with
     * `amount_due_minor: 15000`. One call now means one set of facts.
     *
     * @return array{state:string, reason:?string}
     */
    public static function for(Offering $offering): array
    {
        return self::decide(
            $offering,
            self::intakeFormExists($offering),
            self::planRows($offering),
            Masjid::find($offering->masjid_id)?->canAcceptDonations()
        );
    }

    /**
     * The verdict from facts the caller has already established.
     *
     * @param  bool  $hasIntakeForm  the offering's intake Form resolves (not soft-deleted)
     * @param  iterable<int,FeePlan>  $plans  this offering's fee plans, RAW and
     *         unfiltered. The caller does not pre-filter and does not pass a
     *         count: `isPurchasable()` is the predicate and it is applied here,
     *         so a caller cannot apply a slightly different one and hand in a
     *         number this class has no way to question. It used to take an int,
     *         and the two defects above are what a pre-computed count buys.
     * @param  ?bool  $organisationCanCollect  Masjid::canAcceptDonations(), or
     *         null for "not asked" — see isPurchasable().
     * @return array{state:string, reason:?string}
     */
    public static function decide(
        Offering $offering,
        bool $hasIntakeForm,
        iterable $plans,
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

        // Materialised once: the block below may read the plans TWICE, and a
        // caller is entitled to hand in any iterable — a generator would be
        // exhausted by the first pass and report "no plans" on the second.
        $rows = is_array($plans) ? $plans : iterator_to_array($plans);

        // Clause 4, and it must be tested BEFORE capacity: findFeePlan() refuses
        // before capacity is ever consulted, so "full" would be the wrong story.
        $purchasable = self::purchasablePlans($rows, $organisationCanCollect);

        if ($purchasable === []) {
            // WHICH kind of "nothing to buy" this is. Asking the predicate a
            // second time with the organisation question withheld is what
            // separates "there are no plans" from "there are plans, and every
            // one of them needs money this organisation cannot yet take" —
            // two different misconfigurations needing two different people to
            // act, so they get two different words rather than one vague one.
            $blockedByCollection = $organisationCanCollect === false
                && self::purchasablePlans($rows, null) !== [];

            return [
                'state' => self::STATE_CLOSED,
                'reason' => $blockedByCollection
                    ? self::REASON_ORG_CANNOT_COLLECT
                    : self::REASON_NO_FEE_PLAN,
            ];
        }

        // Clause 5. Full is not closed — the sign-up is queued, not refused.
        if ($offering->isAtCapacity()) {
            return ['state' => self::STATE_WAITLIST, 'reason' => null];
        }

        return ['state' => self::STATE_OPEN, 'reason' => null];
    }

    /**
     * THE PREDICATE: could a registrant name this plan in `fee_plan_id`, and
     * would the money that follows actually move?
     *
     * ONE function, four callers — `decide()` above,
     * `OfferingPublicPayload::feePlans()` (what the page publishes),
     * `OfferingRegistrationsController::findFeePlan()` (what quote and register
     * accept), and `AdminDashboard\OfferingsController::registrationState()`.
     * Every clause below was, at some point, enforced in some of those places
     * and not others, and every time the result was the same shape: a page that
     * advertises a price the write path refuses, or a write path that takes a
     * seat nothing can pay for.
     *
     * The clauses, and what each one costs when it is missing:
     *
     *  - `is_active`. `register()` refuses a deactivated plan (planInactive) and
     *    plans are deactivate-and-replace, so superseded rows live forever.
     *    Publishing one advertises a price nobody can buy.
     *  - `kind` in `FeePlan::KINDS`. Money never degrades: `listTotalFor()`
     *    throws on an unknown kind and FeePlan has no degrade helper on purpose.
     *    Missing from `findFeePlan()` until 2026-08-12 — `POST /quote` naming a
     *    `sliding_scale` id returned that internal invariant message to an
     *    anonymous caller, confirming a row the page had just withheld.
     *  - `billing_interval` in `FeePlan::BILLING_INTERVALS` for the two
     *    subscription kinds. `RegistrationCheckoutService` refuses to guess a
     *    cadence nobody agreed to; without this clause the refusal lands after
     *    the seat is taken.
     *  - `installment_count >= 1` for installments. `perChargeMinor()` divides
     *    by it; there is no per-payment amount to charge without it. Note >= 1
     *    and NOT the `min:2` `StoreFeePlanRequest` enforces at create time — a
     *    predicate stricter than the write it gates is its own defect, and a
     *    one-payment plan that already exists checks out perfectly well.
     *  - A PAID KIND CHARGES MORE THAN ZERO. `StoreFeePlanRequest` already
     *    states this clause in words — "A paid plan must charge more than zero;
     *    a $0 charge is the free plan instead." — and enforced it at create time
     *    while nothing enforced it on a row that got in another way. Same
     *    reachability class as the three clauses above it (an import, a seeder,
     *    a manual edit), and the same shape of harm, one step worse: measured
     *    with `one_time`, `recurring` and `installment` all at `amount_minor 0`,
     *    the page read `open` and published "$0.00", quote answered 200, and
     *    `register` CONFIRMED THE SEAT FREE — `listTotalFor()` returns 0 for
     *    them, so intake took the free-path carve-out and gave the place away.
     *    A paid tier misconfigured to nothing now reads exactly like a plan that
     *    was never created.
     *
     *    `free` is deliberately EXEMPT, which is why this is spelled against the
     *    kind rather than as `listTotalFor($plan) > 0`. A `free` plan carrying a
     *    stale non-zero `amount_minor` is a real, chargeless registration path
     *    and stays purchasable; `OfferingPublicPayload` publishes its per-charge
     *    as 0 rather than the stale column, so no price travels that is not
     *    charged.
     *  - the organisation can collect, for plans that RAISE A CHARGE. A direct
     *    charge has nowhere to land without a Connect account
     *    (`orgCannotCollectPayments`), and until this clause was per-plan the
     *    check sat at the END of `RegistrationCheckoutService::checkout` — after
     *    the registration row, the form response and the seat increment were
     *    committed, and where `OfferingRegistrationsController::register()`
     *    swallows it (`catch (\Throwable) { $checkoutUrl = null; }`) and answers
     *    200 "Your place is held while you complete payment." with
     *    `checkout_url: null`.
     *
     * "Raises a charge" is `RegistrationService::listTotalFor($plan) > 0` — the
     * same function that snapshots `list_total_minor` at intake and the same
     * one the payload's `requires_payment` is computed from, so "does this need
     * Stripe" has one implementation rather than an `amount_minor > 0` lookalike
     * beside it. They differ for exactly one row shape: a `free` plan carrying a
     * stale non-zero amount, which the free-path carve-out confirms in-request
     * and never charges. The lookalike would have closed it.
     *
     * @param  ?bool  $organisationCanCollect  null means NOT ASKED — the caller
     *         has no organisation handy — and is treated as no objection rather
     *         than as a refusal, so a caller that cannot answer the question
     *         does not start withholding plans that are genuinely on sale.
     *         `decide()` uses that reading deliberately, to tell the two
     *         nothing-to-buy reasons apart.
     */
    public static function isPurchasable(FeePlan $plan, ?bool $organisationCanCollect = null): bool
    {
        if (! $plan->is_active) {
            return false;
        }

        if (! in_array($plan->kind, FeePlan::KINDS, true)) {
            return false;
        }

        if ($plan->isSubscriptionBased()
            && ! in_array((string) $plan->billing_interval, FeePlan::BILLING_INTERVALS, true)) {
            return false;
        }

        if ($plan->kind === FeePlan::KIND_INSTALLMENT && (int) $plan->installment_count < 1) {
            return false;
        }

        // A PAID KIND MUST CHARGE MORE THAN ZERO. `free` is exempt: it is the
        // declared no-Stripe path and a stale amount on one is never charged.
        if ($plan->kind !== FeePlan::KIND_FREE && (int) $plan->amount_minor <= 0) {
            return false;
        }

        if ($organisationCanCollect === false
            && app(RegistrationService::class)->listTotalFor($plan) > 0) {
            return false;
        }

        return true;
    }

    /**
     * The subset of `$plans` that survives isPurchasable(), order preserved.
     *
     * @param  iterable<int,FeePlan>  $plans
     * @return list<FeePlan>
     */
    public static function purchasablePlans(iterable $plans, ?bool $organisationCanCollect = null): array
    {
        $kept = [];

        foreach ($plans as $plan) {
            if (self::isPurchasable($plan, $organisationCanCollect)) {
                $kept[] = $plan;
            }
        }

        return $kept;
    }

    /**
     * This offering's fee plans, oldest first — the deactivate-and-replace chain
     * in the order it was created.
     *
     * UNFILTERED beyond tenancy and ownership: the purchasability judgement is
     * `isPurchasable()`'s alone, and a query that pre-filtered would be a second
     * copy of it in SQL. `masjid_id` is named explicitly because /api/v1 runs
     * UNBOUND and the BelongsToMasjid global scope adds no filter there.
     *
     * @return Collection<int,FeePlan>
     */
    public static function planRows(Offering $offering): Collection
    {
        return FeePlan::query()
            ->where('masjid_id', $offering->masjid_id)
            ->where('offering_id', $offering->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * How many plans a registrant could actually name in a `fee_plan_id`.
     * Resolves the plans and the organisation itself; `decide()` is the form to
     * use when the caller already has them.
     */
    public static function purchasablePlanCount(Offering $offering): int
    {
        return count(self::purchasablePlans(
            self::planRows($offering),
            Masjid::find($offering->masjid_id)?->canAcceptDonations()
        ));
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
}
