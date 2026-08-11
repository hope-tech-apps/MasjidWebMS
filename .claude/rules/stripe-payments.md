---
paths:
  - "app/Services/Stripe/**"
  - "app/Services/Receipts/**"
  - "app/Http/Controllers/StripeWebhookController.php"
  - "app/Http/Controllers/Mobile/DonationsController.php"
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
