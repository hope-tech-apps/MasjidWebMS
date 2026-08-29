<?php

namespace App\Support;

use App\Models\FeePlan;
use App\Models\Registration;
use App\Models\RegistrationPayment;
use App\Services\Stripe\RegistrationCheckoutService;

/**
 * WHAT IS STILL OWED ON A REGISTRATION, integer minor units — THE one function
 * that answers it, for every surface that publishes `amount_due_minor`.
 *
 * ==========================================================================
 * WHY THIS IS A CLASS AND NOT A PRIVATE METHOD ON THE QUOTE
 * ==========================================================================
 *
 * Round six gave `amount_due_minor` a precise per-kind meaning — "the charges
 * that have not been made yet" — and implemented it as a private method on
 * `OfferingRegistrationsController::quote()`. THREE endpoints publish a field
 * with that name, and the other two went on publishing
 * `(int) $registration->adjusted_total_minor`, which is a different question
 * ("what the place cost"). Measured on this branch, one fixture, one instant:
 *
 *   FULL OFFERING, $150 one-time plan
 *     POST /offerings/{slug}/register   200 "This offering is full — you have
 *                                       been added to the waitlist."
 *                                       amount_due_minor 15000
 *     POST /offerings/{slug}/quote      200 amount_due_minor 0
 *                                           requires_payment false
 *
 *   She is on a LIST. She holds no seat, she has no payment leg, and
 *   `checkout` answers 422 — and the door she just came through told her she
 *   owes $150.00.
 *
 *   9 × $100.00 INSTALLMENT, 10¢ of aid granted while pending
 *     POST /registrations/{uuid}/checkout  200 amount_due_minor 89990
 *                                          …opening a Stripe subscription that
 *                                          bills 9998 nine times = 89982
 *     POST /offerings/{slug}/quote         200 amount_due_minor 89982
 *
 *   The door money actually moves through quoted 8¢ MORE than Stripe was asked
 *   for, and disagreed with the preview rendered beside it. Round six fixed
 *   exactly this arithmetic — `perChargeMinor() × N`, never `adjusted − paid`,
 *   because integer division drops the remainder in the payer's favour — and
 *   fixed it on one of the three surfaces that needed it.
 *
 * The field is one name on one public API. A renderer reads it the same way
 * whichever call produced it, so it gets one meaning and one implementation.
 * The alternative — renaming it on `register`/`checkout` to
 * `adjusted_total_minor`, which is literally what those two were publishing —
 * was considered and rejected: it is the same money question asked at three
 * moments of one flow, and answering "what it cost" under a different name at
 * two of them would leave the family's actual balance unavailable at the two
 * moments she is most likely to look. Both numbers are now published at all
 * three, so nothing is lost and nothing is renamed out from under a caller.
 *
 * ==========================================================================
 * WHAT THE FIELD MEANS, PER PLAN KIND
 * ==========================================================================
 *
 * Not "the commitment" (that is `adjusted_total_minor`, which is never
 * restated), and not "the balance", which is a word that only makes sense for
 * a plan with a finite end.
 *
 *  - free            → 0. `requires_payment` is already false; there is no
 *                      Stripe leg at all.
 *  - one_time        → the one charge, less anything the ledger says settled.
 *                      `perChargeMinor()` IS the snapshot here.
 *  - installment     → `per-charge × count − paid`. The commitment is finite
 *                      and every charge is the same size.
 *  - recurring       → THE INTERVALS STRIPE HAS TRIED AND FAILED TO COLLECT,
 *                      or one interval while the current one has not settled.
 *                      An open-ended subscription has no finite total, so it
 *                      can have no balance. See recurringOutstanding().
 *
 * Round five made the field `max(0, adjusted_total − amount_paid)`, which is
 * right for the two finite kinds and meaningless for `recurring`:
 * `listTotalFor()` snapshots a recurring plan's PER-INTERVAL amount, so the
 * "total" is one month and the difference goes to zero the instant the first
 * invoice settles — and stays there for the life of the subscription,
 * including while it is PAST DUE.
 *
 * `active` ALONE CANNOT MEAN "NOTHING IS OUTSTANDING", because
 * `checkout.session.completed` moves a subscription to `active` before any
 * invoice has settled (RegistrationPaymentService, "only ever an UPGRADE out
 * of the pre-payment states"). So the test is the LEDGER, never the status
 * alone.
 *
 * SEPARATELY, subtracting the ledger from the SNAPSHOT quoted an aid-adjusted
 * installment plan up to N−1 minor units above the sum of the charges that
 * remain, because Stripe is charged `intdiv(snapshot, N)` and the rounding is
 * dropped in the payer's favour. Measured, 9 × $100.00 with 10¢ of aid:
 * per-charge 9998, nine of them is 89982, quoted 89990 — 8¢ she would never be
 * asked for. Asking `perChargeMinor()` fixes both, and is the point of that
 * function being THE per-charge function
 * (.claude/rules/registration-billing-data.md).
 *
 * A plan whose shape `perChargeMinor()` refuses degrades to the snapshot
 * balance rather than throwing — the same degrade, for the same reason, as
 * `RegistrationService::chargeableMinor()`. These are read surfaces; a broken
 * plan row must not turn a family's price into a 500.
 */
final class RegistrationOutstanding
{
    /**
     * WHETHER STRIPE IS STILL EXPECTED, read off the registration's own money
     * state rather than re-derived from its total.
     *
     * For a preview of a NEW registration the two are the same question and
     * `adjusted > 0` is the right answer. For an EXISTING one they come apart,
     * in both directions: a settled registration still carries its
     * `adjusted_total_minor` (that is what the place cost, and the snapshot is
     * never restated), and a near-total waiver on an installment plan leaves a
     * total above zero whose per-charge rounds to nothing — the free-path
     * carve-out, `payment_status = none`, nothing to collect ever. Deriving
     * from the total told a parent she still owed $150 she had already paid,
     * and 5¢ nobody would ever ask her for.
     *
     * It is also what makes a WAITLISTED row answer 0: she holds `none`,
     * because she holds no seat.
     *
     * Stated ONCE, here, because `requires_payment` and `amount_due_minor` are
     * published side by side and a second copy of this predicate is how they
     * come to disagree.
     */
    public static function requiresPayment(Registration $registration): bool
    {
        return in_array($registration->payment_status, [
            Registration::PAYMENT_AWAITING,
            Registration::PAYMENT_ACTIVE,
            Registration::PAYMENT_PAST_DUE,
        ], true);
    }

    /**
     * @param  FeePlan|null  $feePlan  the registration's OWN plan when the caller
     *                                 already holds it; resolved by ownership
     *                                 otherwise. Never a plan the client named —
     *                                 a registration is charged from its own
     *                                 snapshot and its own plan, always.
     */
    public static function minor(Registration $registration, ?FeePlan $feePlan = null): int
    {
        // `requires_payment` already carries the settled, cancelled, waitlisted
        // and free-path carve-outs. Nothing is outstanding on any of them.
        if (! self::requiresPayment($registration)) {
            return 0;
        }

        $amountPaid = self::settledMinor($registration);
        $snapshotBalance = max(0, (int) $registration->adjusted_total_minor - $amountPaid);

        // Resolved by OWNERSHIP: `is_active` gates NEW intake, and this row is
        // already held on this plan. Masjid-filtered explicitly because /api/v1
        // never runs the tenant middleware and an unbound scope adds no filter.
        $feePlan ??= FeePlan::query()
            ->where('masjid_id', $registration->masjid_id)
            ->whereKey($registration->fee_plan_id)
            ->first();

        if (! $feePlan) {
            return $snapshotBalance;
        }

        try {
            $perCharge = RegistrationCheckoutService::perChargeMinor($registration, $feePlan);
        } catch (\Throwable $e) {
            return $snapshotBalance;
        }

        if ($feePlan->kind === FeePlan::KIND_RECURRING) {
            return self::recurringOutstanding($registration, $perCharge, $amountPaid);
        }

        $charges = $feePlan->kind === FeePlan::KIND_INSTALLMENT
            ? max(1, (int) $feePlan->installment_count)
            : 1;

        return max(0, $perCharge * $charges - $amountPaid);
    }

    /**
     * AN OPEN-ENDED SUBSCRIPTION HAS NO BALANCE, so what is outstanding is the
     * arrears: the invoices Stripe has tried to collect and could not.
     *
     * ## Round six bounded this at ONE interval, and said so
     *
     * `$amountPaid === 0 || past_due` returned exactly one interval however
     * many had failed. That limitation was declared — in the docblock ("AT MOST
     * ONE INTERVAL") and in .claude/rules/registration-billing-data.md — so it
     * was stated rather than hidden. It was still wrong by (n−1) intervals, and
     * the reviewer's note is the reason it is fixed rather than re-declared:
     * WHEN IT TRIGGERS THERE IS NO SURFACE THAT WILL EVER TELL ANYONE. A
     * declaration in a docblock is read by the next engineer; the number is
     * read by the family. Measured on the wire, $50/month, every transition
     * driven by a signed webhook:
     *
     *   after session.completed            paid 0      due 5000   failed rows 0
     *   after invoice 1 succeeded          paid 5000   due 0      failed rows 0
     *   invoice 2 failed                   paid 5000   due 5000   failed rows 1
     *   invoice 3 failed                   paid 5000   due 5000   failed rows 2   <- owes 10000
     *   invoice 4 failed                   paid 5000   due 5000   failed rows 3   <- owes 15000
     *   invoice 2 later recovers           paid 10000  due 0      failed rows 2   <- owes 10000
     *
     * The last row is the worse cell and it is not in the finding: a partial
     * recovery returns `payment_status` to `active`, so the one-interval rule
     * reported NOTHING owed while two invoices sat uncollected.
     *
     * ## Why the ledger can answer this exactly, with nothing new to maintain
     *
     * `RegistrationPaymentService::handleInvoiceFailed()` already writes ONE
     * `RegistrationPayment` row per failed invoice, keyed
     * `invoice:{stripe_invoice_id}`, carrying that invoice's own
     * `amount_due` — and `handleInvoicePaid()` UPGRADES that same row when a
     * retry succeeds ("one row per invoice, whatever it took to collect it").
     * So the set of `status = failed` rows IS the set of invoices Stripe has
     * not collected, and their sum is the arrears in the currency they were
     * billed in. This reads that; it counts nothing itself and stores nothing
     * new.
     *
     * THE BOUND THAT REMAINS, so nobody reads this as "now exact in all cases":
     * it is exact to the extent the webhook arrived. A dropped or never-sent
     * `invoice.payment_failed` is an invoice this cannot know about, and the
     * figure is then short by that invoice. The `past_due` clause below is the
     * FLOOR under that case — a payer Stripe has told us is in dunning owes at
     * least the interval, even if no ledger row landed — so the failure mode is
     * "understated by the invoices we were never told about", never "reports
     * zero while she is in arrears". Written down in
     * .claude/rules/registration-billing-data.md rather than left here alone.
     *
     * Deliberately NOT applied to `installment`: a finite plan's outstanding is
     * already `per-charge × N − paid`, which counts every charge that has not
     * settled, failed ones included. Adding arrears there would double-count
     * them.
     */
    private static function recurringOutstanding(Registration $registration, int $perCharge, int $amountPaid): int
    {
        $arrears = (int) $registration->payments()
            ->where('status', RegistrationPayment::STATUS_FAILED)
            ->sum('amount_minor');

        if ($arrears > 0) {
            return $arrears;
        }

        // No uncollected invoice on the ledger. One interval is outstanding
        // while the current one has not settled — true before the first invoice
        // lands (nothing in the ledger yet), and true again whenever Stripe has
        // told us the payer is in dunning without a row we could key.
        $unsettled = $amountPaid === 0
            || $registration->payment_status === Registration::PAYMENT_PAST_DUE;

        return $unsettled ? max(0, $perCharge) : 0;
    }

    /**
     * WHAT SHE HAS ALREADY PAID, summed from the ledger rather than inferred
     * from the status — an installment plan is `confirmed` from its first
     * charge to its last, so the status says nothing about how far through it
     * she is. Only SUCCEEDED rows count: a pending charge has not moved money
     * and a refunded one moved it back.
     *
     * Computed, NEVER PUBLISHED. A payment HISTORY is not something an
     * anonymous, uuid-bearer surface may hand out
     * (.claude/rules/registration-billing-data.md).
     */
    private static function settledMinor(Registration $registration): int
    {
        return (int) $registration->payments()
            ->where('status', RegistrationPayment::STATUS_SUCCEEDED)
            ->sum('amount_minor');
    }
}
