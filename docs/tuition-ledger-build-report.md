# Tuition ledger build report

## Walk correction round (base b31b1c4d)

All eight walk findings are fixed on `feature/tuition-ledger`, uncommitted. HEAD
remains `b31b1c4dfd164ed23c785a1bbbc69d77987fb87b`; the owner's refund lookup fix is
preserved. The current contract is `tuition-ledger.md`.

1. Public Description and private Note on entry/void forms, explicitly labelled
   Families see this / Staff only. Existing `reason` storage stays private, including
   old void reasons. A new reversible nullable-description migration uses safe
   plan/kind defaults for old rows; staging scrub covers descriptions too. Family
   JSON, mounted page, rendered HTML, decoded downloaded/attached PDF streams and
   rendered email all have note-exclusion assertions.
2. Reminders use was due / is due, currency symbols and currency decimal scales,
   readable calendar dates, current school-local balance and the family tuition link.
3. Family PDFs show guardian names, school letterhead/contact, readable dates,
   descriptions and friendly entry/method labels. Internal entry IDs are absent;
   school contact details end the statement. Office CSV retains audit IDs.
4. Recorded by and email-log times are formatted server-side in the school's timezone,
   following LetterGrades. Office entry date also initializes from school-local today.
5. Family balance and Checkout payable ceiling end today; coming debt is separate.
   From defaults to the current dated school year's start, then account's first line,
   then today for an empty account. Checkout retains the full reconciliation snapshot,
   so an existing future charge does not create a false review flag on payment.
6. Open account, Add entry and Void show Saved. Email statement requires a confirmation
   with the eligible recipients' count; tests cover cancel and confirm.
7. CSV amounts/opening/closing balances use exact currency units and a currency column,
   with guardian family names and assigned child names. Formula guards protect text
   while negative numeric amounts remain usable numbers.
8. Method keys display as Cash App, Zelle, Check, Bank transfer and other explicit
   labels. Five new PHP regression cases and two new mounted SPA cases cover the walk.

### Verified totals

| Run | Result | Evidence |
|---|---|---|
| Final requested targeted PHP gates, including audit follow-up | 77 passed, 1446 assertions, 7.00 s | `/tmp/tuition-walk-final-gates.log` |
| TuitionLedgerTest, included above | 39 passed | same log |
| Frozen contracts, included above | App101OffContractTest 1; SchoolCalendarOffQueryDifferentialTest 13; ClassSubjectReportSummaryTest 20; all 34 passed | same log |
| Required StagingScrubCoverageTest, included above | 4 passed | same log |
| SPA tuition | 7 passed, 0 failed, 472.535875 ms | `/tmp/tuition-walk-spa.log` |
| Related family language checks | 8 passed, 0 failed, 458.551042 ms | `/tmp/tuition-walk-languages.log` |
| ENTIRE default PHP suite ONCE, exit 2 | 11257 passed, 7 skipped, 20 failed; 184341 assertions; 785.18 s | `/tmp/tuition-walk-full.log` |

The full run started 2026-10-15 01:35 UTC, within the stated 00:00–04:00 UTC
criteria window. The full suite was not repeated. The final targeted rerun covers
the audit follow-up for reconciliation snapshots and stronger decoded-PDF assertions.

### Nine known date failures, separately named

All are in `ReportCardCriteriaTest`, which this fix round does not modify:

- `a_late_started_prior_term_card_uses_its_period_after_a_draft_applies`
- `two_applied_drafts_keep_late_q1_and_q2_cards_and_full_band_versions_recoverable`
- `without_dated_terms_late_cards_use_their_own_school_year_including_a_named_year`
- `the_august_rollover_without_calendar_years_keeps_prior_year_criteria`
- `period_versions_cannot_be_read_or_overwritten_through_another_tenant`
- `sending_freezes_band_even_after_takeback_and_draft_applies_on_next_term_start`
- `without_dated_terms_freeze_is_for_the_school_year`
- `only_the_band_used_by_a_sent_card_freezes_and_unrelated_current_edits_survive_a_draft`
- `deferred_criteria_follow_a_rescheduled_term_and_do_not_apply_on_the_old_start`

### Eleven expected environment failures

Socket refusal (`stream_socket_server`, Operation not permitted):

- ResendTransportTimeoutTest: `a_resend_call_that_is_never_answered_gives_up_instead_of_waiting_for_ever`
- ResendTransportTimeoutTest: `a_silent_resend_still_saves_a_portal_password_and_answers_ok`
- ResendTransportTimeoutTest: `a_silent_resend_still_resets_an_app_password_and_signs_the_member_in`
- ResendTransportTimeoutTest: `the_shipped_limits_are_set_and_well_below_nginxs_sixty_seconds`
- TenancyCanaryTest: `an_origin_that_redirects_every_probe_is_not_a_clean_run`
- TenancyCanaryTest: `an_origin_that_404s_every_probe_is_not_a_clean_run`
- TenancyCanaryTest: `one_unreachable_endpoint_is_not_the_same_alarm_as_a_dead_origin`

Archive counts:

- BackupRunTest: `an_archive_whose_headers_are_directories_is_still_a_short_archive`
- BackupRunTest: `an_archive_that_really_holds_the_files_still_passes`

Missing `public/build/manifest.json`:

- ExampleTest: `it_returns_a_successful_response`
- HostHeaderUrlIntegrityTest: `the_test_harness_really_does_forge_the_host`

**No other failures.**

### Inspection, cleanup and limits

Actual downloaded and emailed PDFs were generated and extracted with pdftotext;
public descriptions/contact/dates are present and private-note sentinels/internal
entry wording are absent. The downloaded statement was rendered to PNG and visually
inspected. No real browser walk was repeated. Mail and Stripe are faked; MySQL,
real delivery/provider behaviour, staging/production and fluent non-English copy
remain unverified. No build, commit, network, deploy, switch-on, real send, formatter,
fixture recapture, dependency/lockfile or CHANGELOG change was made in this round.

Changed/new PHP syntax: 12 files passed. `git diff --check` passes; ordinary and
whitespace-ignored diff shortstats match. Removed 39 generated untracked artifacts;
restored the original subject-report query-count artifact byte for byte from
`/tmp/tuition-walk-original-query-counts.json`. Frozen fixtures and the three frozen
test files are unchanged. STATE.md and PLAN.md record completion.

## Original build (historical)

Implemented on `feature/tuition-ledger` from `05a3f9e3`, uncommitted and default off.
No school was enabled. No network, production, live Stripe, real mail, CAPTURE
variables, fixture recaptures, formatter, dependency changes or CHANGELOG edits.
The owner's required reconciliation was appended verbatim before code changes.

The implementation contract and owner walkthrough are in `tuition-ledger.md`.
September's tuition work was research/design, not an existing ledger. Existing
T-006 subscription billing remains untouched; custom tuition autopay and a separate
existing-account Connect flow are outside this item.

## What was built and reused

The ledger has charge, sibling_discount, credit, office_payment, card_payment,
refund and void lines. Entries are immutable, attributed, dated and signed integer
minor units. Balance is SUM of entries. Office actions use an action UUID so a
retry cannot record a receipt twice. A void references its original; voiding a
scheduled charge also reverses its attached discount. PDFs and CSVs expose entry
and original-entry IDs for correction tracing.

Sibling rule: eldest eligible child on the same account/plan/period pays full;
each younger eligible sibling gets floor(amount_minor × sibling_percent / 100)
as a separate discount line. Birth dates sort ascending; unknown dates last;
equal/missing dates use contact ID. No birth date or age is disclosed by billing.

Reused paths:

- `app/Models/Offering.php`, `FeePlan.php`, `Registration.php` and
  `RegistrationPayment.php`, plus `docs/t006-registration-billing-design.md`:
  existing engine and immutable FeePlan pricing. Schedules extend FeePlans;
  ledger plans stay inactive for registration intake to avoid double collection.
- `app/Services/Stripe/RegistrationCheckoutService.php`: existing hosted direct
  Checkout transport with the school's `stripe_account` header. No destination
  charge, pooled balance, raw card data or card surcharge.
- `app/Http/Controllers/StripeWebhookController.php` and `StripeWebhookEvent`:
  verified signatures and stripe_webhook_events delivery deduplication. Older
  money metadata keeps routing priority, including forms, cart and donations.
- `Masjid::canAcceptDonations()`: the existing Standard Connect collection gate.
  Disconnected schools can still use the ledger and office payments.
- `routes/family.php` parent guard and confirmed GroupMembership guardian edges:
  office account membership plus guardianship of every child, rechecked for
  reads and mail. Student/member tokens cannot open a money door.
- `app/Support/SchoolCalendarReaders.php`, `SchoolYear` and `SchoolTerm`:
  local due day, year boundaries and dated terms; stable term positions are period
  keys, so replacing a term row cannot charge its period again.
- `app/Support/ContactIdentity.php`, `SchoolMailTransport.php`, database queue,
  `app/Mail/AttendanceAbsenceMail.php` and its generic school notice templates:
  live canonical addresses, unsafe-transport guard and one external attempt.
  The queue contains IDs; PDFs are rendered after dequeue.
- `app/Services/Receipts/Letterhead.php`: existing receipt/statement branding.
  The school report-card mPDF pattern handles child names in Arabic/other scripts;
  tuition statements carry no charitable tax wording.

## Owner webhook step

Confirm/add these event types on the school's Connect webhook endpoint:

1. `checkout.session.completed`
2. `payment_intent.succeeded`
3. `charge.refunded`

Endpoint subscription changes are the owner's action. Nothing here assumes they
are configured. This path is card-only and needs no new async payment-method
subscriptions. A browser redirect only reads; payments and refunds are recorded
from verified events. Money in flight still records after switch-off or a changed
Connect link. Amount/balance mismatches record money received and flag the office.

## Verification

Runtime: PHP 8.3.35, Node v22.12.0; installed Laravel/Vite dependencies unchanged.

| Run | Exact result | Evidence |
|---|---|---|
| ENTIRE default PHP suite, once, exit 2 | 22 failed, 7 skipped, 11240 passed; 183853 assertions; 838.78 s | `/tmp/tuition-php-full.log` |
| Integration/scoping/retention/Studio/source contracts and legacy billing | 299 passed; 5407 assertions; 42.27 s | `/tmp/tuition-final-gates.log` |
| Final money/webhook/retention follow-up, including all 33 tuition cases | 127 passed; 1833 assertions; 9.21 s | `/tmp/tuition-final-money.log` |
| Final PDF/CSV audit-trace and database queue attachment follow-up | 3 passed; 26 assertions; 1.47 s | `/tmp/tuition-audit-green.log` |
| Roles ON, affected suites plus every expected-failure case | 4 failed, 255 passed; 1895 assertions; 32.01 s | `/tmp/tuition-roles-on.log` |
| Original roles expected-failures checker | tests=259 assertions=1895 failed=4 expected=4; exactly expected failures, nothing else | `/tmp/tuition-roles-on-check.log` |
| First full SPA run | 2169 passed, 3 failed, 1 skipped; 2173 tests | `/tmp/tuition-spa-full.log` |
| Final full SPA, `TZ=UTC npm run -s test:spa` | 2172 passed, 0 failed, 1 skipped; 2173 tests; 224584.305583 ms | `/tmp/tuition-spa-final.log` |
| Single `npm run build` | succeeded in 11.65 s; inspected both new chunks and all seven tuition language headings | `/tmp/tuition-build.log` |

The two integration failures in the full PHP run are now fixed and independently
green. The entire suite was not repeated:

- `StudentAllergiesTest::allergies_family_child_mode_mobile_device_public_and_other_exports_walk_is_clean`:
  the generic route walk needed an enabled, seeded tuition account. It now walks
  the actual family ledger/PDF and checks decoded PDF streams for health data.
- `StudentBirthDateTest::the_writer_the_reader_and_the_age_have_exactly_these_callers`:
  the internal sibling-order reader needed explicit classification. Dates never
  enter the family payload, PDF, export or reminder.

The eleven known environment failures were exactly the specified two archive,
seven loopback-socket and two missing-build failures: BackupRunTest (2),
ResendTransportTimeoutTest (4), TenancyCanaryTest (3), ExampleTest (1),
HostHeaderUrlIntegrityTest (1).

The OTHER nine failures are unchanged-base `ReportCardCriteriaTest` cases:

- `a_late_started_prior_term_card_uses_its_period_after_a_draft_applies`
- `two_applied_drafts_keep_late_q1_and_q2_cards_and_full_band_versions_recoverable`
- `without_dated_terms_late_cards_use_their_own_school_year_including_a_named_year`
- `the_august_rollover_without_calendar_years_keeps_prior_year_criteria`
- `period_versions_cannot_be_read_or_overwritten_through_another_tenant`
- `sending_freezes_band_even_after_takeback_and_draft_applies_on_next_term_start`
- `without_dated_terms_freeze_is_for_the_school_year`
- `only_the_band_used_by_a_sent_card_freezes_and_unrelated_current_edits_survive_a_draft`
- `deferred_criteria_follow_a_rescheduled_term_and_do_not_apply_on_the_old_start`

An untouched export of `05a3f9e3` reproduced exactly those 9 failed / 27 passed
(182 assertions, 5.15 s), `/tmp/tuition-criteria-baseline.log`. A control changed
only that temporary export's per-test clock to school daytime: all 36 passed
(207 assertions, 5.69 s), `/tmp/tuition-criteria-baseline-noon.log`. The tests keep
the current UTC hour when travelling by date, while criteria reads school-local
dates. Actual criteria source/tests in this worktree were left unchanged.

The SPA failures were the missing tuition hidden-off sidebar rule (fixed, the
original pinned fixture untouched) and two unchanged photo harness cases mixing
UTC fixture dates with local lesson dates after UTC midnight. The latter reproduced
3 passed / 2 failed in the default timezone and all 5 passed under UTC. No photo
source/test changes: `/tmp/tuition-spa-photo-rerun.log` and
`/tmp/tuition-spa-photo-utc.log`.

Tests-first red evidence: `/tmp/tuition-red.log`, `tuition-review-red.log`,
`tuition-office-retry-red.log`, `tuition-routing-red.log`, `tuition-audit-red.log`.
The prepaid next-due request measured 99 SQL statements before correction and 44
after, with a regression ceiling of 60. No future charge is stored by that read.

MySQL schema/RESTRICT/primary-key and two-connection overlap tests were added and
syntax checked. SQLite migration down/up passed. MySQL execution remains for CI;
no local MySQL or full roles-ON suite is claimed. The local roles-ON run covers the
new billing suite and all four original expected failures with the unchanged
checker/list. A real database queue Worker verified ID payload serialization and
a faked statement send with a real generated PDF attachment.

## Not verified

Real Stripe TEST mode (owner proof next), real endpoint subscriptions, real mail,
MySQL execution/locking, full roles-ON CI, browser layout, staging and production.
Non-English tuition copy is machine-drafted pending fluent review. Existing backup
and restore prerequisites still apply before real financial records are enabled.

## Cleanup

The one build's new office/family chunks and seven language headings were inspected;
`public/build` was then removed. Generated test artifacts were removed, and the
pre-existing `artifacts/subject-report-summary-query-counts.json` restored byte for
byte from its pre-full-suite backup. No fixture, lockfile or CHANGELOG was modified.
No commit or switch-on was made. Lessons about the two midnight test traps were
appended to the shared lessons inbox; the read-only memory was not changed.

## Files changed and why

69 reviewable tracked/new paths; working notes STATE.md and PLAN.md were also replaced for this task.

| File | Purpose |
|---|---|
| `.claude/rules/family-ledger.md` | Record reconciliation, current contract, choices and limits. |
| `ASSUMPTIONS.md` | Record reconciliation, current contract, choices and limits. |
| `DECISIONS.md` | Record reconciliation, current contract, choices and limits. |
| `app/Console/Commands/TuitionChargesCommand.php` | Switch-gated school-local charge or reminder sweep. |
| `app/Console/Commands/TuitionRemindersCommand.php` | Switch-gated school-local charge or reminder sweep. |
| `app/Http/Controllers/AdminDashboard/ContactsController.php` | Refuse merges that would obscure immutable financial attribution. |
| `app/Http/Controllers/AdminDashboard/MasjidsController.php` | Keep the new grant hidden while off, including no-op writes and history. |
| `app/Http/Controllers/AdminDashboard/TuitionController.php` | Scoped office/family billing, statement, settings, export and Checkout endpoints. |
| `app/Http/Controllers/Family/MeController.php` | Offer the family tuition link only while enabled. |
| `app/Http/Controllers/Family/TuitionController.php` | Scoped office/family billing, statement, settings, export and Checkout endpoints. |
| `app/Http/Controllers/StripeWebhookController.php` | Route verified tuition events without preempting older payment paths. |
| `app/Jobs/SendTuitionMail.php` | Database queue delivery with custody/transport rechecks and one external attempt. |
| `app/Models/Masjid.php` | Keep the new grant hidden while off, including no-op writes and history. |
| `app/Models/OrganisationBillingSetting.php` | Tenant-scoped financial storage; immutable entries and typed fields. |
| `app/Models/TuitionAccount.php` | Tenant-scoped financial storage; immutable entries and typed fields. |
| `app/Models/TuitionCheckout.php` | Tenant-scoped financial storage; immutable entries and typed fields. |
| `app/Models/TuitionChild.php` | Tenant-scoped financial storage; immutable entries and typed fields. |
| `app/Models/TuitionGuardian.php` | Tenant-scoped financial storage; immutable entries and typed fields. |
| `app/Models/TuitionLine.php` | Tenant-scoped financial storage; immutable entries and typed fields. |
| `app/Models/TuitionMail.php` | Tenant-scoped financial storage; immutable entries and typed fields. |
| `app/Models/TuitionSchedule.php` | Tenant-scoped financial storage; immutable entries and typed fields. |
| `app/Services/FamilyLedger/FamilyLedger.php` | One writer, confirmed account authority, credits/voids, derived balance and FIFO debt. |
| `app/Services/FamilyLedger/TuitionCharges.php` | Calendar schedules, sibling lines, period idempotency and read-only debt previews. |
| `app/Services/FamilyLedger/TuitionPayments.php` | Hosted Checkout snapshots and verified payment/refund settlement. |
| `app/Services/FamilyLedger/TuitionReminders.php` | Before/after amounts, durable per-guardian mail claims and live identity addresses. |
| `app/Services/FamilyLedger/TuitionSettings.php` | Read-only defaults and durable per-school currency/minimum/reminder policy. |
| `app/Services/FamilyLedger/TuitionStatement.php` | Inclusive range/opening balance, exact money display, shared letterhead and PDF. |
| `app/Services/Imports/WixContactImport.php` | Retain every financial contact link, including before-migration guards. |
| `app/Services/Member/MemberAccountDeletion.php` | Retain every financial contact link, including before-migration guards. |
| `app/Services/Stripe/RegistrationCheckoutService.php` | Expose the existing direct-charge Checkout transport for reuse. |
| `app/Support/CapabilityCatalogue.php` | Keep the new grant hidden while off, including no-op writes and history. |
| `app/Support/CapabilityWriter.php` | Keep the new grant hidden while off, including no-op writes and history. |
| `config/capabilities.php` | Hidden default-off tuition_ledger grant for all organisation types. |
| `config/staging_scrub.php` | Anonymise financial prose/actor labels and remove references/provider pointers. |
| `database/migrations/2026_10_15_000000_create_tuition_ledger.php` | Eight additive tables, unique claims, primary keys and restrictive financial FKs. |
| `docs/tuition-ledger-build-report.md` | Implementation contract, owner steps and exact verification report. |
| `docs/tuition-ledger.md` | Implementation contract, owner steps and exact verification report. |
| `resources/views/pdf/tuition-statement.blade.php` | Tuition range statement with opening/closing totals and correction IDs. |
| `resources/vue-app/core/access/orgAccess.ts` | Typed tuition routes, capability label and hidden-off navigation/access. |
| `resources/vue-app/core/constants/dashboardAsideMenuItems.ts` | Typed tuition routes, capability label and hidden-off navigation/access. |
| `resources/vue-app/core/helpers/tuitionMoney.ts` | Exact decimal form parsing and display without floating monetary arithmetic. |
| `resources/vue-app/core/types/config/BackendApiRoutes.ts` | Typed tuition routes, capability label and hidden-off navigation/access. |
| `resources/vue-app/core/types/config/SystemRoutes.ts` | Typed tuition routes, capability label and hidden-off navigation/access. |
| `resources/vue-app/core/types/data/Capability.ts` | Typed tuition routes, capability label and hidden-off navigation/access. |
| `resources/vue-app/router/routes/dashboardLayoutRoutes.ts` | Typed tuition routes, capability label and hidden-off navigation/access. |
| `resources/vue-app/router/routes/familyRoutes.ts` | Typed tuition routes, capability label and hidden-off navigation/access. |
| `resources/vue-app/tests/tuition-money.test.ts` | Financial, isolation, privacy, source-contract, role, schema or mounted UI regression coverage. |
| `resources/vue-app/tests/tuition-views.test.ts` | Financial, isolation, privacy, source-contract, role, schema or mounted UI regression coverage. |
| `resources/vue-app/views/dashboard/TuitionLedgerView.vue` | Office families/overdue, plans, entries/voids, settings, export, statements and log. |
| `resources/vue-app/views/family/FamilyHome.vue` | Hidden-off family navigation link. |
| `resources/vue-app/views/family/FamilyTuition.vue` | Family balance, next due, read-only history, Checkout and statement download. |
| `resources/vue-app/views/family/familyI18n.ts` | New family-visible labels in all seven tables with draft status retained. |
| `resources/vue-app/views/family/locales/es.ts` | New family-visible labels in all seven tables with draft status retained. |
| `resources/vue-app/views/family/locales/fa-AF.ts` | New family-visible labels in all seven tables with draft status retained. |
| `resources/vue-app/views/family/locales/fr.ts` | New family-visible labels in all seven tables with draft status retained. |
| `resources/vue-app/views/family/locales/ps.ts` | New family-visible labels in all seven tables with draft status retained. |
| `resources/vue-app/views/family/locales/ur.ts` | New family-visible labels in all seven tables with draft status retained. |
| `routes/admin.php` | Mount guarded office/family APIs and scheduled bookkeeping/reminder commands. |
| `routes/console.php` | Mount guarded office/family APIs and scheduled bookkeeping/reminder commands. |
| `routes/family.php` | Mount guarded office/family APIs and scheduled bookkeeping/reminder commands. |
| `tests/Feature/App101OffContractTest.php` | Financial, isolation, privacy, source-contract, role, schema or mounted UI regression coverage. |
| `tests/Feature/FamilyPortalTest.php` | Financial, isolation, privacy, source-contract, role, schema or mounted UI regression coverage. |
| `tests/Feature/MemberAccountDeletionCoverageTest.php` | Financial, isolation, privacy, source-contract, role, schema or mounted UI regression coverage. |
| `tests/Feature/StudentAllergiesTest.php` | Financial, isolation, privacy, source-contract, role, schema or mounted UI regression coverage. |
| `tests/Feature/StudentBirthDateTest.php` | Financial, isolation, privacy, source-contract, role, schema or mounted UI regression coverage. |
| `tests/Feature/Studio/CapabilityCatalogueEndpointTest.php` | Financial, isolation, privacy, source-contract, role, schema or mounted UI regression coverage. |
| `tests/Feature/TuitionLedgerTest.php` | Financial, isolation, privacy, source-contract, role, schema or mounted UI regression coverage. |
| `tests/Mysql/TuitionLedgerSchemaTest.php` | Financial, isolation, privacy, source-contract, role, schema or mounted UI regression coverage. |
| `tests/MysqlLocks/TuitionLedgerLocksTest.php` | Financial, isolation, privacy, source-contract, role, schema or mounted UI regression coverage. |
