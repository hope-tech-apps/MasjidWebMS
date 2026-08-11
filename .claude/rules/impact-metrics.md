---
paths:
  - "app/Support/ImpactMetrics.php"
  - "app/Http/Controllers/AdminDashboard/ImpactMetricsController.php"
---
# Impact metrics (T-024)

`App\Support\ImpactMetrics` computes an organization's REAL figures for a date
range from the rows Manara already holds — appointment intake, credentialed
volunteers, giving, form submissions, group/program participation,
registrations — so an admin filling in a grant application or a funder report
can look one up instead of hand-assembling it from spreadsheets.

`GET /api/admin/masjids/{masjid_id}/impact/report?from=&to=` is a thin wrapper,
the same arrangement as `DonationStatsController` over `DonationMetrics`.

## The T-020 boundary is the point of this module

`SectionType::IMPACT_STATS` is a PAGE-BUILDER section whose `stats[].value` is
**display text an admin typed** ("6,000+", "$6.3M"). It stays authoritative for
what is PUBLISHED, and it stays author-supplied (`usesExternalData() === false`).

- **Nothing computed here may write to a page section.** There is no write path
  from `ImpactMetrics` to `sections`, and adding one — even "just to prefill" —
  would mean a published, audited funder figure changing because a row was
  inserted this morning. The published number and the live number answer
  different questions on purpose.
- The only bridge is a HUMAN one: every metric carries a `formatted` string an
  admin may choose to copy across. Copying is an editorial act and stays one.
- Do not flip `usesExternalData()` for `impact_stats`. If aggregation on a page
  is ever genuinely wanted it is a NEW, separately classified section type —
  the enum already says so.

## A metric definition is the deliverable, not the number

Every metric carries `provenance.source` (the tables) and
`provenance.definition` (what exactly was counted). An ambiguous definition is
how an impact report becomes wrong, so a definition must state:

- the population and the column the window was applied to;
- whether it counts ROWS or PEOPLE, and what makes two rows one person;
- what the data CANNOT support. "Scheduled" means an appointment was booked,
  not attended; "credentialed volunteers" is people who are credentialed, not
  hours served. Say so in the definition rather than letting a reader assume.

`basis` is part of that contract, and the three values are genuinely different:

- `period` — a FLOW inside [from, to].
- `as_of` — a STOCK evaluated against a date, using dates the row really stores
  (a credential's `expires_at`), so it is recomputable for a past date.
- `current` — a stock read off a flag the platform keeps NO history for
  (`groups.is_active`). It describes today even when the caller asked about
  2019, and the payload says so.

Each metric's `period` block reports the window IT covers, not the one that was
asked for. `as_of` is clamped to today: evaluating credentials against a date
that has not happened would report a licence expiring in October as lapsed.

**Machine keys are append-only.** A filed report refers back to them.

## Only real sources

A metric with no data behind it does not get stubbed, defaulted or estimated.
Nothing here counts volunteer hours, patient visits, attendance or a dollar
value of services donated — the platform stores none of them. If a funder asks
for one of those, the answer is that the data does not exist yet, not a number
assembled from a proxy.

## Vertical-aware selection is OR, never a hardcoded vertical

A metric is included when it is in the vertical's default set **or** the tenant
actually has data for it. Excluded metrics are named in `meta.omitted` with a
reason, so a reader can tell "not asked" from "the answer was zero".

The default sets live on each metric's `verticals` list next to its definition,
not in `config/verticals.php` — so adding a vertical needs no change here, and
a metric's meaning and its audience stay in one place. Read the tenant's
vertical through `Masjid::orgType()`; never hardcode a vertical's shape (there
is no "clinic" branch anywhere in this module), and never hardcode a vertical's
nouns — labels come from `$masjid->term()`.

## Tenancy, money, permissions

- **Every aggregate runs inside `withTenant()`**, which binds `TenantContext` to
  the masjid being reported on and restores the previous binding after. A report
  is the one payload where an unbound caller summing the whole fleet under one
  organization's letterhead is a fabricated document, so the ambient binding is
  not relied on alone. A DIFFERENT tenant already bound throws.
- `form_responses` carries no `BelongsToMasjid` trait, so it is the one source
  filtered by hand. Every other source is a trait model.
- Raw joins see no global scopes: they add `deleted_at IS NULL` by hand, because
  a soft-deleted person must not sit in a funder count. They are NOT scoping
  devices — the scoped base row already decides the tenant.
- **Money is integer minor units end to end**; `formatted` is the only division.
  Donation figures come from `DonationMetrics`, never a second SUM, so the
  report and the giving dashboard cannot disagree.
- The route is gated `permission:view contacts`; MONEY-bearing metrics
  additionally require `view donations`, checked in the controller, and are
  named in `meta.omitted` when the caller lacks it. Reuse of the existing
  families is deliberate — minting `view impact` would break the pinned
  `Permission::count() === 8` (`RolePermissionBridgeTest`).

## Not built (and why)

Export (PDF/CSV) is deliberately out of scope. It would need a document
template plus a period-and-provenance header — every figure's definition has to
travel with it or the export is worth less than the JSON — and it should reuse
`App\Services\Receipts`' PDF path rather than growing a second one. Snapshots /
a metrics cache table are also out: the figures are cheap (about nine grouped
queries) and a cache would introduce a stale number with nothing responsible
for refreshing it, which is exactly the failure `ContactCredential`'s derived
status was designed to avoid.
