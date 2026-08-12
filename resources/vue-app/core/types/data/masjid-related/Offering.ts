import { Contact } from '@/core/types/data/masjid-related/Contact';

/**
 * Offerings, fee plans and registrations — the T-006 registration + billing
 * engine's admin surface (docs/t006-registration-billing-design.md).
 *
 * THE ONE RULE THIS FILE EXISTS TO ENCODE: every field named `*_minor` is an
 * INTEGER number of MINOR UNITS (cents), never a float and never a formatted
 * string (.claude/rules/stripe-payments.md). They are typed `number` because
 * that is what JSON gives us, but nothing in the SPA may add, divide, round or
 * re-parse one except the display helpers in `@/composables/useMinorUnits`,
 * which convert for RENDERING only and never send a derived number back.
 *
 * The currency those amounts are denominated in lives on the FEE PLAN and
 * nowhere else (.claude/rules/registration-billing-data.md): a plan is
 * immutable once referenced, so `registration.fee_plan.currency` is always the
 * denomination of that registration's snapshot totals. There is deliberately no
 * `currency` on `Registration` or on a payment row — if a screen cannot reach
 * the fee plan, it must not print a currency symbol at all.
 *
 * Relation keys are SNAKE_CASE. The controllers eager-load `feePlans`,
 * `feePlan`, `intakeForm` and `grantedBy`; Eloquent snake-cases relation keys
 * when it serializes, so the wire names are `fee_plans`, `fee_plan`,
 * `intake_form`, `granted_by` (same as `rent_payments` elsewhere in this SPA).
 */

// --------------------------------------------------------------- vocabularies

/** Mirrors `Offering::KINDS`. Presentation only — never an authorization check. */
export type OfferingKind = 'event' | 'program' | 'admission' | 'membership' | 'appointment';

/**
 * A literal fallback for a cold load. The SERVER's `meta.kinds` is the
 * authority; this only keeps a picker from being empty before the first
 * response lands (the same pattern the groups screens use).
 */
export const OFFERING_KINDS: OfferingKind[] = [
    'event',
    'program',
    'admission',
    'membership',
    'appointment'
];

/**
 * Mirrors `FeePlan::KINDS`.
 *
 * Unlike an offering kind, a MONEY kind never degrades: an unrecognized value
 * is a validation failure server-side, and this SPA must not guess one either.
 * Guessing `free` would waive a fee; guessing `one_time` could charge the wrong
 * shape (.claude/rules/registration-billing-data.md).
 */
export type FeePlanKind = 'free' | 'one_time' | 'installment' | 'recurring';

export const FEE_PLAN_KINDS: FeePlanKind[] = ['free', 'one_time', 'installment', 'recurring'];

/** Stripe's own subscription interval vocabulary (`FeePlan::BILLING_INTERVALS`). */
export type BillingInterval = 'day' | 'week' | 'month' | 'year';

export const BILLING_INTERVALS: BillingInterval[] = ['day', 'week', 'month', 'year'];

/**
 * The SEAT state machine (`Registration::STATUSES`).
 *
 * Note the spelling: the seat is 'cancelled' (two Ls) while the MONEY state
 * below is 'canceled' (Stripe's spelling). That divergence is deliberate and
 * pinned by `RegistrationBillingModelTest` — do not "fix" either one.
 */
export type RegistrationStatus = 'pending' | 'confirmed' | 'waitlisted' | 'cancelled';

export const REGISTRATION_STATUSES: RegistrationStatus[] = [
    'pending',
    'confirmed',
    'waitlisted',
    'cancelled'
];

/**
 * The MONEY state machine (`Registration::PAYMENT_STATUSES`), independent of the
 * seat above. A failed installment moves this to `past_due` and NEVER touches
 * the seat — nothing may eject a payer for a declined card.
 */
export type RegistrationPaymentStatus =
    | 'none'
    | 'awaiting'
    | 'active'
    | 'paid'
    | 'past_due'
    | 'canceled';

export const REGISTRATION_PAYMENT_STATUSES: RegistrationPaymentStatus[] = [
    'none',
    'awaiting',
    'active',
    'paid',
    'past_due',
    'canceled'
];

/** Mirrors `RegistrationPayment::STATUSES` — the donation ledger's vocabulary. */
export type LedgerStatus = 'pending' | 'succeeded' | 'failed' | 'refunded';

/** Mirrors `RegistrationAdjustment::KINDS`. */
export type AdjustmentKind = 'aid' | 'discount' | 'code';

export const ADJUSTMENT_KINDS: AdjustmentKind[] = ['aid', 'discount', 'code'];

// ---------------------------------------------------------------- fee plans

/**
 * One way to pay for one offering. IMMUTABLE once created: the write surface is
 * create + deactivate, and `FeePlansController::update` refuses an edit with a
 * 422 rather than silently ignoring the fields.
 *
 * `amount_minor` is the amount PER CHARGE, integer minor units:
 *  - free        → 0
 *  - one_time    → the single charge
 *  - installment → one installment (there are `installment_count` of them)
 *  - recurring   → one interval's charge, open-ended
 */
export type FeePlan = {
    id: number;
    masjid_id: number;
    offering_id: number;
    kind: FeePlanKind;
    /** INTEGER MINOR UNITS. Never divide this outside `useMinorUnits`. */
    amount_minor: number;
    /** Lowercase ISO-4217 as stored (`usd`); the display helpers upper-case it. */
    currency: string;
    billing_interval: BillingInterval | null;
    installment_count: number | null;
    label: string;
    is_active: boolean;
    created_at: string;
    updated_at: string;
};

/**
 * What the create form sends. There is no update payload on purpose — see the
 * immutability note above.
 *
 * `amount_minor` is produced by `parseMajorToMinor()` from what the admin typed
 * and is the ONLY number that goes over the wire; the major-unit text they
 * typed is never sent and never round-tripped.
 */
export type FeePlanPayload = {
    kind: FeePlanKind;
    amount_minor: number;
    currency: string;
    /** '' when the kind has no interval — the server PROHIBITS the key then. */
    billing_interval: BillingInterval | '';
    /** null when the kind has no count — the server PROHIBITS the key then. */
    installment_count: number | null;
    label: string;
    is_active: boolean;
};

// ----------------------------------------------------------------- offerings

/** The intake form an offering validates every registration through. NOT NULL. */
export type OfferingFormRef = {
    id: number;
    name: string;
    slug?: string;
    is_active?: boolean;
};

/** Where confirmed registrants are materialised as group memberships. Nullable. */
export type OfferingGroupRef = {
    id: number;
    name: string;
    slug?: string;
};

/**
 * One registerable thing: a class, a program, an admission round, a membership.
 *
 * TWO COUNTS, AND THEY ARE NOT THE SAME NUMBER:
 *  - `registration_count` is the denormalised SEAT counter. It is written only
 *    inside the locked registration transaction and the seat-release paths, and
 *    it is what capacity is checked against. A cancelled registration has given
 *    its seat back, so it is NOT in here.
 *  - `registrations_count` is `withCount('registrations')` — every registration
 *    ROW ever attached to this offering, cancelled and waitlisted included.
 * Rendering one under the other's label would misstate how full a class is.
 */
export type Offering = {
    id: number;
    masjid_id: number;
    kind: OfferingKind;
    name: string;
    /**
     * The public prose a family reads before deciding to register — served
     * verbatim to anonymous visitors by GET /api/v1/offerings/{slug}. Null is a
     * legitimate state; the public renderer omits the block.
     */
    description: string | null;
    slug: string;
    intake_form_id: number;
    group_id: number | null;
    /** null means UNLIMITED (the forms convention), not zero. */
    capacity: number | null;
    /** Seats currently held. Server-maintained and guarded; never writable here. */
    registration_count: number;
    opens_at: string | null;
    closes_at: string | null;
    is_active: boolean;
    /**
     * DERIVED server-side and APPENDED to every offering payload: is_active AND
     * inside the opens_at/closes_at window — i.e. what the public register path
     * actually enforces. Read this, never `is_active`, for anything that claims
     * an offering is accepting sign-ups: an offering whose `closes_at` has
     * passed is still `is_active: true` and refuses every registration.
     *
     * These two were declared on `FeePlan` above until 2026-08-12, where nothing
     * ever set them, while OfferingsView and OfferingDetailView had already been
     * reading `offering.is_open` / `offering.closed_reason` off this type — a
     * type error `npm run build` does not catch, because it is `vite build` with
     * no typecheck (.claude/rules/section-types.md documents the same trap for
     * the section editor map).
     */
    is_open: boolean;
    /** Why it is not open: 'inactive' | 'not_yet_open' | 'closed'; null when open. */
    closed_reason: 'inactive' | 'not_yet_open' | 'closed' | null;
    settings: Record<string, unknown> | null;
    created_at: string;
    updated_at: string;
    deleted_at?: string | null;
    /** Every registration row, cancelled included — see the note above. */
    registrations_count?: number;
    /** Eager-loaded by index/show. */
    fee_plans?: FeePlan[];
    intake_form?: OfferingFormRef | null;
    group?: OfferingGroupRef | null;
};

/**
 * One row of the page-builder's offering picker
 * (GET /api/admin/masjids/{id}/offerings/options).
 *
 * Deliberately NOT the full `Offering`: attaching a program to a page needs the
 * name and the four facts that decide whether the published block will actually
 * work, and nothing about the people registered. There are no seat numbers and
 * no roster counts here on purpose — `registration_count` is a count of people,
 * and a page builder has no business with the CRM.
 *
 * `active_fee_plan_count` is the one an admin cannot see from the name: the
 * public register endpoint takes a `fee_plan_id`, so an offering with zero
 * ACTIVE plans cannot take a single registration however open its window is.
 */
export type OfferingOption = {
    id: number;
    name: string;
    slug: string;
    kind: OfferingKind;
    is_active: boolean;
    /** is_active AND the window — never is_active alone. */
    is_open: boolean;
    closed_reason: 'inactive' | 'not_yet_open' | 'closed' | null;
    /** Every seat taken: a sign-up is waitlisted, not refused. */
    is_full: boolean;
    active_fee_plan_count: number;
};

/** What the create/edit form sends. `registration_count` is deliberately absent. */
export type OfferingPayload = {
    name: string;
    description: string;
    slug: string;
    kind: OfferingKind;
    intake_form_id: number | null;
    group_id: number | null;
    capacity: number | null;
    /** datetime-local strings ("2026-09-04T18:00"); '' means unbounded. */
    opens_at: string;
    closes_at: string;
    is_active: boolean;
};

/** The list endpoint's read filters. An empty `kind` means every kind. */
export type OfferingFilters = {
    search: string;
    kind: OfferingKind | '';
    active_only: boolean;
};

/**
 * Seat position as the SHOW endpoint states it.
 *
 * `remaining` is null when `capacity` is null, because unlimited remaining is
 * genuinely unknown rather than 0 — and it is the SERVER's subtraction, not
 * ours. Never recompute it from the other two.
 */
export type OfferingSeats = {
    capacity: number | null;
    taken: number;
    remaining: number | null;
};

/**
 * `meta` on the offering endpoints.
 *
 * `offering_label` is the tenant's own word for the concept, served from the
 * vertical's terminology pack — "Programs" for a masjid or a school, "Services"
 * for a community org. Never hardcode any of them.
 *
 * `seats` and `registrations_by_status` are served by SHOW only.
 */
export type OfferingsMeta = {
    offering_label: string;
    kinds: OfferingKind[];
    fee_plan_kinds: FeePlanKind[];
    billing_intervals: BillingInterval[];
    registration_statuses: RegistrationStatus[];
    payment_statuses: RegistrationPaymentStatus[];
    seats?: OfferingSeats;
    registrations_by_status?: Partial<Record<RegistrationStatus, number>>;
};

// ------------------------------------------------------------- registrations

/** One person a registration is FOR. Contacts-first: never a duplicated name. */
export type Registrant = {
    id: number;
    masjid_id: number;
    registration_id: number;
    contact_id: number;
    contact?: Contact | null;
    /** Present on the `?view=registrants` payload, which carries the plan too. */
    registration?: Registration | null;
};

/**
 * One auditable REDUCTION of what a registration is charged.
 *
 * `amount_minor` is an UNSIGNED magnitude — this table cannot express a
 * surcharge, by design. Granting is refused once any Stripe leg exists: aid is
 * strictly pre-checkout, because this design has no post-hoc money movement.
 */
export type RegistrationAdjustment = {
    id: number;
    masjid_id: number;
    registration_id: number;
    kind: AdjustmentKind;
    /** INTEGER MINOR UNITS, unsigned. */
    amount_minor: number;
    reason: string | null;
    granted_by_user_id: number | null;
    /** The audit trail's "who"; null once that admin's account is gone. */
    granted_by?: { id: number; name: string; email: string } | null;
    created_at: string;
    updated_at: string;
};

export type AdjustmentPayload = {
    kind: AdjustmentKind;
    amount_minor: number;
    reason: string;
};

/**
 * One Stripe charge against one registration. An installment plan accrues one
 * row per invoice.
 *
 * Written ONLY by the webhook handlers — there is no admin write path to this
 * table, and there is no "mark as paid" anywhere in this codebase. The webhook
 * is the source of truth (.claude/rules/stripe-payments.md).
 *
 * `stripe_fee_minor` / `net_minor` are null unless a balance transaction was
 * expanded on the payload. NULL MEANS UNKNOWN, NOT ZERO: registrations issue no
 * receipt, so there is deliberately no fee estimate to fall back on, and a
 * screen must render the absence rather than a 0.
 */
export type RegistrationPaymentRow = {
    id: number;
    masjid_id: number;
    registration_id: number;
    /** INTEGER MINOR UNITS, denominated by the registration's fee plan. */
    amount_minor: number;
    stripe_payment_intent_id: string | null;
    stripe_invoice_id: string | null;
    stripe_charge_id: string | null;
    stripe_balance_transaction_id: string | null;
    stripe_fee_minor: number | null;
    net_minor: number | null;
    status: LedgerStatus;
    paid_at: string | null;
    idempotency_key?: string | null;
    created_at: string;
    updated_at: string;
};

/**
 * One household's signup.
 *
 * `list_total_minor` and `adjusted_total_minor` are SNAPSHOTS taken at intake
 * from the immutable plan; `adjusted_total_minor` is the charged amount,
 * always. What that number MEANS depends on the plan kind — the full commitment
 * for an installment plan, one interval's charge for a recurring one — which is
 * why every screen labels it through `registrationChargeLabel()` instead of
 * calling it "Total".
 *
 * There is deliberately no "paid so far" field: the API does not serve one, and
 * summing the ledger in the browser would put a number on a money screen that
 * no server ever computed.
 */
export type Registration = {
    id: number;
    /** The opaque public handle used by the payer-facing endpoints. */
    uuid: string;
    masjid_id: number;
    offering_id: number;
    fee_plan_id: number;
    contact_id: number;
    form_response_id: number | null;
    /** The SEAT. */
    status: RegistrationStatus;
    /** The MONEY. Independent of the seat. */
    payment_status: RegistrationPaymentStatus;
    /** INTEGER MINOR UNITS — the plan's price before any admin adjustment. */
    list_total_minor: number;
    /** INTEGER MINOR UNITS — what Stripe was actually asked to charge. */
    adjusted_total_minor: number;
    stripe_checkout_session_id: string | null;
    stripe_subscription_id: string | null;
    stripe_subscription_schedule_id: string | null;
    checkout_expires_at: string | null;
    idempotency_key?: string | null;
    created_at: string;
    updated_at: string;
    /** The payer / guardian. */
    contact?: Contact | null;
    /** The denomination of both totals above. */
    fee_plan?: FeePlan | null;
    form_response?: { id: number; submitted_at?: string | null } | null;
    registrants?: Registrant[];
    registrants_count?: number;
    /** SHOW only. */
    adjustments?: RegistrationAdjustment[];
    /** SHOW only — the per-charge ledger. */
    payments?: RegistrationPaymentRow[];
};

/**
 * The roster endpoint's read filters. Empty strings mean "every value", which
 * is why neither is simply the status type.
 */
export type RegistrationFilters = {
    status: RegistrationStatus | '';
    payment_status: RegistrationPaymentStatus | '';
    search: string;
};

/**
 * Which of the two server-rendered views of the same list to ask for.
 *
 * `registrations` is one row per SIGNUP (the money view); `registrants` is one
 * row per PERSON — a guardian who registered three children is one registration
 * and three registrants, which is what a teacher printing a class list wants.
 */
export type RegistrationView = 'registrations' | 'registrants';
