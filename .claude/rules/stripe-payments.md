---
paths:
  - "app/Services/Stripe/**"
  - "app/Services/Receipts/**"
  - "app/Http/Controllers/StripeWebhookController.php"
  - "app/Http/Controllers/Mobile/DonationsController.php"
  - "app/Http/Controllers/Api/V1/FormSubmissionsController.php"
  - "app/Http/Controllers/Api/V1/FormResponsePaymentsController.php"
---
# Stripe payments (CRM donations — Connect Standard + direct charges)

The donation money path is a locked design. Do not deviate without a dedicated
task — the alternatives (destination charges, escrow, custom accounts) change who
holds funds and who bears liability.

## Account & charge model (do NOT change)

- **Stripe Connect STANDARD accounts + DIRECT charges + `application_fee_amount`.**
  The connected org (masjid) is the merchant of record. The charge is created ON
  the connected account (the `stripe_account` request option = the
  `Stripe-Account` header). Funds land in the ORG's balance; the platform
  receives ONLY its application fee; the org bears its own refunds/disputes.
- **Never** use destination charges, `transfer_data`, `on_behalf_of` escrow, or
  hold platform-side balances. The platform must never be the merchant of record.
- **PCI SAQ A**: card data is entered on Stripe's hosted Checkout page. This app
  never sees a PAN — never build a custom card form or handle raw card data.
- `application_fee_amount` is sent ONLY when > 0 (Stripe rejects a zero fee).
  Platform fee % is `config('services.stripe.platform_fee_percentage')`, default 0.

## Webhooks are the source of truth

- **Never trust the browser redirect.** A donation is advanced to `succeeded`
  and a receipt issued ONLY from a signature-verified webhook.
- The webhook route (`/api/stripe/webhook`) is registered OUTSIDE auth + throttle
  (like the Pusher webhook). The HMAC signature verified against
  `STRIPE_WEBHOOK_SECRET` is the ONLY gate — **fail closed** if the secret is
  unset.
- **TWO signing secrets** (added by T-006c, not a deviation). Events raised on a
  CONNECTED account are delivered by a separate Stripe *Connect* endpoint, which
  signs with `STRIPE_CONNECT_WEBHOOK_SECRET`. `verifiedEvent()` accepts a payload
  authentic under EITHER configured secret and rejects one authentic under
  neither. Fail-closed in both directions: no secrets configured accepts
  nothing, and an unset Connect secret makes connect deliveries fail
  verification — it is never a bypass.
- **Idempotent + dedup + order-independent.** Every event id is recorded in
  `stripe_webhook_events` (unique). Re-fetch/guard on current status; receipt
  issuance is idempotent per donation (unique `donation_id` + in-transaction
  check). `checkout.session.completed` and `payment_intent.succeeded` may both
  fire — they must converge to one succeeded donation + one receipt.
- **Paid means `payment_status: paid`, never `status: complete`.** Every
  `checkout.session.completed` carries `status: complete`, including a delayed
  payment method (a US bank debit) whose money has not moved; that one arrives
  with `payment_status: unpaid` and only records the session handle. Its money
  settles through `checkout.session.async_payment_succeeded` (routed to the same
  handlers, the session now `paid`) or `payment_intent.succeeded`, idempotently.
  `checkout.session.async_payment_failed` books nothing and is logged at warning.
  A registration's unpaid completion HOLDS the seat (`holdWhilePaymentClears`):
  the completed page becomes the current one, `checkout_expires_at` is nulled so
  the reaper cannot cancel a seat that is being paid for (the reaper re-checks
  its filter under the row lock), a subscription's id is linked and an
  installment schedule attached (linking and bounding are not settlement), and
  checkout refuses a second page while it clears (`Registration::paymentIsClearing()`).
  A failed debit gives the seat back through `releaseSeat()` and cancels a
  subscription behind it, the pair an admin cancel runs; a meal order forgets its
  spent page so staff can send a new link. If the failure event never arrives, a
  clearing seat has no deadline and only an admin releases it. No checkout here
  pins card-only, so any
  organisation that enables bank debits in its Stripe dashboard reaches this path;
  the production Connect endpoint must subscribe both async events (LOG.md
  2026-09-11).
- Persist a `pending` donation row BEFORE the redirect; write with an
  idempotency key so a retried Checkout Session create can't double-charge.

## Money & receipts

- **All money is integer MINOR UNITS (cents).** Never floats.
- **Donor-covers-fees gross-up** (documented + unit-tested in
  `DonationService::grossUp`): `charged = round((intended + fixed) / (1 - rate))`,
  so the org still NETS the intended amount after Stripe's fee. Rate/fixed are
  configurable (`services.stripe.fee_percentage` / `fee_fixed`), default 2.9%+30¢.
- **Receipt serials are GAP-FREE per masjid** — a per-masjid sequence 1, 2, 3, …
  allocated transaction-safely (`lockForUpdate` + unique `(masjid_id,
  serial_number)`). `eligible_amount = gross_amount − advantage_amount`
  (advantage 0 for a plain cash gift). Jurisdiction 'US' for now.

## Registrations reuse this doctrine, they do not refactor it (T-006c)

`App\Services\Stripe\RegistrationCheckoutService` (outbound) and
`RegistrationPaymentService` (inbound webhooks) are a DELIBERATE SIBLING of
`DonationService`, per docs/t006-registration-billing-design.md — shared-helper
extraction between the two is explicitly deferred to its own task. They mirror
every rule above: Connect Standard + direct charge on `stripe_account`,
hosted-only, positive-only `application_fee_amount`, integer minor units,
pending-before-redirect with an idempotency key, webhook-only advancement.

- The charged amount is the registration's `adjusted_total_minor` SNAPSHOT.
  A total of 0 is the free-path carve-out and **never** a $0 Session.
- `metadata.registration_uuid` is the dispatch signal in
  `StripeWebhookController`. Objects carrying it route to the registration
  handlers; **everything without it keeps today's donation behaviour, unchanged**
  — pinned by `RegistrationWebhookTest` plus the untouched `DonationFlowTest`.
- Tenancy on inbound events is derived from `event.account` matched against
  `masjids.stripe_account_id`, then the uuid is looked up WITHIN that masjid.
  Metadata alone never decides tenancy.
- Registration payments record fee/net ONLY from a balance transaction expanded
  on the payload. No read-back, and no fee-formula estimate: a registration
  issues no receipt, so a guessed fee would be a fabricated number in a
  financial ledger (contrast the donation fallback, which backs a receipt).

### Subscriptions (T-006e) — same doctrine, two parameter differences

- **`mode=subscription` is still a DIRECT charge on the connected account.**
  Installment and recurring plans change the SHAPE of the Checkout Session and
  nothing about who holds the money. Never `transfer_data` / `on_behalf_of`.
- **`application_fee_percent`, not `application_fee_amount`** — the amount-based
  param does not exist for subscriptions. Still positive-only: the key is
  ABSENT at 0, never sent as 0. Restated on `RegistrationCheckoutService`
  rather than reached out of the locked `DonationService`.
- **Routing metadata goes on `subscription_data.metadata`**, so it lands on the
  SUBSCRIPTION and every `invoice.*` event it raises carries
  `registration_uuid`. Invoices do NOT inherit invoice-level metadata, and
  Stripe has moved subscription details between `subscription_details` and
  `parent.subscription_details` across API versions — all known locations are
  checked, so an invoice routes correctly regardless of pinned version.
- **STRIPE OWNS THE BILLING CLOCK, THE RETRIES AND THE DUNNING.** Do not build a
  payment scheduler, a retry loop, or a dunning engine. The only thing this
  codebase creates is a Subscription Schedule (`end_behavior=cancel`,
  `iterations=N`) telling Stripe when to stop; it is attached idempotently from
  whichever event first carries the subscription id, and a failure to attach it
  is LOGGED, never thrown — a 500 in a webhook makes Stripe retry forever.
- **Dispatch stays additive.** `invoice.payment_succeeded` and
  `customer.subscription.deleted` were already donation events; they now ask the
  registration question first and fall through to the identical donation call
  otherwise. `invoice.payment_failed` and `subscription_schedule.completed` are
  new arms whose non-registration case is the `null` that `default` gave them
  before. `DonationFlowTest` must keep passing untouched.

## Forms take one payment — the fourth sibling (DECISIONS.md 2026-09-11)

After donations, registrations and meal orders: a form whose settings turn card
payment on (`settings.payment.online`) opens ONE hosted Checkout per response.
`App\Services\Stripe\FormResponseCheckoutService` is a clone of
`MealOrderCheckoutService` as it stood at 42f07d6, not a refactor of it. Every rule
above holds (Connect Standard + direct charge on `stripe_account`, hosted only,
positive-only `application_fee_amount`, integer minor units, the idempotency key
persisted before the call, webhook-only advancement). On top of them:

- **`metadata.form_response_uuid`** is the routing key, on the session AND on
  `payment_intent_data.metadata`, beside `masjid_id` and `form_id`. Inbound, the
  masjid comes from `event.account` and the uuid is looked up within it
  (`FormResponse::findByUuidForMasjid`). `FormResponse::markPaid()` is true on the
  unpaid→paid transition only, and that is when the receipt and the coordinator
  email go. **A form row is never emailed while it is unpaid.**
- **Charged from the row's snapshot** (`amount_due_minor`, `fee_covered_minor`,
  `total_minor`), written at submit by `App\Support\FormPayment`, the only
  float-to-cents conversion. Never recomputed at call time, and the lines are
  asserted to sum to `total_minor` before the call. A paying form whose total comes
  to 0 is a 422 at submit: **never** the free path, never a $0 session.
- **Everything that could stop the page opening is asked BEFORE the row is
  written**: `canAcceptDonations()`, Stripe's charge bounds, and the return address.
- **Return URLs** are built only from an Origin that exactly matches
  `config('forms.payment_return_origins')`, plus a relative, regex-checked
  `return_path` (`App\Support\FormPaymentReturn`). The list fails closed when unset.
  It is NOT `cors.allowed_origins` (which defaults to `['*']`), there is no `APP_URL`
  fallback, and a client-supplied absolute URL is never passed to Stripe.
- **Pages live 30 minutes** (`expires_at`, plus a minute of slack for Stripe's own
  floor), not the 24-hour default: the festival also takes cash at the gate, and an
  open page is a second payment waiting to happen.
- **Card only** (`payment_method_types: ['card']`; Apple Pay and Google Pay come with
  it). A bank debit completes the page days before its money moves, and a registration
  that is complete but unpaid can be neither paid again nor settled by hand at the door.
- **Every page is opened under the response row's lock**, the first one included,
  so a double-tap that races past the replay guard is handed the first page.
  `reopen()` hands back an open page, refuses a complete one ("confirming"), and
  replaces an expired one on a NEW idempotency key. The "confirming" refusal
  (`FormCheckoutRefused::paidOnStripe()`) is the one 422 whose data says
  `confirming: true` and `can_pay: false`, on the submit's replay and "Return to
  payment" alike (`FormCheckoutRefused::answer()`): the payer has paid, so the page
  waits for the webhook and never offers to pay again. `closeOpenSession()` is the
  admin take-cash action's first step: expire the page, and after a refused close
  ask Stripe again ('complete' means the payer won the race). A refusal is answered
  with the row as the lock found it (`onLockedRow()` copies it back), never the copy
  read before the lock: cash taken, or a cancel, committed while the request waited
  must not come back as "unpaid, can pay".
- **A cancelled registration is never payable.** `preflight()` refuses it under the
  row lock, so "Return to payment", a replayed submit and a racing first page all stop
  there. The status read and the submit answer say `cancelled: true` (the status read
  with `can_pay: false`) and never carry the group link, whatever the payment state: a
  refunded card payer is cancelled and still reads as paid. Cancelling one closes its open page
  (`FormResponsesController::update()`, the 42f07d6 `closePageOfCancelled` rule). The
  cancellation stands whatever Stripe says; 'complete' tells the admin to refund.
  Cancelling one the card had ALREADY paid for (the webhook first, on a door list
  loaded before it) is a warning too, logged by ids: cancelling refunds nothing, so
  whichever of the two lands first, the admin is told to refund. That warning comes
  back on every "cancelled" said of a card-paid row, not only the first, and every such
  answer carries `card_page` (`closed`, `paid_on_stripe`, `unconfirmed`, `none`,
  `unchecked`): the admin screen words its answer from that, never from memory.
- **`customer_email`** is prefilled only when `FILTER_VALIDATE_EMAIL` passes and the
  domain has a dot. A Stripe `InvalidRequestException` is retried ONCE without it,
  on a new key. Stripe's error messages quote the address, so they are never logged.
- Refusals are `FormCheckoutRefused` (a `RuntimeException`), so the public
  controllers show those messages and never a database error's.
- **The status read and "Return to payment" are limited per registration** (30 and 20
  an hour), never per connection: every phone at the venue shares one address. A uuid
  naming no registration with a money leg at the header's masjid
  (`FormResponse::isPaymentHandle()`) meets a per-connection flood guard INSTEAD, so
  junk from a shared address never stops a real payer or spends its allowance. Every
  429 passes the throttle's `Retry-After` through and says the wait in words
  (`App\Support\TryAgainIn`), the staff-code lockout included.
- **Inbound is `App\Services\Stripe\FormResponsePaymentService`**, a clone of
  `MealOrderPaymentService`. `StripeWebhookController` asks its question third, on
  `checkout.session.completed` and `payment_intent.succeeded` only: order, then
  registration, then form response, then the unchanged donation default. A form
  event never books a Donation, and every other event keeps its route
  (`FormPaymentWebhookTest`; `DonationFlowTest` untouched).
  - **Paid means the session's `payment_status` is `paid`**, the rule every sibling
    follows (see "Webhooks are the source of truth" above): every
    `checkout.session.completed` carries `status: complete`, including a
    delayed-method payment whose money has not moved. Form checkout is card only, so
    that should never happen here; if it does, the completion records only the
    session id, and `payment_intent.succeeded` or
    `checkout.session.async_payment_succeeded` settles the row when the money lands.
  - **Refusals are logged at warning, never thrown** (a 500 makes Stripe retry an
    event that can never succeed): no account, an unknown account, or a uuid outside
    that account's masjid. Nothing is recorded.
  - **A card payment never flips a row that was not waiting for one** (cash at the
    gate, paid by staff or elsewhere, no money leg). Its payment intent id is
    recorded so the organisation can find the charge and refund it. A **second**
    payment intent on a card-paid row is logged as a double charge and never
    recorded over the first.
  - Money landing on a triage-`cancelled` row is recorded as paid and logged. An
    offboarded organisation's payment is recorded, and nobody is emailed on its
    behalf.
  - **The emails say how the registration was paid** (`FormNotifier::paymentLine()`:
    "Paid $X by card", "Paid in cash", "Paid (recorded by staff)"). The receipt
    carries the WhatsApp group link as a real `<a>` only once the row is settled
    (`FormResponse::isSettled()`). A paid receipt drops the form's payment note,
    which under the Wix fallback is the pay-here link. Both emails name the tier the
    row was priced at (`feeRule($response->submitted_at)`, the instant `lineItems()`
    prices the Stripe line at), not the tier in force when the payment is recorded.

## Tenancy note

`Fund`, `Donation`, `DonationReceipt` use `App\Models\Concerns\BelongsToMasjid`
(see `.claude/rules/tenant-scoping.md`). But the PUBLIC donation flow and the
WEBHOOK run UNBOUND (no tenant middleware), so those paths set/filter `masjid_id`
EXPLICITLY — the creating hook does not stamp it when unbound. `Masjid` is the
tenant root and is NOT a `BelongsToMasjid` model. `StripeWebhookEvent` is not
tenant-scoped (events span masjids).

## Live Stripe calls are isolated for testability

The only methods that touch the live API are thin protected/public seams
(`createCheckoutSession`, `createAccount`, `createAccountLink`, `retrieveAccount`,
`fetchChargeFinancials`) that return plain arrays. Money/persistence logic is
tested with these seams stubbed — tests MUST NOT hit the live Stripe API.
