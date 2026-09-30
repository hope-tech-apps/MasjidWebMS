# Family ledger (tuition): the rules

DECISIONS.md 2026-09-30, "Tuition autopay and the family ledger". **Design rules: until slice 1A lands, no code implements this.** Each rule names the guard that will enforce it, and each money guard must have a named test that fails when the guard is removed.

## What the ledger is

- **The school's own account of what a family owes.** Manara never holds money. A ledger row never causes money to move, and nothing posts on a clock. The ledger holds three kinds of rows:
  - charges the school expects;
  - money the office received outside Stripe;
  - Stripe payments the webhook already recorded, mirrored from `registration_payments`.
- **Append-only.** Entries are never updated or deleted, and a correction is a reversal with a required reason. The one sanctioned exception is `FamilyLedger::carryOnMerge`, which re-points contact ids in a contact merge. There is no MySQL trigger: an absolute delete refusal once blocked account erasure.
- **One writer.** `App\Services\FamilyLedger\FamilyLedger` is the only class that creates entries.
- **Integer minor units, signed**, with a `currency` on every entry and account. Amounts arrive as strings and are parsed once by `Money::toMinor`.
- **Idempotency.** `(masjid_id, idempotency_key)` is unique. The keys:

| Row | Key |
|---|---|
| charge | `charge:{account}:{child}:{period}:{category}` (autopay, import, staff and slice 7 all use it) |
| projected Stripe payment | `rp:{registration_payment_id}:{line}` |
| non-card discount | `ncd:{charge_entry_id}:{payment_entry_id}` |
| Stripe refund | `stripe:chg:{charge_id}:refunded:{cumulative_minor}` |
| lost dispute | `stripe:dsp:{dispute_id}:lost` |

## Stripe state and staff entries

- **Stripe state comes only from the webhook projection.** That means `paid_via = stripe`, the `stripe_*` columns and `source = stripe_webhook`. A card payment taken by hand in the school's own dashboard is `card_offsite` and fills no `stripe_*` column.
- **A staff entry never doubles a projected payment.**
  - A `card_offsite` or imported row whose reference is a Stripe id matching a projected payment is refused.
  - The same child and amount within five days of a projected payment needs an acknowledgement.
  - In the other order, the projection still posts and flags "possible double record".
  - An unassigned Stripe payment is resolved by reassigning the projection, never by hand-recording it.
- **Staff cannot reverse a projected row.** Refunds and disputes of Stripe payments are the webhook's to record. Staff record refunds of money that came outside Stripe.
- **Money-posting paths fail loudly.** Any path that posts to the ledger, webhook paths included, checks its tables with `existsOrFail` and answers non-2xx on failure, so Stripe redelivers. Only "is this event mine?" checks use `has()`. Tables ship in an earlier deploy than the code that writes them.

## The non-card discount (no card surcharge)

- Manara never adds a fee to a card payment.
- A school may discount bank transfer, cash, check or Zelle. The rate, cap, methods and charge categories are settings in `organisation_billing_settings`, never in code.
- **Offline payments.** The credit is computed from the amount received against a charge, rounded down to the cent, and capped so the discounts on one charge never exceed the discount on the whole charge. It is keyed per (charge, payment) and never typed. It is refused on a charge covered by live autopay.
- **Autopay.** The discount is a separately priced bank-debit plan the family chooses before checkout.

## Who may see an account (custody)

- Members are staff-confirmed and never inferred. Only a confirmed, live row counts.
- **`BillingMemberAuthority`** is the one query for "may this person read the account" (the family Account screen) and "is this person sent its statements". It reads the edge table directly and never calls `GroupAudience`. A billing member qualifies only with one of:
  - a confirmed guardian edge to **every** child member of the account, whatever its `left_on` (a withdrawn family still owes);
  - `payer_only`, which records who set it, when and why.
- **Custody.** The custody procedure is deleting the guardian edge. That cuts the account on the next request with no second step.
  - The guardian-row delete names the billing memberships that went dark.
  - `family-accounts:audit` lists members who no longer qualify, including *partial* members (edged to some children but not all). Partial members see nothing until the office splits the account or sets `payer_only`.
- **Statements.** One message per recipient. The address is the member's live family-login address first, else `contacts.email`, and every preview shows each address.
  - A statement never prints another billing member's name.
  - In a `split_household` account each recipient sees their own payments in full and the other household's only as a total.
- **Merges never launder authority.** Children and entries follow the survivor. A billing member follows as-is only when `ContactIdentity::changed()` is false. Otherwise the source row ends and the survivor gets a *pending* row that grants nothing until someone holding both `manage donations` and `manage contacts` confirms it.

## Erasure, deletion, scrub

- **Office records.**
  - Every ledger table with a contact column is in `MemberAccountDeletion::OFFICE_RECORDS`, the merge carry and `WixContactImport::heldBy`.
  - `restrictOnDelete` foreign keys to contacts make a forgotten carry fail loudly.
  - Contact force-delete sites delete ledger rows first only where the whole tenant is going (the demo seeder's rollback).
- **Organisation hard-delete refuses** (`LedgerStillPresent`) while ledger rows exist. `family-accounts:purge {org}` exports the ledger, requires the typed organisation name, and runs the checks, the purge and the delete in one transaction.
- **Backups before real data.** The ledger is the only record of cash, check and Zelle receipts, so no organisation gets the `family_accounts` grant with real data until off-site backups are on and a restore drill has passed.
- **Staging scrub.** Staging scrubs `notes`, `reference`, `recorded_by_label`, `payer_only_reason`, `ended_reason` and `stripe_*`, and keeps amounts.
