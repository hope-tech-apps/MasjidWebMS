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
