import {
    AdjustmentKind,
    BillingInterval,
    FeePlan,
    FeePlanKind,
    LedgerStatus,
    OfferingKind,
    OfferingRegistrationState,
    OfferingRegistrationStateReason,
    Registration,
    RegistrationPaymentStatus,
    RegistrationStatus
} from '@/core/types/data/masjid-related/Offering';

/**
 * How offerings, fee plans and registrations are SPELLED — shared by the list,
 * the detail screen, both tabs and the registration page so five files cannot
 * drift apart on what "past_due" means.
 *
 * The vocabularies themselves are never defined here: they come from the
 * server's `meta`. This decides only how a value that has arrived is rendered,
 * and every lookup degrades to a humanized key, so a kind added in PHP tomorrow
 * reads as words today instead of blanking a cell.
 *
 * NO MONEY ARITHMETIC LIVES HERE. `describePlanTerms` and
 * `registrationChargeLabel` return the WORDS that go beside a server-supplied
 * amount — "× 9 payments, billed monthly", "Total across 9 payments" — and
 * never a number. The one place a figure is converted for display is
 * `@/composables/useMinorUnits`.
 */

const OFFERING_KIND_LABELS: Partial<Record<OfferingKind, string>> = {
    event: 'Event',
    program: 'Program',
    admission: 'Admission',
    membership: 'Membership',
    appointment: 'Appointment'
};

const FEE_PLAN_KIND_LABELS: Partial<Record<FeePlanKind, string>> = {
    free: 'Free',
    one_time: 'One-time',
    installment: 'Installments',
    recurring: 'Recurring'
};

/** Adverbs, because they read beside an amount: "$50.00, billed monthly". */
const INTERVAL_ADVERBS: Partial<Record<BillingInterval, string>> = {
    day: 'daily',
    week: 'weekly',
    month: 'monthly',
    year: 'yearly'
};

/** Nouns, for "per month". */
const INTERVAL_NOUNS: Partial<Record<BillingInterval, string>> = {
    day: 'day',
    week: 'week',
    month: 'month',
    year: 'year'
};

const REGISTRATION_STATUS_LABELS: Partial<Record<RegistrationStatus, string>> = {
    pending: 'Pending',
    confirmed: 'Confirmed',
    waitlisted: 'Waitlisted',
    cancelled: 'Cancelled'
};

/**
 * Bootstrap subtle badges for the SEAT. `waitlisted` draws the eye because it is
 * the one state an admin can act on (promote); `pending` is amber because the
 * seat is held but not yet paid for and has a deadline on it.
 */
const REGISTRATION_STATUS_BADGES: Partial<Record<RegistrationStatus, string>> = {
    pending: 'bg-warning-subtle text-warning-emphasis',
    confirmed: 'bg-success-subtle text-success',
    waitlisted: 'bg-info-subtle text-info-emphasis',
    cancelled: 'bg-secondary-subtle text-secondary'
};

const REGISTRATION_STATUS_ICONS: Partial<Record<RegistrationStatus, string>> = {
    pending: 'bi-hourglass-split',
    confirmed: 'bi-check2-circle',
    waitlisted: 'bi-list-ol',
    cancelled: 'bi-x-circle'
};

/** What each SEAT state means, in the operator's terms. */
const REGISTRATION_STATUS_HINTS: Partial<Record<RegistrationStatus, string>> = {
    pending: 'Holding a seat while checkout is open. Released automatically if the checkout window expires.',
    confirmed: 'Holding a seat. Registrants have been written onto the roster.',
    waitlisted: 'No seat — this signup was made after the offering filled. Promote to give it one.',
    cancelled: 'The seat has been given back.'
};

const PAYMENT_STATUS_LABELS: Partial<Record<RegistrationPaymentStatus, string>> = {
    none: 'No charge',
    awaiting: 'Awaiting payment',
    active: 'Billing active',
    paid: 'Paid',
    past_due: 'Past due',
    canceled: 'Billing canceled'
};

const PAYMENT_STATUS_BADGES: Partial<Record<RegistrationPaymentStatus, string>> = {
    none: 'bg-light text-dark border',
    awaiting: 'bg-warning-subtle text-warning-emphasis',
    active: 'bg-primary-subtle text-primary-emphasis',
    paid: 'bg-success-subtle text-success',
    past_due: 'bg-danger-subtle text-danger',
    canceled: 'bg-secondary-subtle text-secondary'
};

/**
 * What each MONEY state means. Two of these are load-bearing beyond decoration:
 *
 *  - `past_due` says out loud that Stripe owns the retry cadence and that a
 *    failed invoice never removes anybody, so nobody goes looking for a dunning
 *    setting this codebase does not have;
 *  - `canceled` says settled charges are not restated, which is why there is no
 *    refund button anywhere on these screens.
 */
const PAYMENT_STATUS_HINTS: Partial<Record<RegistrationPaymentStatus, string>> = {
    none: 'No Stripe leg — this registration costs nothing to hold.',
    awaiting: 'A hosted Checkout session is open. The seat advances only when Stripe confirms it.',
    active: 'A live subscription. Each installment is charged by Stripe on its own schedule.',
    paid: 'Settled in full.',
    past_due: 'An invoice failed. Stripe retries on its own schedule, and a failed payment never removes the seat — un-enrolling is an explicit decision, taken here.',
    canceled: 'Future billing has stopped. Charges that already settled are not restated: refunds are made in your own Stripe dashboard.'
};

const LEDGER_STATUS_LABELS: Partial<Record<LedgerStatus, string>> = {
    pending: 'Pending',
    succeeded: 'Succeeded',
    failed: 'Failed',
    refunded: 'Refunded'
};

const LEDGER_STATUS_BADGES: Partial<Record<LedgerStatus, string>> = {
    pending: 'bg-warning-subtle text-warning-emphasis',
    succeeded: 'bg-success-subtle text-success',
    failed: 'bg-danger-subtle text-danger',
    refunded: 'bg-secondary-subtle text-secondary'
};

const ADJUSTMENT_KIND_LABELS: Partial<Record<AdjustmentKind, string>> = {
    aid: 'Financial aid',
    discount: 'Discount',
    code: 'Code'
};

/**
 * CAN A FAMILY REGISTER — the one status vocabulary, so the offerings list, the
 * offering detail header and the page-builder's picker cannot say three
 * different things about one program.
 *
 * They did. Until 2026-08-12 the list and the detail header rendered `is_open`
 * alone, which is the WINDOW and nothing else, so a program whose sign-up form
 * had been deleted, or whose fee plans were all deactivated, showed a green
 * "Open" while refusing every registration. The section editor was the only
 * surface that warned, and it is the one a family never sees.
 *
 * The verdict is NOT computed here. The server decides it in
 * `App\Support\OfferingRegistrationState`, from the clauses the write path
 * actually refuses on, and serves it as `registration_state` +
 * `registration_state_reason`. This file only spells it.
 *
 * The three MISCONFIGURATION reasons are deliberately styled `danger` rather
 * than the neutral grey the window states get. "The window closed in March" is
 * an organisation's own decision; "there is no fee plan", "the sign-up form was
 * deleted" and "online payments were never finished" are programs nobody
 * remembered to finish, and the difference is exactly what an admin needs to see
 * at a glance.
 *
 * EVERY MEMBER OF `OfferingRegistrationState::REASONS` NEEDS A KEY IN ALL THREE
 * MAPS. `org_cannot_collect` had none: the server produced it, the admin
 * payloads carried it, and every map here fell through to the bare `closed`
 * entry — so the one reason a registrar can fix in two clicks rendered as a
 * neutral grey "Closed — Not accepting registrations.", indistinguishable from a
 * program they had switched off on purpose, with no warning icon because
 * `registrationStateIsFault` did not list it either.
 */
const REGISTRATION_STATE_LABELS: Record<string, string> = {
    open: 'Open',
    waitlist: 'Full — waitlisting',
    'closed:inactive': 'Switched off',
    'closed:not_yet_open': 'Scheduled',
    'closed:closed': 'Window closed',
    'closed:no_intake_form': 'No sign-up form',
    'closed:no_fee_plan': 'No fee plan',
    'closed:org_cannot_collect': 'Payments not set up',
    closed: 'Closed'
};

const REGISTRATION_STATE_BADGES: Record<string, string> = {
    open: 'bg-success-subtle text-success',
    waitlist: 'bg-warning-subtle text-warning-emphasis',
    'closed:inactive': 'bg-secondary-subtle text-secondary',
    'closed:not_yet_open': 'bg-info-subtle text-info',
    'closed:closed': 'bg-secondary-subtle text-secondary',
    // Not "closed on purpose" — broken. Coloured like the fault it is.
    'closed:no_intake_form': 'bg-danger-subtle text-danger',
    'closed:no_fee_plan': 'bg-danger-subtle text-danger',
    'closed:org_cannot_collect': 'bg-danger-subtle text-danger',
    closed: 'bg-secondary-subtle text-secondary'
};

/** What it means and, where there is one, what to do about it. */
const REGISTRATION_STATE_HINTS: Record<string, string> = {
    open: '',
    waitlist: 'Every seat is taken. Sign-ups are still accepted — they join the waitlist rather than being refused.',
    'closed:inactive': 'Switched off. It is not published anywhere and no registration is accepted.',
    'closed:not_yet_open': 'Registration has not opened yet. It will start accepting sign-ups on its opening date.',
    'closed:closed': 'The registration window has passed. Change the closing date to accept sign-ups again.',
    'closed:no_intake_form': 'Its sign-up form has been deleted, so every registration is refused. Point it at a form that still exists.',
    'closed:no_fee_plan': 'It has no active fee plan, so a registration has nothing to sign up FOR — even a free program needs a free plan. Add one under its Fee Plans tab.',
    'closed:org_cannot_collect': 'Every one of its fee plans charges money, and online payments are not set up for this organisation yet, so a card payment has nowhere to land. Finish Stripe onboarding from the Donations screen — or add a free plan, which registers with no payment at all.',
    closed: 'Not accepting registrations.'
};

/**
 * `open` / `waitlist` / `closed:<reason>` — the key the three maps above are
 * read with. A reason is only ever meaningful on `closed`, and an unrecognised
 * one falls back to the bare state rather than blanking the badge.
 */
function registrationStateKey(
    state: OfferingRegistrationState | string | null | undefined,
    reason?: OfferingRegistrationStateReason | string | null
): string {
    const value = String(state || 'closed');

    if (value !== 'closed' || !reason) {
        return value;
    }

    const key = `closed:${reason}`;

    return key in REGISTRATION_STATE_LABELS ? key : value;
}

/** "one_time" -> "One time"; an unknown key still reads as words, never blank. */
function humanize(value: string): string {
    const words = String(value || '').replace(/_/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

export function useOfferingDisplay() {

    const offeringKindLabel = (kind: OfferingKind | string): string =>
        OFFERING_KIND_LABELS[kind as OfferingKind] || humanize(String(kind || 'event'));

    const feePlanKindLabel = (kind: FeePlanKind | string): string =>
        FEE_PLAN_KIND_LABELS[kind as FeePlanKind] || humanize(String(kind || ''));

    const intervalAdverb = (interval: BillingInterval | string | null | undefined): string =>
        INTERVAL_ADVERBS[interval as BillingInterval] || (interval ? `every ${humanize(String(interval)).toLowerCase()}` : '');

    const intervalNoun = (interval: BillingInterval | string | null | undefined): string =>
        INTERVAL_NOUNS[interval as BillingInterval] || String(interval || '');

    const registrationStatusLabel = (status: RegistrationStatus | string): string =>
        REGISTRATION_STATUS_LABELS[status as RegistrationStatus] || humanize(String(status || ''));

    const registrationStatusBadge = (status: RegistrationStatus | string): string =>
        REGISTRATION_STATUS_BADGES[status as RegistrationStatus] || 'bg-light text-dark border';

    const registrationStatusIcon = (status: RegistrationStatus | string): string =>
        REGISTRATION_STATUS_ICONS[status as RegistrationStatus] || 'bi-circle';

    const registrationStatusHint = (status: RegistrationStatus | string): string =>
        REGISTRATION_STATUS_HINTS[status as RegistrationStatus] || '';

    const paymentStatusLabel = (status: RegistrationPaymentStatus | string): string =>
        PAYMENT_STATUS_LABELS[status as RegistrationPaymentStatus] || humanize(String(status || ''));

    const paymentStatusBadge = (status: RegistrationPaymentStatus | string): string =>
        PAYMENT_STATUS_BADGES[status as RegistrationPaymentStatus] || 'bg-light text-dark border';

    const paymentStatusHint = (status: RegistrationPaymentStatus | string): string =>
        PAYMENT_STATUS_HINTS[status as RegistrationPaymentStatus] || '';

    const ledgerStatusLabel = (status: LedgerStatus | string): string =>
        LEDGER_STATUS_LABELS[status as LedgerStatus] || humanize(String(status || ''));

    const ledgerStatusBadge = (status: LedgerStatus | string): string =>
        LEDGER_STATUS_BADGES[status as LedgerStatus] || 'bg-light text-dark border';

    const adjustmentKindLabel = (kind: AdjustmentKind | string): string =>
        ADJUSTMENT_KIND_LABELS[kind as AdjustmentKind] || humanize(String(kind || ''));

    // ------------------------------------------------ can a family register?

    const registrationStateLabel = (
        state: OfferingRegistrationState | string | null | undefined,
        reason?: OfferingRegistrationStateReason | string | null
    ): string => REGISTRATION_STATE_LABELS[registrationStateKey(state, reason)] || 'Closed';

    const registrationStateBadge = (
        state: OfferingRegistrationState | string | null | undefined,
        reason?: OfferingRegistrationStateReason | string | null
    ): string => REGISTRATION_STATE_BADGES[registrationStateKey(state, reason)] || 'bg-secondary-subtle text-secondary';

    const registrationStateHint = (
        state: OfferingRegistrationState | string | null | undefined,
        reason?: OfferingRegistrationStateReason | string | null
    ): string => REGISTRATION_STATE_HINTS[registrationStateKey(state, reason)] ?? '';

    /**
     * True when registration is shut for a reason that is a MISCONFIGURATION
     * rather than a decision — no sign-up form, no fee plan, or online payments
     * never finished. Surfaces use it to decide whether to shout; the words
     * themselves come from the maps above.
     *
     * The window reasons are deliberately absent: `inactive`, `not_yet_open` and
     * `closed` are all things an organisation chose, and shouting about a
     * program that closed on schedule is noise that teaches admins to ignore the
     * icon.
     */
    const registrationStateIsFault = (
        state: OfferingRegistrationState | string | null | undefined,
        reason?: OfferingRegistrationStateReason | string | null
    ): boolean => [
        'closed:no_intake_form',
        'closed:no_fee_plan',
        'closed:org_cannot_collect'
    ].includes(registrationStateKey(state, reason));

    /**
     * The words that go AFTER a fee plan's own `amount_minor` — "once",
     * "× 9 payments, billed monthly", "per month, ongoing".
     *
     * Deliberately no total: `amount_minor × installment_count` is a number the
     * server computes at intake (`RegistrationService::listTotalFor`) and does
     * not serve here, so this screen shows the two figures it was given side by
     * side rather than inventing a third that could disagree with the snapshot
     * a registration was actually quoted.
     */
    const describePlanTerms = (plan: FeePlan | null | undefined): string => {
        if (!plan) return '';

        if (plan.kind === 'free') return 'no charge';
        if (plan.kind === 'one_time') return 'once';

        if (plan.kind === 'installment') {
            const count = plan.installment_count;
            const payments = count ? `× ${count} payments` : '× installments';
            const cadence = plan.billing_interval ? `, billed ${intervalAdverb(plan.billing_interval)}` : '';

            return `${payments}${cadence}`;
        }

        if (plan.kind === 'recurring') {
            return plan.billing_interval
                ? `per ${intervalNoun(plan.billing_interval)}, ongoing`
                : 'ongoing';
        }

        return '';
    };

    /**
     * What a registration's `adjusted_total_minor` actually MEANS, which depends
     * entirely on its plan's kind:
     *
     *  - one_time    → the single charge;
     *  - installment → the FULL commitment, split across N Stripe charges;
     *  - recurring   → ONE interval's charge; an open-ended plan has no total;
     *  - free        → nothing.
     *
     * Calling all four "Total" is the mistake this function exists to prevent —
     * a recurring registration would read as though the family owed one month's
     * fee and were done.
     */
    const registrationChargeLabel = (registration: Registration): string => {
        const plan = registration.fee_plan;

        if (!plan) return 'Charged amount';
        if (plan.kind === 'free') return 'No charge';
        if (plan.kind === 'one_time') return 'Charged once';

        if (plan.kind === 'installment') {
            return plan.installment_count
                ? `Total across ${plan.installment_count} payments`
                : 'Total across the installment plan';
        }

        if (plan.kind === 'recurring') {
            return plan.billing_interval
                ? `Charged per ${intervalNoun(plan.billing_interval)}`
                : 'Charged each period';
        }

        return 'Charged amount';
    };

    /** Date + time in the reader's own locale and zone; the raw value if it will not parse. */
    const formatDateTime = (iso: string | null | undefined): string => {
        if (!iso) return '—';
        const date = new Date(iso);

        return isNaN(date.getTime())
            ? iso
            : date.toLocaleString(undefined, {
                year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
            });
    };

    /** A payer's display name, falling back to their email and then to the id. */
    const contactName = (contact: { first_name?: string; last_name?: string; email?: string | null } | null | undefined): string => {
        if (!contact) return 'Unknown';
        const name = `${contact.first_name || ''} ${contact.last_name || ''}`.trim();

        return name || contact.email || 'Unknown';
    };

    return {
        offeringKindLabel,
        feePlanKindLabel,
        intervalAdverb,
        intervalNoun,
        registrationStatusLabel,
        registrationStatusBadge,
        registrationStatusIcon,
        registrationStatusHint,
        paymentStatusLabel,
        paymentStatusBadge,
        paymentStatusHint,
        ledgerStatusLabel,
        ledgerStatusBadge,
        adjustmentKindLabel,
        registrationStateLabel,
        registrationStateBadge,
        registrationStateHint,
        registrationStateIsFault,
        describePlanTerms,
        registrationChargeLabel,
        formatDateTime,
        contactName
    };
}
