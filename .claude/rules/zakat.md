---
paths:
  - "app/Support/ZakatDesignation.php"
  - "app/Support/ZakatCalculator.php"
  - "app/Http/Controllers/Api/V1/ZakatCalculatorController.php"
  - "config/zakat.php"
---
# Zakat (T-031)

Two separate things share a name here, and conflating them is the mistake this
rule exists to prevent:

1. **Designation** — the restriction a giver placed on one gift
   (`donations.is_zakat`). An accounting fact.
2. **The calculator** — a public arithmetic aid that tells a donor what 2.5% of
   their net wealth is. A fiqh-adjacent tool.

## Designation is per-GIFT, never derived from the fund

`funds.type = 'zakat'` describes the ORG's bucket. `donations.is_zakat`
describes the GIVER's restriction on one gift. Both directions of the mismatch
are real and both matter:

- zakat given to a **general** fund — the common case, because most small
  masjids run one fund and the donor still says "this is my zakat". Deriving
  from the fund would record restricted money as unrestricted and the org would
  spend it on the electricity bill.
- a **non-zakat** gift into a zakat-typed fund — sadaqah toward a relief appeal,
  or an imported ledger row that landed there by name. Counting it overstates
  the restricted pot the org owes its recipients.

So: **never compute a zakat figure from `funds.type`.** `App\Support\ZakatDesignation`
is the one place the rule lives; the checkout path, the admin offline path and
the reporting layer all go through it.

- The giver's answer wins, including an explicit "no" into a zakat fund.
- The fund's type is only the **default**, used when nobody said.
- `zakat_source` records which of the two produced the answer (`donor`,
  `fund_default`, `admin`) and is non-null ONLY when the gift is zakat — there
  is nothing to attribute about a gift carrying no restriction.
- The designation is stamped on the **pending** row before the Stripe redirect,
  so it is already present on the row the webhook advances. It never depends on
  the browser coming back (.claude/rules/stripe-payments.md).
- A recurring commitment is designated **once**, at checkout; every invoice it
  books copies the commitment's value rather than re-deriving it from a fund
  whose type an admin may since have edited. Inbound metadata never decides it.

### Stripe metadata is positive-only

The `zakat` / `zakat_source` metadata keys are attached **only when the gift is
zakat**, the same discipline `application_fee_amount` follows. A non-zakat gift's
Checkout Session parameters are therefore byte-identical to what they were before
T-031, which `ZakatDesignationTest` pins and `DonationFlowTest` keeps honest.
The metadata is a convenience for the org's own reconciliation in the Stripe
dashboard; **our row is the record of record.**

### Reporting is additive and carries its definition

`DonationMetrics` reports zakat as conditional aggregates over the SAME scan as
the totals beside them, so the subset can never describe a different window. No
pre-existing response key changed meaning. `meta.zakat.definition` ships the
definition with the number, the T-024 discipline (.claude/rules/impact-metrics.md):
a zakat total an org shows its donors is worthless if the reader has to guess
whether it means "gifts to the zakat fund".

## The calculator must not make a fiqh ruling in silence

Every step of a zakat calculation rests on a position some qualified scholar
disputes. A tool that picks one and prints a dollar figure has issued a ruling
while looking like arithmetic. Therefore:

- **Every disputable choice is a NAMED ASSUMPTION in the response payload**, not
  only a code comment. `ZakatCalculator::assumptions()` is part of the contract,
  not decoration. Adding a computation step means adding its assumption.
- **Where the caller can reasonably decide, they decide.** The nisab basis is
  per request; the liabilities deducted are exactly what was entered.
- **Where a fact is unknown, say unknown.** With no metal price there is no
  threshold, so `meets_nisab` and `zakat_due_minor` are **null** — never `false`
  ("under the threshold") and never `0` ("you owe nothing"). Never ship a
  hardcoded metal price: a stale threshold tells a payer they owe nothing when
  they do.
- **The calculator values nothing for the caller.** Holdings are entered as
  money the payer determined. Pricing someone's jewelry would embed both a
  market quote and a contested position (is customary jewelry zakatable?) inside
  a number that looked derived.

### The positions currently taken, and where they are documented

| Assumption | Default | Documented in |
|---|---|---|
| Nisab basis | **silver** (Hanafi takes the lower; contemporary practice often follows it as the more cautious/beneficial choice — gold basis is a live alternative) | `config/zakat.php`, `assumptions.nisab_basis` |
| Nisab weights | gold 87.48 g (20 mithqāl), silver 612.36 g (200 dirhams); 85 g / 595 g are the common alternatives | `config/zakat.php`, `assumptions.nisab_weights` |
| Rate | 1/40 on monetary wealth only — not produce (5%/10%) or livestock | `config/zakat.php`, `assumptions.rate_scope` |
| Hawl | NOT verified; the caller is assumed to be at their due date | `assumptions.hawl_not_verified` |
| Threshold compared to | **net** (after liabilities); some compare gross | `assumptions.nisab_compared_to_net` |
| Deductible debts | exactly what was entered; no judgment made | `assumptions.liabilities_as_entered` |
| Jewelry | caller's choice; Hanafi includes it, majority exempt customary women's jewelry | `assumptions.holdings_valued_by_you` |
| Rounding | **up** to the next minor unit, so no shortfall | `assumptions.rounding_up` |

Money is integer minor units end to end and the rate is applied as the integer
fraction 1/40 — never the float `0.025`.

## The endpoint writes nothing, and logs nothing

`POST /api/v1/zakat/calculate` is a POST despite being a pure read: its body is
a person's complete net worth, and a GET would put that in a query string and
from there into access logs and browser history. Nothing is persisted and the
figures must never reach `Log::*` on any path. `GET /api/v1/zakat/nisab` is an
ordinary GET because it carries nothing personal.

Tenancy follows the `/api/v1` idiom exactly: `masjid-id` header, same 404 for a
missing target as a bogus one, throttled by name (`zakat-calculator`, keyed by
IP **and** organization).

## Out of scope (deliberately)

Zakat **eligibility case management** — the aid-request workflow that decides
which recipients qualify under the eight categories of Qur'an 9:60 — is a
separate slice. Nothing here rules on whether a gift discharges the giver's
obligation or on recipient eligibility, and nothing here should grow to.
