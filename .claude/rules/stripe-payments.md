---
paths:
  - "app/Services/Stripe/**"
  - "app/Services/Receipts/**"
  - "app/Http/Controllers/StripeWebhookController.php"
  - "app/Http/Controllers/AdminDashboard/MealOrdersController.php"
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
- **One narrowing (DECISIONS.md 2026-09-15):** a SuperAdmin may point a child
  program org's FORM card payments at its parent's account. It is still a direct
  charge on one connected account, and the PARENT is the merchant of record for
  them. See "Forms may charge through a parent's account" below; nothing else
  shares an account.
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

## Forms: the required card fee and paying the office (DECISIONS.md 2026-09-13)

BISS Sunday School's registration adds two switches to a form's single payment:

- **`payment.requireFeeCoverage`.** `FormPayment::feeCoveredMinor()` adds
  `StripeFees::coverage()` to every CARD payment on the form, and `cover_fees` cannot
  turn it off. `$online` is false for staff codes and office rows, so their fee is 0.
  A card row settled by hand drops the fee (`FormResponse::paidAs()`). It never turns
  `allowsFeeCoverage()` on; the payload publishes `requireFeeCoverage` as its own key.
- **"Nets the full price" is conditional.** `application_fee_amount` is taken on the
  grossed-up total and is not grossed up itself, and the rate is platform-wide config,
  not the org's Connect pricing. `DonorCoversFeesTest` pins it at a platform fee of 0.
- **An office row never touches Stripe.** It has `payment_method` `office`, is unpaid,
  and has `fee_covered_minor` 0 with `total_minor` equal to what is owed. There is no
  `refusal()` check, no return origin and no session. The checkout preflight, `canPay`
  and the webhook's `explainNoTransition()` all read it as "not paid by card". A stray
  card payment on one is recorded and logged, and never flips it.
- **Office submissions are limited per email.** There are
  `forms.office_per_day` (3) per form per 24 hours, keyed by an HMAC of the
  normalised identity email. The next one is a 429 before any write, and the bucket
  is charged only when a row is written. Unpaid office rows hold capacity and never
  lapse (DECISIONS.md 2026-09-13, Known limits).
- **Office rows are the one unpaid money leg emailed at submit**
  (`FormNotifier::submitted()`). The receipt's note carries the office's instructions,
  and there is no group link. Settlement sends the paid receipt with
  `toCoordinators: false`.
- **Settled by hand exactly as before**: `cash` or `external`, plus `paid_via`.
  - `markPaidExternal` on an office row without `via` is refused on the locked row
    (`MarkFormResponsePaidRequest::refusal()`), before anything is written.
  - `paidAs()` writes `paid_via` `cash` for every cash settlement, gate or table.
  - `FormCashTotals` adds `external_by_via` (a detail, never a second total) and
    `owed_office`.
- **Routing (`FormSubmissionsController`).** A staff credential is always cash.
  Otherwise the row is `office` when chosen, or when `pay_with` is absent and
  `!Form::canTakeCardNow()`; otherwise it is a card payment.
  - A choice the form does not offer is a 422 on `pay_with`, never swapped for the
    other.
  - The replay fingerprint adds `pay_with: office` for office rows only, so card
    fingerprints written before the deploy still match their replays.

## Forms may charge through a parent's account (DECISIONS.md 2026-09-15)

A narrow exception to "every org is its own merchant of record", for FORM card
payments only. BISS (a school org, `parent_id` = Burlington Masjid) takes form card
payments on Burlington's existing Connect account and keeps its own
`stripe_account_id` NULL, so `masjids_active_stripe_account_unique` still holds.
Donations, lunch orders and registrations are untouched for every org, and
`Masjid::canAcceptDonations()` is NOT changed: a linked child still cannot take a
donation.

- **The link** is `masjids.forms_card_via_masjid_id` (+ `_set_at`, `_set_by`). Not
  fillable, on `PUBLIC_DIRECTORY_DENYLIST`, no FK. Its only writers are
  `FormsCardAccountController` and the `Masjid` force-delete hook, and every write is
  one transaction with a `masjid_forms_card_links_log` row (append-only: `link`,
  `unlink`, `revoke`, the actor, the typed holder name, the consent reference, the
  last four of the holder's `acct_`).
  - **Set** only by a SuperAdmin: `PATCH .../masjids/{id}/forms-card-account`. The
    check is `SetFormsCardAccountRequest::authorize()`, so a non-super gets a 403 with
    no validation keys. Refused (422, `code`) unless the holder is the child's parent,
    live, onboarded and not itself linked, the child has no account of its own and
    no one charges through it, and the typed name equals the holder's name.
  - **Revoked** by the holder's admin (`permission:manage donations`, tenant-bound to
    the holder): `DELETE .../masjids/{holder}/connect/forms-card-for/{child}`.
    Deliberately NOT behind `crm`: consent withdrawal must work while the holder's
    CRM is off. Archived children stay listed in `forms_card_for` and stay
    revocable, because a restore brings the link back.
  - The typed holder name is compared with surrounding whitespace trimmed on both
    sides (TrimStrings has already trimmed the input); case and inner spacing must
    match.
  - **Onboarding** refuses a linked org (409 in the controller, `LogicException` in
    `ensureConnectedAccount`). Its Online payments tab (every non-masjid with the CRM)
    shows the link's status from `connect/status.forms_card_via` and no Connect button.
    Never add a path that lets a linked org grow an account: the resolver checks the
    link first, so an own account makes its form card payments REFUSED, not re-routed.
- **One resolver** answers every Forms card question:
  `App\Services\Stripe\FormChargeAccount::for($org)`. A linked org gets the holder's
  account, read LIVE, only while the link equals `parent_id`, the holder is live, not
  itself linked, and has an `acct_` account with charges enabled. Anything else is
  null: card is unavailable, never a different payee. Never copy an account id or a
  charges flag onto the child.
- **The account is pinned on the row** (`form_responses.charge_account_id`, hidden,
  never cleared) when a page opens, before the Stripe call. Retrieve, expire, cancel,
  take cash and the webhook use the pin, never a fresh lookup.
- **Linked sessions keep the public handle out of metadata.** Their metadata is
  `form_charge_ref` (a random per-row key) and `form_id`: no `form_response_uuid`, no
  `client_reference_id`, no `masjid_id`, and Adaptive Pricing is off, because the
  holder's Stripe users can read the session. The statement descriptor suffix names
  the child. KNOWN EXCEPTION: `success_url` / `cancel_url` still carry the row uuid
  (`FormPaymentReturn::urls`). With the child's masjid id it reads the payment status,
  a settled row's WhatsApp link, and reopens checkout on an unpaid row, until the
  public form page keeps the uuid itself.
- **Stripe refusing a pinned account fails closed.** A 403 / `account_invalid` (never a
  401, which is the platform's own key) switches off the live org holding exactly that
  account and stamps `masjids.stripe_deauthorized_at`; while stamped, an
  `account.updated` created at or before the stamp is ignored.
- **Refunds and disputes flag only the payment recorded on a paid pinned row**
  (payment intent id matched with `hash_equals`); `charge_refunded_minor` records the
  amount refunded so far and a dispute outranks a refund.
- **Inbound, a pinned row is matched strictly**: `hash_equals(pin, event.account)`
  plus the session id or the amount and currency. A mismatch records nothing, and a
  pinned row never falls through to the unpinned path. Unpinned rows (every org that
  is not linked) keep today's path and warning texts.
- **The admin surfaces never show the holder's account id to the child.**
  `connect/status` adds `forms_card_via` / `forms_card_for` (names and a ready flag);
  `forms/card-account` answers `own | linked | unavailable`.
- The holder disconnecting the platform clears its charges flags
  (`account.application.deauthorized`), so the resolver fails closed. Refunds and
  disputes on a linked charge only FLAG the row (`charge_flag`); refunds are done by
  hand in the holder's dashboard.

## Lunch orders marked paid by hand (DECISIONS.md 2026-09-11)

The Jummah-lunch board's Mark paid (`MealOrdersController::markPaid`) is a
staff-asserted settlement, a carve-out from "payment state moves only on verified
webhooks", like the forms' take-cash. Until 2026-09-11 it was pay-at-pickup only
and refused every online order. It now works on any unpaid order that is not
cancelled, for admins and lunch volunteers, on these terms:

- **It always says how** (`paid_via`: `cash | zelle | terminal | stripe`,
  `MealOrder::PAID_VIA`). `stripe` is money taken through some other Stripe route:
  a label staff record. A payment on the order's own Checkout page is still
  recorded by the webhook alone and leaves `paid_via` NULL. Who and how are
  written by the first press only (`MealOrder::markPaidByHand`): a press on an
  order already marked paid by hand is a 200 with `recorded: false`, and `data`
  names what was recorded first, which is what the board reports, never the
  method it sent. A missing or unknown method is a 422 whose `data` is one sentence
  (`MarkMealOrderPaidRequest`), so a board on an old bundle can be told to reload.
- **The order's own page is closed first, under the row lock**
  (`MealOrderCheckoutService::closePageBeforePaidByHand`, through the same
  retrieve/expire seams). Open: expired and forgotten. `complete` and `paid`:
  refused, the webhook records it, and a warning is logged by ids (if the order
  still shows unpaid minutes later, the Connect webhook is not arriving).
  `complete` and `unpaid`: refused, a bank debit is clearing. A refused close is
  asked about again (the `closeSession()` rule). Stripe not answering, or no
  account on record: refused. An order the webhook has already settled from its
  own page (`MealOrder::paidOnItsOwnPage()`) is refused too, so the answer never
  depends on how fast Stripe delivers. A refusal records nothing.
  `payment_method` is never changed: it is the channel, and `paid_via` is how the
  money came.
- **Every page is made on the locked row, the first one included.** `checkout()`
  reads the order again under `lockForUpdate` and asks every refusal there (the
  forms' `onLockedRow()` rule), as `paymentLink()` always did. A Mark paid either
  waits and closes the page, or makes the order unpayable before a page exists. A
  page recorded while `checkout()` waited is handed back, never doubled.
- **A card payment on a hand-paid order is a double payment**
  (`MealOrderPaymentService::paidTwice()`). Its payment intent id is recorded;
  `paid_via`, `marked_paid_by_user_id` and `paid_at` are never rewritten; and a
  warning is logged by ids, once per payment intent, so the organisation refunds
  one. The board flags the row too (`paid_via` and `stripe_payment_intent_id`
  both set, a pair nothing else leaves), since the log reaches only the operator.
  The app never refunds it.

## Lunch top-ups: a paid order pays the difference first (DECISIONS.md 2026-09-25)

A customer may add plates to a PAID lunch order before the cutoff by paying the difference
(`MealOrderCheckoutService::openTopUp`, `MealOrderTopUpPaymentService`). Every rule above holds
(direct charge on the org's account, card only, idempotency key on the `meal_order_top_ups` row
before the call, positive-only application fee, integer minor units, webhook-only advancement).

- **Routed first.** The session's metadata is `kind: lunch_top_up`, `top_up_id`, `order_uuid`,
  `masjid_id`; `StripeWebhookController` asks `isTopUpEvent()` before `isOrderEvent()`, so a top-up
  is never the order's own payment. Its payment intent carries no `order_uuid`, and
  `payment_intent.succeeded` for it is acked and ignored.
- **Matched strictly.** Masjid from `event.account`, the top-up by id within it, then the session
  id, order uuid, metadata masjid id, `amount_total` and `payment_status: paid`. A mismatch records
  nothing and logs at warning.
- **The money is never lost.** If the order moved after the top-up was asked for, the plates are
  not applied; `settled_total_minor` += the amount, the row is `conflict`, and a warning is logged.
- Fewer plates on a paid order is refused online: no automatic refunds, ever.

## The Giving switch never touches money that moved (DECISIONS.md 2026-09-16, switches wave 2)

- **Webhooks, receipts and receipt emails never check a module.** A gift for an
  organisation whose `giving` module is off is booked, receipted and emailed like
  any other. `App\Support\GivingSwitch::noteArrivalIfOff` then logs
  `GivingSwitch::ARRIVAL_MESSAGE` at warning level, once per donation (`gift`) or
  commitment (`monthly_gift_started`) through `Cache::add`, AFTER that work. It
  never throws: an exception inside the webhook's try becomes a 500 that Stripe
  retries for days.
- **Intake follows it.** `Mobile\DonationsController::createCheckoutSession`
  refuses one-time and monthly checkout with a 403 sentence, before
  `canAcceptDonations` and before the try. `Mobile\FundsController` answers `[]`
  without reading or clearing its cache.
- **Connect onboarding, status and the forms-card Stop button never sit behind
  `giving`.** Connect renders on {term} Details › Online payments for every school
  or community organisation, and for a masjid while its Giving is switched off
  (`showsOnlinePaymentsTab`; owner, 2026-09-14). It needs the CRM, because the
  connect routes sit inside `crm`. A masjid with Giving on keeps it on the Giving
  Dashboard. A pointer to Connect asks `connectPlace` / `connectPlaceTitle`
  (null without the CRM) and never hard-codes a screen. That includes the offerings
  hint for `org_cannot_collect` (`registrationStateHint`). A linked org
  (`forms_card_via_masjid_id` set) is never pointed at onboarding (409): offerings
  charge only on its own account (`canAcceptDonations`), so its hint says program
  fees cannot take cards and suggests a free plan.
- **Switching Giving off is refused while any monthly gift can still charge**
  (`GivingSwitch::liveSubscriptionCount()` above zero): rows with a Stripe
  subscription id that are not `canceled`, plus a `canceled` row Stripe says it
  is still billing (only the Stripe dashboard can stop it). Stripe
  (`DonationService::stripeStatusOf`) is asked only about a cancelled row with a
  gift booked after its `canceled_at`. Local timestamps never decide it: a late
  or replayed invoice webhook also books after a cancel. An unknown answer
  counts. A donor's pause is still `active` locally, so it counts. Unlinked
  monthly checkout pages opened in the last 24 hours (`openCheckoutCount()`, a
  Checkout Session id present) refuse too, and say wait, never cancel: the admin
  cancel does not expire the session, so a cancelled row could still be paid.
  There is no override (owner: block), so anyone opening app checkout pages can
  hold the switch until 24 hours after the last one. A switch never cancels,
  pauses or changes a gift, and the member `/me/recurring-giving` verbs do not
  follow it.
- **Receipt wording by org type.** `Letterhead::religiousOrg($masjid)` (true for
  a masjid, and for a missing organisation) picks the tax wording. Every sender of
  `DonationReceiptMail` / `AnnualStatementMail` passes `religiousOrg:`, so the
  email agrees with the PDF it carries. The mailables declare it as a defaulted
  property, NOT a promoted one, so a payload queued before it existed still
  unserializes. Masjid output is byte-identical to c0f6a72
  (`ReceiptWordingByOrgTypeTest` against `tests/fixtures/receipts-c0f6a72`).

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
