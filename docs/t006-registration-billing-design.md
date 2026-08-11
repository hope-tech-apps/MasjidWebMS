# T-006 Registration + Billing Engine — Ratified Design

_Produced 2026-08-11 by a 3-approach judge panel (scouts + adversarial critics),
supervisor rubric synthesis, lead-reviewer verification._

## Lead-reviewer verdict

RATIFIED

(1) Rubric honest: weighted arithmetic verified (8.05 / 6.25 / 6.60 — all correct). Relative scores are defensible against the critiques: offering-engine's risk edge (additive tables vs. payable-forms mutating live `forms`/webhook dispatch inside the locked path, vs. stripe-native's org-side drift) matches what the critics actually found. The Connect-secret gap hit all three equally, so it doesn't distort ranking, and the supervisor credited stripe-native's critic for it rather than hiding the winner's shared blind spot.

(2) stripe-payments.md compliance: Connect Standard + direct charges, hosted-only/SAQ-A, integer minor units, pending-before-redirect with idempotency keys, webhook dedup/order-independence, fail-closed secrets (platform + new Connect secret), seams stubbed, explicit unbound-path stamping per the Tenancy note, gap-free donation serials untouched (registration receipts deferred to a separate sequence). `application_fee_percent` for subscriptions is not a deviation — the amount param doesn't exist there, zero-omission is restated, and T-006 is itself the dedicated task the rule requires. The free-path synchronous confirmation is a declared, bounded carve-out for a path with no Stripe leg; donation advancement stays webhook-only, pinned by the regression suite.

(3) Coverage: every finding from all three critiques traced to a designed-in mechanism, a slice/test, or the deferred list with a stated reason — none silently dropped.

---

## Scoring

| Criterion (weight) | offering-engine | payable-forms | stripe-native |
|---|---|---|---|
| Fulfills requirements (40%) | **9** — real lifecycle, waitlist, per-payment ledger, adjustments | 7 — waitlists/slots/transfers strain the form noun; roster = responses is weak | 8 — no local payment ledger; aid via hidden prices is leaky |
| Implementation risk, lower=better (20%) | **7** — purely additive tables; risk is clone divergence, contained | 5 — webhook dispatch *inside* the locked donation path; `invoice.paid` can book a registration as a Donation; columns added to live tables | 5 — Connect-secret gap, org-side drift, same `invoice.*` disambiguation risk |
| Testability (15%) | **8** — all state local, seams stubbed, every invariant pinnable | 7 — shared-dispatch tests are fragile | 6 — Stripe-side catalog/schedule state hard to stub; reconciliation awkward |
| Simplicity/maintainability (15%) | 7 — more tables, two Stripe services | 6 — `forms` becomes a god-noun | 6 — reconciliation job + drift alarms forever |
| Reversibility (10%) | **8** — drop six additive tables | 5 — live `forms`/`form_responses` mutated | 6 — Products/Prices persist on org accounts beyond migration control |
| **Weighted** | **8.05** | 6.25 | 6.60 |

**Winner: offering-engine**, with grafts: payable-forms' `quote` and re-mint-checkout endpoints and its $0-branch rule; stripe-native's derive-tenant-from-`event.account` webhook stamping, reserve-at-pending + `checkout.session.expired` release, and its Connect-secret finding (which applies to all three and only stripe-native's critic caught).

---

# T-006 — Registration + Billing Engine (Final Design)

## Decision

First-class Offering/Registration nouns. Forms remain the only intake machinery; groups remain the only people-structure; money mirrors the locked donation doctrine (Connect Standard, direct charges, hosted Checkout only, integer minor units, webhook-only advancement). The donation path is **not refactored** — `RegistrationCheckoutService` is a deliberate sibling, not a shared abstraction; the only shared file touched is `StripeWebhookController`, additively.

## Data model (all money integer minor units; all models `BelongsToMasjid` with denormalised `masjid_id`; kinds/statuses are PHP constants, never DB enums)

- **offerings** — `masjid_id, kind (event|program|admission|membership|appointment), name, slug` (unique per tenant), `intake_form_id→forms, group_id` (nullable roster target), `capacity, registration_count` (denormalised, forms-style), `opens_at, closes_at, is_active, settings` json, softDeletes. *Why:* one table for every registerable thing; `kind` is presentation, verticals stay configuration.
- **fee_plans** — `masjid_id, offering_id, kind (free|one_time|installment|recurring), amount_minor, currency, billing_interval, installment_count, label, is_active`. **Immutable once referenced** — edits deactivate-and-replace; in-flight registrations keep their snapshot. *Why:* kills the price-change-while-open failure mode.
- **registrations** — `uuid, masjid_id, offering_id, fee_plan_id, contact_id` (payer/guardian), `form_response_id, status (pending|confirmed|waitlisted|cancelled)`, `payment_status (none|awaiting|active|paid|past_due|canceled)`, `list_total_minor, adjusted_total_minor` (snapshot at creation — the charged amount, always), `stripe_checkout_session_id, stripe_subscription_id, stripe_subscription_schedule_id, checkout_expires_at, idempotency_key`. *Why:* two independent state machines; snapshot pricing; opaque `uuid` handle (donation pattern), always masjid-filtered on lookup.
- **registrants** — `masjid_id, registration_id, contact_id`. The roster; Contacts-first per the groups rule.
- **registration_adjustments** — `masjid_id, registration_id, kind (aid|discount|code), amount_minor, reason, granted_by_user_id`. *Why:* auditable aid, granted server-side by admins — no self-service discount hole.
- **registration_payments** — `masjid_id, registration_id, amount_minor, stripe_payment_intent_id/invoice_id/charge_id/balance_txn_id, stripe_fee_minor, net_minor, status, paid_at, idempotency_key`. One row per charge (N per installment plan); mirrors `donations` financial columns.

Reused unchanged: `forms/form_responses` (intake), `groups/group_memberships` (roster materialisation), `stripe_webhook_events` (dedup).

## Endpoints

**Public (`/api/v1`, unbound — every handler sets/filters `masjid_id` explicitly; the `BelongsToMasjid` creating hook does NOT stamp here):**
- `GET offerings/{slug}` — offering + active fee plans + form schema
- `POST offerings/{slug}/quote` — server-priced preview incl. adjustment code (graft)
- `POST offerings/{slug}/register` — one transaction: `FormSchema` validation → `form_response` → capacity `lockForUpdate` re-check → registration `pending` with snapshot totals → seat reserved. Returns `checkout_url`, or confirms synchronously if total is 0.
- `POST registrations/{uuid}/checkout` — re-mint an abandoned session, idempotency-keyed (graft)

**Admin (tenant/crm group):** `OfferingsController` (CRUD), `FeePlansController` (create/deactivate only), `RegistrationsController` (roster, waitlist promote, cancel, adjustments grant).

**Webhook:** existing `POST /api/stripe/webhook` route shape, but registration events from connected accounts arrive on a **Connect endpoint with its own signing secret** (`STRIPE_CONNECT_WEBHOOK_SECRET`) — verified fail-closed exactly like the platform secret. This was a latent gap in all three proposals.

## Stripe objects & webhook handlers

- **one_time** → Checkout Session, `mode=payment`, direct charge on `stripe_account`, `application_fee_amount` only when > 0, metadata `registration_uuid`.
- **installment** → Checkout `mode=subscription` + Subscription Schedule (`end_behavior=cancel` after N iterations); **recurring** → plain subscription. Both carry `registration_uuid` in *subscription* metadata (so `invoice.*` events carry it) and use **`application_fee_percent`** — the amount-based param does not exist for subscriptions; omit when 0.
- **free / 100% aid** → no Stripe leg. **Declared doctrine carve-out:** confirmation is synchronous in-request; this is the one path that advances state outside a webhook, and any waiver reducing the total to 0 (or below Stripe's minimum) must branch here — never a $0 session.

Handlers (all: dedup via `stripe_webhook_events`, re-fetch + status guard, order-independent):
`checkout.session.completed` / `payment_intent.succeeded` — converge to one `registration_payments` row, one confirmation, one `group_memberships` write. `checkout.session.expired` — release the seat (decrement counter, mark cancelled if never paid). `invoice.payment_succeeded` — append payment row, `active`. `invoice.payment_failed` — `past_due` only; **never** touches `status` — un-enrolling a mid-semester child is an explicit admin action. `subscription_schedule.completed` — `paid`. `charge.refunded` — record on the payment row only; roster untouched.

**Dispatch safety (highest-risk touchpoint):** events with `registration_uuid` metadata route to `RegistrationPaymentService`; events **without it default to today's donation path unchanged** — pinned by tests asserting existing donation fixtures still book as donations and a registration `invoice.paid` never creates a Donation. **Tenant trust:** `masjid_id` is derived from `event.account` matched against the masjid's stored Stripe account id, then cross-checked against the registration's `masjid_id` — metadata alone never decides tenancy, so masjid A's events can never advance masjid B's registration.

## Forms & groups integration

`intake_form_id` points at a normal form; answers validate through `FormSchema` and persist as a normal `form_response` — never duplicated. On confirmation, `RegistrationService` writes `group_memberships` for each registrant into `offerings.group_id` plus `guardian_of_contact_id` edges via the existing invariants. Groups stay payment-free per DECISIONS 2026-08-10.

## Financial aid — strictly pre-checkout

Adjustments are admin-granted rows applied when computing `adjusted_total_minor` *before* session creation; Checkout charges exactly that snapshot. No post-hoc money movement, ever. Public quote accepts codes but validates them server-side.

## Capacity under concurrency

**Reserve-at-pending:** the registration transaction takes `lockForUpdate` on the offering, re-checks `registration_count < capacity`, increments, commits — two guardians racing the last seat means exactly one `pending`, the other `waitlisted`. Nobody pays for a seat they don't hold, so no auto-refund path exists. Held seats are bounded by `checkout_expires_at`: `checkout.session.expired` releases them, and a scheduled reaper (T-006f) sweeps any pendings whose webhook never arrived.

## Slices

- **T-006a (S)** — 6 migrations, models, constants, factories; mandatory cross-tenant Feature test per model; uuid lookups masjid-filtered.
- **T-006b (M)** — `RegistrationService`: intake transaction, capacity lock, pricing snapshot, adjustments, free-path synchronous confirmation, group/guardian writes. Ships free offerings end-to-end.
- **T-006c (L)** — `RegistrationCheckoutService` (one_time), public quote/register/checkout endpoints, Connect-secret verification, `completed`/`succeeded`/`expired` handlers, dispatch pinning tests (donation regression suite must pass untouched). Ships paid one-time offerings.
- **T-006d (M)** — Admin controllers: offerings, immutable fee plans, roster, adjustment grants, waitlist promotion, explicit cancel (with subscription cancel when present).
- **T-006e (L)** — installments/recurring: schedules, `application_fee_percent`, `invoice.*` + schedule handlers, dunning states, ordering-permutation tests (`invoice.payment_succeeded` before `checkout.session.completed` must converge).
- **T-006f (S)** — expired-checkout reaper + seat-release job; concurrency test: N parallel last-seat attempts → exactly one confirmed, capacity never exceeded.

Each slice lands with its tests green, seams stubbed, no live API.

## Explicitly deferred (with reasons)

- **Refund modeling / partial-refund states** — v1 refunds are admin actions in the org's Stripe dashboard (org is MoR and bears refunds); `charge.refunded` records but automated refund flows need their own task.
- **Registration receipts** — not gifts; when built they get a **separate serial sequence with advantage amounts**, never the gap-free donation sequence. Out of T-006 scope.
- **`offering_slots` (clinic appointments)** — additive table later; `kind=appointment` reserves the vocabulary.
- **Waitlist auto-promotion with payment window** — manual admin promotion in T-006d; automation deferred.
- **Cross-offering transfers, donor-covers-fees gross-up for tuition, tax/Terminal** — separate tasks.
- **Shared-helper extraction between the two Stripe services** — deliberately not done; `DonationService` is locked, and premature abstraction is the riskier move. Revisit only via a dedicated task after both are stable.

Every critic finding above is either designed-in (tenancy stamping, webhook account verification, free-path carve-out, fee-percent, capacity/expiry, price immutability, dunning-never-ejects, dispatch pinning, $0 branch, Connect secret) or on the deferred list with its reason.
