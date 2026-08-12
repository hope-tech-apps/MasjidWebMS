---
paths:
  - "app/Models/Offering.php"
  - "app/Support/OfferingPublicPayload.php"
  - "app/Models/FeePlan.php"
  - "app/Models/Registration.php"
  - "app/Models/Registrant.php"
  - "app/Models/RegistrationAdjustment.php"
  - "app/Models/RegistrationPayment.php"
  - "app/Services/Registrations/**"
  - "database/migrations/*_create_offerings_table.php"
  - "database/migrations/*_create_fee_plans_table.php"
  - "database/migrations/*_create_registration*"
  - "database/migrations/*_create_registrants_table.php"
---
# Registration + billing data layer (T-006a commitments)

docs/t006-registration-billing-design.md is the ratified design. Where it left
a column detail open, T-006a fixed the following — later slices (T-006b..f)
MUST build on these, not re-decide them:

- **Currency lives on `fee_plans` ONLY.** It is not re-snapshotted onto
  registrations or payments: a fee plan is immutable once referenced, so
  `registration->feePlan->currency` is always the denomination of the snapshot
  totals. Checkout/webhook code resolves currency through `fee_plan_id`.
- **`registration_adjustments.amount_minor` is an UNSIGNED reduction
  magnitude.** `adjusted_total_minor = list_total_minor − Σ adjustments`,
  floored at 0 by the service. The table cannot express a surcharge; do not
  encode one as a negative anything.
- **`registration_payments.status` uses the donation ledger's vocabulary**
  (`pending|succeeded|failed|refunded`, `RegistrationPayment::STATUSES`).
- **The two cancel spellings are intentional and distinct**: seat
  `Registration::STATUS_CANCELLED` ('cancelled') vs money
  `Registration::PAYMENT_CANCELED` ('canceled', Stripe's subscription word).
  Always reference the constants; `RegistrationBillingModelTest` pins both.
- **uuid lookups go through `Registration::findByUuidForMasjid($uuid,
  $masjidId)`** on every public/unbound path — never resolve a registration
  from a client uuid alone (the tenant scope adds no filter when unbound).
- **`offerings.registration_count` is guarded** (not mass-assignable); it is
  written only inside the locked registration transaction and the seat-release
  paths. `offerings.intake_form_id` is NOT NULL — an offering cannot exist
  without its intake form. `FeePlan` has deliberately NO kind() degrade helper:
  money kinds fail validation loudly, they never degrade (contrast
  `Offering::kind()` → 'event', which is presentation-only).
- Fee plans are deactivate-and-replace: no softDeletes, no updates once
  referenced; `registrations.fee_plan_id` is a RESTRICT FK on purpose.

T-006b (`App\Services\Registrations\RegistrationService`) fixed these where
the doc left detail open — T-006c..f build on them:

- **`list_total_minor` per plan kind**: free → 0; one_time → `amount_minor`;
  installment → `amount_minor × installment_count` (the full commitment);
  recurring → `amount_minor` (per-interval charge; open-ended has no finite
  total). Unknown kinds throw (`RegistrationService::listTotalFor`).
- **One registration = one seat.** `registration_count` counts registrations,
  not registrants; a household with three children takes one seat.
- **`idempotency_key` is minted at INTAKE for paid pendings**
  (`reg_checkout_…`, the RegistrationFactory default state) — the
  create_registrations_table migration's "minted only at session creation"
  comment is superseded. T-006c keys the Session create with the stored key;
  the re-mint endpoint rotates it. Free/waitlisted rows keep it null.
- **The checkout window** is
  `config('services.stripe.registration_checkout_window_minutes')` (default
  30) — set on paid pendings at intake, consumed by T-006c's expired handler
  and T-006f's reaper.
- **`RegistrationService::confirm()` is the single confirmation seam**
  (idempotent, pending-only; writes the roster + guardian edges). The free
  path and 100%-waiver adjustments route through its outcome now; T-006c's
  webhook handlers must call it rather than flipping `status` themselves.
  Waitlisted rows never confirm through it — promotion is T-006d's explicit
  admin action.
- **Adjustment grants are refused once any Stripe leg exists** (session /
  subscription / schedule id, or payment_status beyond none|awaiting) —
  strictly pre-checkout, enforced in the service, not just the controller.

T-006c (`RegistrationCheckoutService`, `RegistrationPaymentService`, the public
quote/register/checkout endpoints) fixed these — T-006d..f build on them:

- **The convergence key is `reg_payment_{registration_uuid}`**
  (`RegistrationPaymentService::paymentKey`). A one_time plan is exactly ONE
  charge by construction, so `checkout.session.completed` and
  `payment_intent.succeeded` derive the SAME key: whichever lands first creates
  the single `registration_payments` row, the other MERGES into it (never
  overwriting a recorded identifier, never blanking one). T-006e's installments
  must key per INVOICE — a different prefix, which cannot collide with this one.
- **Handlers never flip `status` themselves.** They call
  `RegistrationService::confirm()`, so the paid path materialises its roster
  through the exact code the free path uses. Money that lands on a non-pending
  seat is recorded in the ledger and logged; it never throws (a 500 would have
  Stripe retry forever) and never resurrects a cancelled seat.
- **`RegistrationService::releaseSeat()` is the seat-release seam** — the only
  writer of `offerings.registration_count` besides intake. Pending-only,
  idempotent, decrements under `lockForUpdate`, and sets seat `cancelled` +
  money `canceled`. T-006f's reaper must call THIS, not its own decrement.
- **A superseded session never releases a seat.** `checkout.session.expired` is
  ignored unless the expiring session id IS the registration's current
  `stripe_checkout_session_id` — otherwise an abandoned first session would
  cancel a seat the registrant is actively paying for through the re-minted one.
- **The checkout window is clamped to Stripe's [30 min, 24 h] expiry bounds** and
  the clamped value is written back to `checkout_expires_at`, so the deadline we
  sweep against and the one Stripe expires against are the same instant.
- **Public pricing is server-side, always.** `quote` writes nothing; a
  client-supplied `code` is reported back as `code_applied: false` and can never
  move a price — aid is an admin grant (T-006d). The payer's email is required
  because a Contact is keyed on (masjid, email); registrants may have none.

T-006e (installments + recurring: the subscription legs and the `invoice.*` /
subscription-lifecycle handlers) fixed these:

- **The per-invoice ledger key is `reg_invoice_{stripe_invoice_id}`**
  (`RegistrationPaymentService::invoicePaymentKey`). A subscription is N
  charges, so the uuid is the wrong grain; the invoice id is the natural key and
  the prefix cannot collide with T-006c's `reg_payment_{uuid}`. EVERY event
  about one invoice — the failure, the retry, the eventual success, any
  redelivery — derives the same key and therefore the same single row. A
  succeeded row is never downgraded by a stale failure.
- **Order independence across the subscription permutation is achieved by
  DIVIDING THE WORK, not by ordering it.** `invoice.payment_succeeded` owns the
  ledger row; `checkout.session.completed` owns the subscription link; BOTH
  confirm the seat through `RegistrationService::confirm()` and both may attach
  the installment schedule. Invoice-first self-heals `stripe_subscription_id`
  off the invoice. Either order ⇒ one confirmed registration, one roster, no
  duplicate rows (`RegistrationInstallmentTest` proves both arms).
- **A subscription-mode `checkout.session.completed` books NO payment row.**
  Money for a subscription arrives per invoice; booking here would double-count
  the first installment.
- **`payment_status` for subscriptions is DERIVED FROM THE LEDGER, never
  assumed**: `active` while a subscription is live, `paid` once an installment
  plan's succeeded rows reach its `installment_count` (also set by
  `subscription_schedule.completed`). Open-ended `recurring` never reaches
  `paid` — there is no finite commitment to finish.
- **DUNNING NEVER TOUCHES `status`.** `invoice.payment_failed` writes
  `payment_status` only. This is the whole reason T-006f's reaper excludes
  `past_due`, and the two halves must stay consistent: nothing may eject a payer
  for a declined card. A later successful retry lifts `past_due` back to
  `active` on its own — Stripe owns the retry cadence, this codebase has no
  scheduler, retry loop or dunning engine.
- **`past_due` is for PAYERS.** A registration that has never settled a payment
  AND is not confirmed stays `awaiting` on a failed invoice: marking an unpaid
  hold `past_due` would make it permanently immune to the reaper — an immortal
  seat nobody is paying for.
- **The installment per-charge amount is `intdiv(adjusted_total_minor,
  installment_count)`** (`RegistrationCheckoutService::perChargeMinor`), so
  pre-checkout aid reduces every installment. `list_total = amount × N` divides
  exactly, so a remainder only appears once aid is granted, and **the rounding
  is dropped in the PAYER's favour** — never charge a family more than the total
  they were quoted, and never a float.
- **`customer.subscription.deleted` and `subscription_schedule.completed` are
  MONEY events**: a fully-settled installment plan ends `paid`, anything else
  ends `canceled`, and the seat is untouched in both cases. Cancelling stops
  FUTURE billing only — settled `succeeded` rows are never restated, because v1
  refunds are the org's own action in its own Stripe dashboard.

T-006f (`registrations:reap-expired`, `App\Console\Commands\ReapExpiredCheckouts`)
fixed these:

- **The reaper owns no seat arithmetic.** It selects and calls
  `RegistrationService::releaseSeat()`; there is deliberately no decrement in
  the command.
- **`offerings.registration_count` is written in exactly three places, all in
  `RegistrationService`, all under the offering row lock with capacity
  re-checked inside it, and always as a RELATIVE `± 1` update — never a
  read-modify-write:** `register()` (intake increment),
  `promoteFromWaitlist()` (admin promotion increment, added by T-006d), and
  `releaseSeat()` (the single decrement seam). Nothing outside that class may
  touch the counter. If you add a fourth writer, it takes the same lock, the
  same in-lock capacity re-check, and a relative update — or capacity can be
  oversold, which means charging two families for one seat.
- **The sweep set is `Registration::scopeCheckoutExpiredBefore($deadline)`** —
  `pending` + `awaiting` + non-null `checkout_expires_at <= deadline`.
  `past_due` is EXCLUDED on purpose (dunning never ejects, T-006e), and `none`
  is the free path, which has no window to expire.
- **`$deadline` is `now() minus a GRACE MARGIN`, never a bare `now()`.**
  `config('services.stripe.registration_reaper_grace_minutes')`, default 15,
  `--grace=` for a one-off. It exists so a payment webhook still in flight can
  never have its seat reaped out from under a paying customer; the costs are
  asymmetric (a wrongly reaped seat needs a human refund from the org's
  dashboard, a late-swept seat costs a slightly later waitlist opening).
- **Convergence with `checkout.session.expired` is by construction**, both
  orders: webhook-first leaves a row the filter no longer matches, reaper-first
  leaves a non-pending seat the pending-only seam refuses. One decrement either
  way.
- **Not scheduled by default**, exactly like `groups:purge-feed` — cadence is an
  operator decision, so it belongs in routes/console.php when a policy is agreed.
- **The concurrency invariant is tested in two arms** (`RegistrationConcurrencyTest`):
  a forked N-process race that only runs on a lock-capable scratch database and
  skips cleanly otherwise, plus a deterministic arm that renders `lockForUpdate`
  as an inert SQL comment to prove the locked read and the relative counter
  write share one transaction. SQLite cannot prove mutual exclusion; that limit
  is stated in the test, not papered over with sequential calls.

T-006g (the public front door: `GET /api/v1/offerings/{slug}`, the `offering`
page section) fixed these:

- **`App\Support\OfferingPublicPayload` is the ONE definition of what an
  anonymous visitor may see about an offering**, and it has two consumers:
  `Api\V1\OfferingRegistrationsController@show` and
  `SectionContentBinder::bindOffering`. Do not hand-roll a second public shape
  anywhere. Two copies of that judgement is how a private field reaches a
  published page — the one that gets reviewed is tightened and the one nobody
  remembered is not. `OfferingSectionTypeTest` asserts the two payloads are
  byte-identical.
- **`is_active = false` is the UNPUBLISH switch, on the read as well as the
  write.** The public read uses exactly the predicate
  `OfferingRegistrationsController::findOffering` uses, so a draft is never
  discoverable and a page can never render a Register button that `register`
  would 404. A window that has merely closed is a different thing: it is served,
  with `is_open: false` and `closed_reason`.
- **`registration_state` (`open|waitlist|closed`) is the field a renderer
  switches on, not `is_open`.** Full is NOT closed — `register()` waitlists
  rather than refusing — and an offering whose intake form has been soft-deleted
  IS closed even though `is_open` reports true, because `register()` throws
  `offeringClosed()` when it cannot load the form. `is_open` / `closed_reason`
  are still reported verbatim from the model accessors; this field is the one
  that accounts for everything the write path checks.
- **Seats are published as `remaining` + `is_full`, never as `capacity` or
  `registration_count`.** `remaining` is a property of the OFFERING (how many
  more places it will accept); `registration_count` is a count of PEOPLE, and a
  public page is never a window onto the CRM. Publishing capacity as well would
  hand out the subtraction, which is the same number by another name.
- **The only internal id in the public payload is `fee_plans[].id`**, because
  `POST offerings/{slug}/register` takes `fee_plan_id`. Not the offering id, not
  `masjid_id`, not the intake form's id or slug — the public API is addressed by
  slug and uuid.
- **Fee plans are filtered to `is_active` AND to `FeePlan::KINDS`.** An
  unrecognized money kind is withheld rather than guessed at: `listTotalFor()`
  throws on one, and `FeePlan` has no degrade helper on purpose. Withholding is
  the fail-closed answer; crashing the public page is not, and guessing would
  waive or misquote a fee.
- **`total_minor` is `RegistrationService::listTotalFor()`, never a
  multiplication done anywhere else** — the same function that snapshots
  `list_total_minor` at intake, so the number on the page and the number charged
  have one implementation.
- **The intake form's own `fee` rule and its `accepting`/`closed_reason` are NOT
  published on this payload.** Money for an offering comes from its fee plans and
  nowhere else (`register()` prices from the plan and leaves
  `form_responses.amount_due` null), and a second "is this open" flag beside the
  offering's is how the two drift and one starts lying.
- **`offerings.description` is a column, not a `settings` key.** It is the one
  field a public registration page cannot do without; burying it in the
  unvalidated knob bag would make it the only public field with no schema. It is
  not the intake form's `description` either — one form can be the intake for
  several offerings.
