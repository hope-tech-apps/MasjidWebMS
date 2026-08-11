---
paths:
  - "app/Models/Offering.php"
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
