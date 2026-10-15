# Family tuition ledger (item 9, default off)

This extends the T-006 engine documented in `t006-registration-billing-design.md`.
The September tuition workspace contained research and decisions, not a deployed
family ledger. The item 9 reconciliation is recorded verbatim in DECISIONS.md.

## Money and retention

`tuition_ledger` is a hidden school grant, false for every organisation type. No
school is enabled by this build. All amounts are signed integer minor units, with
currency snapshotted on the account and FeePlan. School currency, minimum card
payment and optional reminder intervals live in `organisation_billing_settings`;
currency becomes fixed when the school creates its first account or schedule.
Accounts may raise the school minimum or override reminder intervals. No new
`masjids` columns or production configuration changes.

The account row is the mutex for every financial write. The only line writer is
`App\Services\FamilyLedger\FamilyLedger`. Line kinds:

| Kind | Sign | Authority |
|---|---|---|
| charge | positive | office reason or due-date schedule |
| sibling_discount | negative | schedule; references the child's charge |
| credit | negative | office reason |
| office_payment | negative | office, actual received date, fixed method, reference |
| card_payment | negative | verified Stripe webhook only |
| refund | positive | verified `charge.refunded` cumulative delta |
| void | opposite original | office reason, original entry ID, actor/time |

Models reject updates/deletes. There are no line update/delete HTTP endpoints.
Staging scrub is the explicit non-production exception for PII. Voiding a charge
also voids its attached discount. Rerunning a schedule cannot recreate a voided
charge. Staff cannot void Stripe payments or refunds; the school refunds through
its own Stripe dashboard. Balance is always SUM(amount_minor), never a stored
payment state. FIFO allocation of payments/credits computes remaining due amounts.
Office methods: cash, check, Zelle, Cash App, Venmo, bank transfer, other. Staff
cannot insert Stripe IDs or create Stripe-looking payment state. Nothing writes
office receipts into RegistrationPayment or Registration.payment_status.

Every financial contact FK is RESTRICT. No new FK into group_memberships.
MemberAccountDeletion retains children, guardians, payers and mail recipients,
with guarded optional-table reads pinned separately in App101OffContractTest.
Wix undo inherits the same retention lists. Contact merges involving a source
with tuition history are refused rather than rewriting immutable attribution.

Staging classification for all new columns:

- `tuition_lines.reason`, `recorded_by_label`: anonymise; reference and Stripe PI:
  null. No names, references or free text survive in these fields.
- `tuition_checkouts.stripe_account_id`: anonymise; session ID and URL: null.
- Accounts, children, guardians, schedules, settings: IDs, dates, fixed enums,
  percentages, currency, minimums and reminder intervals are retained. No names or
  email addresses are copied into them. Schedule group/grade JSON is school
  targeting metadata; the human FeePlan label uses existing FeePlan scrub policy.
- Mail logs contain contact/account IDs, dates, fixed statuses and financial
  due-date/amount JSON only. Addresses are resolved at delivery and never logged.
- Amounts, immutable keys, actor IDs, kinds, methods, period identifiers, review
  flags and timestamps are operational/financial records, retained. Contacts and
  staff names are independently scrubbed by existing policy.

## Office workflow and schedules

With the grant enabled, open **Family tuition ledger** from the school sidebar.
Set school currency, minimum and reminder intervals first. Open a family account
by explicitly choosing its children and their confirmed guardians. A child can
belong to only one account. This build has no payer-only or split-household mode:
never put different custodial households on a shared account unless each confirmed
guardian is allowed to read every child on it.

Create a plan under an existing Offering. It uses a T-006 FeePlan, inactive for
registration intake so it cannot also collect the same fee through registration.
The tuition schedule's own active flag controls posting. Prices are immutable:
deactivate the schedule and create its replacement for future periods. Check
that active plans do not overlap for the same children and period.

- Yearly: one charge on the school's first school-year date.
- Per term: one on each dated term's start date; term configuration is required.
- Monthly: chosen day, default 9, clamped to the last day of a shorter month,
  inside the inclusive school-year dates.
- Closed and non-meeting days remain due dates. Calendar timezone determines
  today; holidays do not move a monetary obligation to another day.
- Target current participants by classes or grades. Both chosen means both must
  match. Each child is charged once per plan/period even with several class rows.
- Eligible siblings in the same account/plan/period are ordered by birth date,
  eldest first. Unknown dates sort last; equal/missing dates use contact ID.
  Eldest pays full; younger siblings each receive the configured percentage,
  rounded down to a minor unit, on their own discount line.

`tuition:charges` runs every 15 minutes. Unique (masjid_id, idempotency_key), with
key `charge:{fee_plan_id}:{child_contact_id}:{period}`, plus the account lock,
prevents overlap/rerun duplicates. A discount is keyed by its charge ID. No
backfill is automatic after a missed due day; add a documented office charge if
one was missed. Only children already assigned to an account by that date qualify.

The office list shows every family's balance and an overdue filter. Open a family
for its range ledger, corrections, one-offs/credits, recorded office payments,
PDF statement, recipient preview, email action and delivery log. CSV export uses
inclusive dates, an opening/closing balance, exact minor units, and formula guards.
The UI displays school-entered prose as text, never HTML.

## Family authority and payment

Only the existing family parent guard opens these routes. Student hand-off and
mobile member tokens grant no billing access. Every request AND queued delivery
requires office account membership plus a confirmed guardian edge to EVERY child,
including withdrawn children. Removing a custody edge cuts access immediately;
an email match grants nothing. The member portal remains dark, preserving #61;
no universal-cart flags, origins, routing or payment blockers are bypassed.

Families see their balance, next due amount/date, each child's lines, history and
an inclusive date-range PDF with an opening balance. A school without a connected
Stripe account can keep its ledger and office payments; the family sees that card
payment is unavailable. New labels exist in all seven language tables; non-English
tuition labels are machine-drafted pending fluent review.

Checkout requires `Masjid::canAcceptDonations()` and uses the school's own Standard
Connect account through the existing RegistrationCheckoutService direct-charge
transport (`stripe_account` request option / Stripe-Account header). Hosted
Checkout accepts cards only. A family explicitly chooses a positive whole or
partial balance, never more than the current balance or below the school minimum.
No card data reaches Manara. No card surcharge is added. The existing platform
fee fraction is parsed as an exact decimal for integer fee arithmetic.

Return URLs use server-configured app.url and the family portal path, never
caller input or the cart's return-origin allowlist. The return page merely reads
and says confirmation is pending. It writes no financial line.

A durable Checkout attempt carries account, payer, currency, connected-account
and balance/amount snapshots. Only signature-verified StripeWebhookController
calls settlement, through existing stripe_webhook_events delivery deduplication.
Session and PaymentIntent success converge on one `card:{checkout_id}` line.
Paid-session means payment_status=paid. An amount/balance mismatch still records
money received and raises needs_review in the office ledger. Wrong-account events
cannot settle; currency mismatch fails the webhook for office reconciliation.
Refunds append only the new amount beyond the largest recorded cumulative refund,
so duplicate, out-of-order and refund-before-payment deliveries remain idempotent.
Switch-off or a changed Connect link never discards a payment already in flight.

## Owner steps before enabling real use

The build has no network and does not change Stripe endpoint subscriptions.
Confirm these event types on the school's Connect webhook endpoint:

- `checkout.session.completed`
- `payment_intent.succeeded`
- `charge.refunded`

Adding or confirming those subscriptions is an OWNER step. This card-only path
does not need asynchronous payment-method events, preserving PM-A6c. It does not
change the cart's expired/failed arms or PM-A6b strict form-refund exclusion.

Prove the complete path in Stripe TEST mode: three siblings, due-day charges and
discounts, family-initiated Checkout, signed completion/payment-intent deliveries,
duplicate delivery, changed balance flag, cash entry, credit, Stripe refund, family
history/PDF, office overdue list and reminders. Do not enable real data before the
September decision's off-site backup/restore precondition has been satisfied.
Enabling a school and sending real family mail remain owner actions.

Autopay subscriptions are not implemented here. When that September work lands,
its verified invoice payments will project RegistrationPayment into this same
ledger with their own unique source key; a family on autopay sees those payment
lines against its scheduled debt. Ledger credits never change what an existing
Stripe subscription collects. Manara builds no payment scheduler.

## Mail and evidence limits

`tuition:remind` runs hourly. Optional school before/after intervals create one
reminder per account/guardian/day, listing due dates/amounts together. Future
charges are previewed, never posted early. Amounts are recomputed before sending;
paid debt suppresses an overdue message. There are no automatic late fees.
Office statement sends use the same recipient authority and log. The database
queue carries IDs only; the worker resolves live ContactIdentity addresses,
rechecks the switch and custody, and renders a PDF only after dequeue. A committed
sending claim allows one external attempt. Failed/uncertain attempts stay visible
for the office and are never silently retried. Stale sending claims become failed.
Existing SchoolMailTransport rejects unsafe fallback transports outside tests.
No address or exception message containing an address is written to a log.

Local tests fake Stripe and mail; a real database queue Worker exercises
serialization and statement PDF attachment construction. SQLite tests do not prove
InnoDB lock behaviour. MySQL CI runs the new schema/key/RESTRICT checks and the
existing migration rollback/forward gate; local MySQL execution is unverified.

Further verified details: term period keys use school-year ID and stable term
position, rather than a replaceable term-row ID. Payment/refund effective dates
use verified Stripe event time in the school timezone. The portal's next-due
preview accounts for prepaid credits; a measured prepaid-month read used 44 SQL
statements after the correction versus 99 before, without creating future lines.
Office entry action UUIDs survive a failed request/retry; the same key with changed
details is a refusal. Tuition routing never preempts any existing money metadata.

The PDF reuses `App\Services\Receipts\Letterhead` from the existing receipt and
annual-statement code. It uses the school report-card mPDF renderer pattern for
Arabic/other child names, rather than the giving statement's Latin-oriented
renderer or its charitable tax wording. September's custom subscription setup and
separate existing-account Connect flow were not found in the base code and are
not implemented by this ledger item. It consumes the existing Connect linkage.

Build evidence and every changed path are in `tuition-ledger-build-report.md`.
