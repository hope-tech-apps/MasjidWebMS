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

> **Before debugging "the offering 404s / the admin gets 403": check
> `masjids.crm_enabled`.** It defaults false, provisioning never sets it, and a
> SuperAdmin-only toggle is the only way to flip it — so a fully-configured
> school is invisible on both the public and the admin side until somebody does.
> The whole go-live note is in `.claude/rules/verticals.md`
> ("GO-LIVE: `crm_enabled` is NOT part of provisioning"); it lives there rather
> than being restated here, because a rule copied into two files is how the two
> come to disagree.

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
- **`settle()` NEVER THROWS, and since 2026-08-13 that is true by construction
  rather than by assertion.** It said so here while `confirm()` →
  `writeRosterMemberships()` threw `rosterMisconfigured()` whenever
  `offerings.group_id` did not resolve for the tenant — inside `settle()`'s own
  `DB::transaction`, so the throw rolled back the `registration_payments` row and
  `payment_status = paid` with it and answered `StripeWebhookController::handle`
  500. Measured with the roster group soft-deleted by one unguarded admin click:
  three Stripe retries, three 500s, ledger empty, `pending`/`awaiting` intact,
  and forty-six minutes later the reaper cancelled a seat the family had paid
  $150 for — with no local trace of the charge for anyone to refund from.
  **A ROSTER IS A MATERIALISATION, NOT THE REGISTRATION**: `offerings.group_id`
  is nullable and an offering without one has always registered families, so an
  unresolvable group is now the same fact — logged loudly with the offering, the
  group id and the registration, and skipped. A group belonging to ANOTHER
  organisation is still never written into; skipping is what protects the other
  tenant, and throwing was only the delivery mechanism. Nothing else is
  swallowed: a deadlock, a constraint violation or any other transient database
  failure still propagates, still 500s, and Stripe still retries it — which is
  correct, because those resolve on retry and a misconfigured roster does not.
  **Do not reinstate `RegistrationException::rosterMisconfigured()`**; the
  refusal belongs at `AdminDashboard\GroupsController::destroy`, before any money
  exists (see T-006g below).
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
- **`checkout()` re-checks the clauses about the PROGRAM and none of the clauses
  about a NEW registration.** Re-checked: `is_active`, the intake form still
  resolving (through `OfferingRegistrationState::intakeFormExists`), and the
  organisation still being able to collect. NOT re-checked: which plans are
  purchasable, capacity, and **the `opens_at`/`closes_at` window** — this
  registration already chose its plan and holds its seat, and it is charged from
  `adjusted_total_minor`, never from the plan. All three halves were got wrong
  once, in both directions. Re-checking `! $feePlan->is_active` broke the
  seasonal price rise outright (deactivate-and-replace is the ONLY way to change
  a price, so every in-flight family's checkout link 422'd the moment the
  registrar deactivated the old plan). Not re-checking the intake form left the
  door shut and the till open: every read surface said `closed / no_intake_form`
  while checkout minted a live Session for $150. And re-checking the whole of
  `is_open` — which is `is_active AND isWithinWindow()` — applied the SIGN-UP
  DEADLINE to somebody who had already signed up: registration closes at
  midnight, she registers at 11:52pm on a 30-minute hold, comes back with her
  card at 12:05am, 422. `RegistrationService::promoteFromWaitlist()` already
  states the rule one file over — the window "governs PUBLIC intake … refusing it
  after the window closed would strand the waitlist" — and checkout strands the
  same people for the same reason. The hold has its own bound
  (`checkout_expires_at`, refused outright once past), so dropping the window
  leaves nothing unbounded.
- **Public pricing is server-side, always.** `quote` writes nothing; a
  client-supplied `code` is reported back as `code_applied: false` and can never
  move a price — aid is an admin grant (T-006d). The payer's email is required
  because THE PAYER is keyed on (masjid, email): they are asserting their own
  address, so find-or-create on it is what makes a returning family attach to
  their own record instead of spawning a second one.
- **WHAT THIS ENDPOINT WRITES TO A ROSTER IS A CLAIM, NEVER A GRANT**
  (2026-08-13). A registrant IS matched to a pre-existing contact — on address
  AND name, so two siblings on one household mailbox stay two people — and so is
  the payer, on address alone. What contains the anonymous caller is not which
  row they reach but `group_memberships.provenance`: every row
  `RegistrationService::writeRosterMemberships()` creates is `self_asserted`,
  which lists a person and opens nothing until an authenticated staff act
  confirms it. A contact this endpoint CREATES never carries an address another
  contact already holds, and stores it lower-cased.

  This REPLACES a rule that stood here for part of one day — "a registrant is
  never matched to a pre-existing contact". It enumerated doors while the `payer`
  field, the same writer one field away, stayed unguarded; and it 403'd a teacher
  out of her own classroom, forked a returning child into N people on one roster,
  and named the merge verb as the reconciliation. Full argument, the measured
  anonymous-guardianship exploit and the merge chain: `.claude/rules/groups.md`,
  "PROVENANCE — a guardian edge records ON WHOSE AUTHORITY it exists".
- **A PREVIEW IS NEVER STRICTER THAN THE WRITE IT PREVIEWS, and WHICH write it
  previews depends on whether it names a registration.** With no
  `registration_uuid`, `quote` previews `register()` and applies the identical
  two calls in the identical order (`findFeePlan()`, then the offering-level
  verdict). WITH one, it previews `checkout()` — so it resolves that
  registration's OWN plan by OWNERSHIP ONLY, reports that plan's id/kind beside
  its own snapshot, and applies no offering-level verdict at all.
  `fee_plan_id` is therefore `required_without:registration_uuid`, not
  `required`. Before 2026-08-13 it was `required` and resolved through
  `findFeePlan()` (which gates on `isPurchasable()`, including `is_active`)
  BEFORE the uuid branch, which made the preview strictly stricter than the write
  the moment round two correctly removed `is_active` from the checkout side:
  measured on one fixture at one instant, checkout answered 200 charging the
  15000 snapshot while quote on the registration's OWN plan id answered 404, and
  quote on the *replacement* plan's id answered 200 reporting THAT plan's
  `fee_plan_id` and `kind: recurring` beside a one-time $150 snapshot. A client
  may still send a `fee_plan_id` alongside a uuid; the registration's own plan
  wins, because a client can name a plan but never a price and never which plan
  a registration is on.

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
- **THE FREE-PATH CARVE-OUT IS DECIDED ON THE CHARGE, NOT ON THE TOTAL.**
  `grantAdjustment()` and `promoteFromWaitlist()` branch on
  `perChargeMinor() <= 0`, not on `adjusted_total_minor === 0`. The two are the
  same test for one_time and recurring and differ for installments, where the
  charge reaches zero as soon as the TOTAL drops below the COUNT — the rounding
  rule above at its limit, with the whole residue dropped in the payer's favour.
  Measured before the fix, 9 × $100.00 with `grantAdjustment(aid, 89995)`:
  adjusted 5, the admin sees success, `perChargeMinor` = `intdiv(5, 9)` = 0,
  checkout 422 `nothingToCharge`, seat still held with `checkout_expires_at` set,
  reaped 46 minutes later. A registrar granting near-total aid ejected the family
  she was helping. `adjusted_total_minor` is deliberately NOT rewritten to 0 —
  it is derived from the audit trail (`adjusted = list − Σ adjustments`) and the
  money state `none` is what says nothing is being collected.
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
  switches on, not `is_open`** — and `App\Support\OfferingRegistrationState` is
  the ONE place it is decided, for the public payload and for every admin
  surface alike. Full is NOT closed — `register()` waitlists rather than
  refusing. Everything else that the write path refuses on IS closed, even
  where `is_open` reports true and `closed_reason` reports null:
  - the intake form has been soft-deleted (`register()` throws
    `offeringClosed()` when it cannot load it) — reason `no_intake_form`;
  - there is no PURCHASABLE fee plan, so nothing can go in `register`'s
    `fee_plan_id` — reason `no_fee_plan`. **A FREE offering still needs a `free`
    plan.** This clause was missing until 2026-08-12: `state()` checked the
    window and the form only, so an active offering with a form and zero plans
    published `registration_state: "open"` beside `fee_plans: []`, and the parent
    who filled the form in got a 404 "This fee plan is not available." from
    `quote`. This paragraph claimed the field "accounts for everything the write
    path checks" while it did not — the code is now what the sentence says.
  - every purchasable plan raises a charge and the organisation has not finished
    Connect onboarding — reason `org_cannot_collect`. An offering that also has a
    free tier stays `open` and the free tier goes on registering; only the
    CHARGEABLE plans are withheld.
  - `no_fee_plan` outranks `waitlist`: `findFeePlan()` refuses before capacity is
    ever consulted, so "full" would be the wrong story.

  **The plan clause is PER PLAN. `OfferingRegistrationState::isPurchasable()` is
  the whole of it and the offering-level verdict is only an aggregate of it.**
  Four callers, one function: `decide()`, `OfferingPublicPayload::feePlans()`,
  `OfferingRegistrationsController::findFeePlan()` and the admin list's
  `registrationState()`. Do not restate any of its clauses as `where` conditions
  or as an inline `filter()` — every version of this defect has been a copy of
  the predicate that was a clause behind. A plan it refuses is withheld from the
  page AND is the same 404 as a plan that never existed; withholding and refusing
  are one fact. Its clauses are `is_active`, `kind ∈ KINDS`, `billing_interval ∈
  BILLING_INTERVALS` for the subscription kinds, `installment_count >= 1` for
  installments (>= 1 and NOT the request's create-time `min:2` — a predicate
  stricter than the write it gates is its own defect), **`amount_minor > 0` for
  every kind that is not `free`**, and, for plans whose `listTotalFor()` is > 0,
  that the organisation can collect.

  The zero-charge clause was added 2026-08-13, on the same evidence and in the
  same reachability class as the three before it. `StoreFeePlanRequest` states it
  in words at create time — "A paid plan must charge more than zero; a $0 charge
  is the free plan instead." — and nothing enforced it on a row that arrived by
  import, seeder or manual edit. Measured with `one_time`, `recurring` and
  `installment` each at `amount_minor 0`: page `open`, plan published at "$0.00",
  quote 200, and **`register` CONFIRMED THE SEAT FREE** — `listTotalFor()`
  returns 0 for them, so intake took the free-path carve-out and gave the place
  away. It is spelled against the KIND rather than as `listTotalFor($plan) > 0`
  because `free` must stay exempt: a `free` plan carrying a stale non-zero
  `amount_minor` is a real chargeless registration path and stays purchasable
  (the `listTotalFor` lookalike would have closed it, as the note above already
  warned). `OfferingPublicPayload` publishes a free plan's `amount_minor` as 0
  rather than its column, so no price travels on that payload that would not be
  charged.

  `is_open` / `closed_reason` are still reported verbatim from the model
  accessors — they answer "is the window open", which is a narrower question.
  `registration_state_reason`
  (`inactive|not_yet_open|closed|no_intake_form|no_fee_plan|org_cannot_collect`,
  pinned as `OfferingRegistrationState::REASONS`) is served beside the verdict,
  decided by the same call, and is what a renderer explains itself from. Adding a
  member means adding it to `REASONS`, to `OfferingRegistrationStateReason` in
  `Offering.ts`, and to the three maps plus `registrationStateIsFault` in
  `useOfferingDisplay.ts` — `org_cannot_collect` shipped in none of them and
  rendered as a deliberate grey "Closed". The admin `offerings` index / show /
  options payloads carry the identical pair, so no admin screen can show a green
  "Open" for a program nothing can register for.

- **A soft-deleted MASJID takes nothing.** Hand-filtering `masjid_id` proves a
  row belongs to the id in the `masjid-id` header; it never asks whether that id
  still names an organisation, and `masjids` soft-deletes. Before 2026-08-12,
  `$masjidA->delete()` left `GET /offerings/{slug}`, `POST …/quote` and
  `POST …/register` all answering 200 — a confirmed registration, its form
  response and an incremented seat counter, written for an offboarded
  organisation. With a priced plan the Stripe leg refused one layer down
  (`RegistrationCheckoutService` resolves the org with `Masjid::find()`, which
  excludes trashed rows), so no Session opened and the family got a phantom
  pending seat: one guard in the money layer doing work that belonged at the
  boundary. Every public path now goes through
  `App\Support\PublicTenant::exists()` (`OfferingPublicPayload::forSlug/forId`,
  `OfferingRegistrationsController::resolveTenant`, and the sibling public
  endpoints — see `PublicTenantLifecycleTest`, which walks every `/api/v1` route
  against a deleted organisation).

- **An offering's intake form cannot be deleted out from under it.**
  `AdminDashboard\FormsController::destroy` refuses with a 422 while the form is
  any live offering's `intake_form_id`, naming them. `offerings.intake_form_id`
  is NOT NULL and there is no "no intake form" state to fall into, so the delete
  used to leave a required reference dangling and silently close the program
  while every admin screen went on reporting it open. The non-destructive paths
  are `is_active = false` on the form (which does NOT break offering
  registration — `register()` never consults it) or re-pointing the offering
  first. The `no_intake_form` state above still exists for rows broken before
  the guard.
- **Nor can its ROSTER GROUP be deleted out from under it.**
  `AdminDashboard\GroupsController::destroy` refuses with a 422 while the group is
  any live offering's `group_id`, naming them — the third and last reference into
  these tables to be guarded, written to match its two siblings
  (`FormsController::destroy`, `OfferingsController::destroy`). It was the
  unguarded one and it is the one that takes money: `offerings.group_id` is
  nullable with `nullOnDelete()`, but `groups` SOFT-deletes, so the FK never fires
  and the pointer is simply left dangling. Measured before the guard — register
  200 with a live checkout URL, one click deletes the classroom, then
  `checkout.session.completed` ×3 → 500, 500, 500, ledger empty, and the reaper
  cancelled the paid seat 46 minutes later. The recovery it points at is SOFTER
  than the intake form's, because a roster is optional where a form is not: detach
  the offering (`group_id` is nullable and registrations then confirm with no
  roster), re-point it at the replacement classroom, or set `is_active = false` on
  the group. Soft-deleted offerings do not block. The guard is not the only
  defence and is not meant to be — `writeRosterMemberships()` skipping an
  unresolvable group is what keeps rows broken before it from costing a family her
  money, exactly as `no_intake_form` still exists behind the FormsController
  guard.
- **Seats are published as `remaining` + `is_full`, never as `capacity` or
  `registration_count`.** `remaining` is a property of the OFFERING (how many
  more places it will accept); `registration_count` is a count of PEOPLE, and a
  public page is never a window onto the CRM. Publishing capacity as well would
  hand out the subtraction, which is the same number by another name.
- **The only internal id in the public payload is `fee_plans[].id`**, because
  `POST offerings/{slug}/register` takes `fee_plan_id`. Not the offering id, not
  `masjid_id`, not the intake form's id or slug — the public API is addressed by
  slug and uuid.
- **Fee plans are filtered by `OfferingRegistrationState::isPurchasable()`** (see
  the clause list above), never by a predicate spelled out here. An unrecognized
  money kind is withheld rather than guessed at: `listTotalFor()` throws on one,
  and `FeePlan` has no degrade helper on purpose. Withholding is the fail-closed
  answer; crashing the public page is not, and guessing would waive or misquote a
  fee. **THE "NEVER PUBLISHED" CLAIM IS PER PLAN, and it is written per plan
  because the offering-level version of it is false.** A plan is published if and
  only if `isPurchasable()` accepts it, so the two verdicts that mean "no plan
  survives that predicate" — `no_fee_plan` and `org_cannot_collect` — are ALWAYS
  served beside `fee_plans: []`, being computed from the same call over the same
  rows at the same instant. (The payload published `amount_minor: 15000` beside
  `registration_state: closed / org_cannot_collect` until 2026-08-13, because the
  verdict and the plan list were filtered by two different rules; that is fixed.)
  What must NOT be written here or in `OfferingPublicPayload` is the broader
  sentence it used to carry — "a price is never published for a plan this same
  payload's `registration_state` declares unbuyable". Measured false for three
  states: `not_yet_open`, a window that has merely `closed`, and `no_intake_form`
  all serve `registration_state: closed` WITH the plans populated at 15000, and
  that behaviour is right — a page that says "opens 4 September, $150" needs the
  price to say it with, and those three are facts about the OFFERING rather than
  about any plan.
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
