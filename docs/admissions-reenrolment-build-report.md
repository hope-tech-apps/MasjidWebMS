# Admissions build evidence

Branch feature/admissions-reenrolment, base 05a3f9e3. No commit, deployment, switch-on, CHANGELOG change or fixture capture. No CAPTURE variable set. Pre-existing untracked artifacts/subject-report-summary-query-counts.json is not part of this change.

Contract, storage, default messages, duplicate rule, reuse and synthetic walkthrough: [admissions-reenrolment.md](admissions-reenrolment.md).

## Verification

Initial red tests: PHP 10 failed (missing implementation); SPA 3 failed (missing components). Expanded behavior/schema: 22 passed, 272 assertions, /tmp/admissions-final-target.log. Earlier combined tenant/scoping/scrub/deletion/App101/source guard run: 29 passed, 1204 assertions, /tmp/admissions-guards.log. Studio snapshots/source contracts run before the final extra schema checks: 73 passed with two subsequently corrected failures (office-settlement test needed its existing payment-via argument; catalogue needed the two new hidden school keys), /tmp/admissions-integration.log. Repaired targeted checks recorded separately.

The entire default PHP suite was run once: **14 failed, 7 skipped, 11246 passed (183784 assertions), 829.60s**, /tmp/admissions-full-php.log. Eleven were the known environment cases (seven socket, two archive, two missing-build). The three additional failures were FamilyPortalTest's write-route inventory, SchoolCalendarOffQueryDifferentialTest's year-delete SQL and StudentAllergiesTest's unseeded privacy route parameter. These were corrected without editing any fixture or the legacy expected route list/SQL bodies. Post-full affected suites: **183 passed (2459 assertions)**, /tmp/admissions-post-full-final.log. Further final behavior coverage: **27 passed (230 assertions)**, /tmp/admissions-last-behavior.log.

The first full SPA run found static imports missing from old test mounts, the operator's OFF menu visibility and indentation-sensitive locale parity. Those were corrected with lazy OFF imports, hidden grants and matching locale format; existing behavior assertions were preserved. The repaired full run: **2173 passed, 0 failed, 1 skipped (2174 tests)**, /tmp/admissions-full-spa-final.log. Final full SPA after the payment-return guard: **2174 passed, 0 failed, 1 skipped (2175 tests), 211966.861709ms**, /tmp/admissions-spa-complete.log. Latest focused new-screen/locale checks: **10 passed**, /tmp/admissions-spa-latest.log.

Scoped roles-on verification (18 affected/guard suites including every expected-failure test): **4 failed, 348 passed (5078 assertions), 352 tests**, /tmp/admissions-roles-on.log. The unchanged `scripts/junit-expected-failures.php` accepted it: `failed=4 expected=4`, checker exit 0, /tmp/admissions-roles-check.log. This was a scoped run, not a second entire PHP suite.

Final behavior additions: **27 passed (230 assertions)**, /tmp/admissions-last-behavior.log. The scoped roles-on run re-exercised those same cases after the last changes, along with tenant isolation, deletion, literal source/SQL, privacy sweep and Studio snapshots. All final non-English additions remain machine drafts. Final grade-freeze regressions were observed red (two failures) before correction: /tmp/admissions-grade-freeze-red.log. Final affected default checks: **48 passed (1270 assertions)**, /tmp/admissions-last-regression.log. The final feature suite with roles ON: **28 passed (237 assertions)**, /tmp/admissions-final-feature-roles.log. MySQL is not available locally: no mysql/mariadb installation and the Docker daemon is unavailable. The new MySQL schema/rollback test is wired into the existing MySQL group; SQLite rollback/forward was executed. Do not call the MySQL behavior verified until CI executes it.

Not verified: real email/inbox arrival, Stripe/card settlement, real payment, MySQL execution, deployed browser or demo walkthrough, fluent translation review.

The single `npm run build` passed in **11.16s**, /tmp/admissions-build.log. Five new lazy screen/component chunk entries and six compiled integration markers were inspected in the manifest/assets, /tmp/admissions-build-inspection.log. `public/build` was removed immediately afterward. Vite emitted its existing large-chunk advisory; there was no build error.

## Reused paths

| Existing path | Reuse |
| --- | --- |
| `app/Models/Form.php`, `app/Models/FormResponse.php` | Stored schema, fee/payment choices and authoritative paid/cancelled state |
| `app/Support/FormSchema.php`, `app/Support/FormRoster.php`, `app/Support/StudentAllergies.php` | Clean/validate/flatten child answers and distinguish child sections from emergency contacts |
| `app/Http/Controllers/Api/V1/FormSubmissionsController.php`, `app/Services/Forms/FormResponseWriter.php` | Existing registration, idempotency, card/office payment and receipt path; signed deposit link added inside its transaction |
| `app/Http/Controllers/AdminDashboard/GroupMembershipsController.php`, `tests/Feature/RosterAddResponseTest.php` | Existing office add, contact lock, duplicate refusal and staff-confirmed guardian provenance |
| `app/Services/Schools/RosterImportService.php`, `app/Support/ContactIdentity.php` | Normalized guardian email shortlist followed by exact identity filtering |
| `app/Models/SchoolYear.php`, `app/Models/GroupMembership.php`, `app/Support/GradeLevel.php` | Target years, current roster grades, promotion order and normalized waiting-list grades |
| `app/Http/Controllers/Family/FamilyController.php` | `subject()` guard used by Marks/Attendance on every child read/write/quote |
| `app/Mail/AttendanceAbsenceMail.php`, `app/Support/SchoolMailTransport.php`, `app/Jobs/SendAttendanceAbsenceMail.php` | Existing plain school sender/reply address, unsafe-transport refusal and one-attempt queue pattern |
| `app/Providers/AppServiceProvider.php` | Existing absence-email limiter, shared by new database jobs |

## File inventory

| File | Why |
| --- | --- |
| `.claude/admissions-recon.md` | Task decisions, assumptions and focused recon, with no client data. |
| `ASSUMPTIONS.md` | Task decisions, assumptions and focused recon, with no client data. |
| `DECISIONS.md` | Task decisions, assumptions and focused recon, with no client data. |
| `app/Http/Controllers/AdminDashboard/ContactsController.php` | Move intake records before contact-merge erasure. |
| `app/Http/Controllers/AdminDashboard/MasjidsController.php` | Independent hidden OFF grants, private serialization, school-only route gates and atomic switches. |
| `app/Http/Controllers/AdminDashboard/SchoolApplicationsController.php` | Tenant/guardian guarded office and family intake endpoints. |
| `app/Http/Controllers/AdminDashboard/SchoolCalendarController.php` | Retain years with campaigns while preserving unused OFF SQL. |
| `app/Http/Controllers/AdminDashboard/SchoolReenrolmentController.php` | Tenant/guardian guarded office and family intake endpoints. |
| `app/Http/Controllers/Api/V1/FormSubmissionsController.php` | Link signed deposits inside the existing Forms write/payment path. |
| `app/Http/Controllers/Family/GroupsController.php` | Add campaign metadata only for enabled family schools. |
| `app/Http/Controllers/Family/ReenrolmentController.php` | Tenant/guardian guarded office and family intake endpoints. |
| `app/Http/Middleware/EnsureOrgCapability.php` | Independent hidden OFF grants, private serialization, school-only route gates and atomic switches. |
| `app/Http/Requests/Api/V1/Forms/SubmitFormResponseRequest.php` | Link signed deposits inside the existing Forms write/payment path. |
| `app/Jobs/SendSchoolAdmissionMail.php` | Paced database mail, live recipient rechecks, one attempt and outcomes. |
| `app/Models/Masjid.php` | Independent hidden OFF grants, private serialization, school-only route gates and atomic switches. |
| `app/Models/SchoolAdmissionMail.php` | Tenant scoping and casts for intake records; campaign retention hint. |
| `app/Models/SchoolAdmissionSetting.php` | Tenant scoping and casts for intake records; campaign retention hint. |
| `app/Models/SchoolApplication.php` | Tenant scoping and casts for intake records; campaign retention hint. |
| `app/Models/SchoolApplicationHistory.php` | Tenant scoping and casts for intake records; campaign retention hint. |
| `app/Models/SchoolReenrolment.php` | Tenant scoping and casts for intake records; campaign retention hint. |
| `app/Models/SchoolReenrolmentAnswer.php` | Tenant scoping and casts for intake records; campaign retention hint. |
| `app/Models/SchoolReenrolmentDeposit.php` | Tenant scoping and casts for intake records; campaign retention hint. |
| `app/Services/Member/MemberAccountDeletion.php` | Retain answers, deposit links and guardian/child reminder history after login deletion. |
| `app/Services/Schools/SchoolAdmissionIdentity.php` | Intake decisions, identities, answers, existing payment links, reminder grouping and merge retention. |
| `app/Services/Schools/SchoolAdmissionsRecords.php` | Intake decisions, identities, answers, existing payment links, reminder grouping and merge retention. |
| `app/Services/Schools/SchoolApplications.php` | Intake decisions, identities, answers, existing payment links, reminder grouping and merge retention. |
| `app/Services/Schools/SchoolReenrolmentDeposits.php` | Intake decisions, identities, answers, existing payment links, reminder grouping and merge retention. |
| `app/Services/Schools/SchoolReenrolments.php` | Intake decisions, identities, answers, existing payment links, reminder grouping and merge retention. |
| `app/Support/CapabilityCatalogue.php` | Independent hidden OFF grants, private serialization, school-only route gates and atomic switches. |
| `app/Support/CapabilityWriter.php` | Independent hidden OFF grants, private serialization, school-only route gates and atomic switches. |
| `app/Support/FormPaymentReturn.php` | Link signed deposits inside the existing Forms write/payment path. |
| `app/Support/SchoolAdmissionsMode.php` | Independent hidden OFF grants, private serialization, school-only route gates and atomic switches. |
| `config/capabilities.php` | Independent hidden OFF grants, private serialization, school-only route gates and atomic switches. |
| `config/staging_scrub.php` | Delete every column of all seven private intake tables in child-first order. |
| `database/migrations/2026_10_14_235000_create_school_admissions.php` | Seven tenant tables, keys, retention actions and guarded rollback. |
| `docs/admissions-reenrolment-build-report.md` | Contract, synthetic walkthrough and verification evidence. |
| `docs/admissions-reenrolment.md` | Contract, synthetic walkthrough and verification evidence. |
| `resources/vue-app/components/forms/ApplicationFormSetting.vue` | Form setting, status/history control, stored-schema deposit fields and lazy OFF loading. |
| `resources/vue-app/components/forms/ApplicationStatusControl.vue` | Form setting, status/history control, stored-schema deposit fields and lazy OFF loading. |
| `resources/vue-app/components/forms/FamilyDepositField.vue` | Form setting, status/history control, stored-schema deposit fields and lazy OFF loading. |
| `resources/vue-app/components/forms/FormBuilder.vue` | Form setting, status/history control, stored-schema deposit fields and lazy OFF loading. |
| `resources/vue-app/core/access/orgAccess.ts` | Typed routes, hidden navigation and independent capability labels/guards. |
| `resources/vue-app/core/constants/dashboardAsideMenuItems.ts` | Typed routes, hidden navigation and independent capability labels/guards. |
| `resources/vue-app/core/types/config/BackendApiRoutes.ts` | Typed routes, hidden navigation and independent capability labels/guards. |
| `resources/vue-app/core/types/config/SystemRoutes.ts` | Typed routes, hidden navigation and independent capability labels/guards. |
| `resources/vue-app/core/types/data/Capability.ts` | Typed routes, hidden navigation and independent capability labels/guards. |
| `resources/vue-app/router/router.ts` | Typed routes, hidden navigation and independent capability labels/guards. |
| `resources/vue-app/router/routes/dashboardLayoutRoutes.ts` | Typed routes, hidden navigation and independent capability labels/guards. |
| `resources/vue-app/router/routes/familyRoutes.ts` | Typed routes, hidden navigation and independent capability labels/guards. |
| `resources/vue-app/tests/school-admissions.test.ts` | Mounted family/office controls, public submit, payment return and seven-language parity. |
| `resources/vue-app/views/dashboard/SchoolApplicationsView.vue` | Office year/answer/reminder and application/status/roster/message screens. |
| `resources/vue-app/views/dashboard/SchoolReenrolmentView.vue` | Office year/answer/reminder and application/status/roster/message screens. |
| `resources/vue-app/views/family/FamilyClass.vue` | Show the family card only when enabled campaign metadata exists. |
| `resources/vue-app/views/family/FamilyReenrolment.vue` | Per-child answers/attribution and signed, prefilled existing-form deposit flow. |
| `resources/vue-app/views/family/FamilyReenrolmentDeposit.vue` | Per-child answers/attribution and signed, prefilled existing-form deposit flow. |
| `resources/vue-app/views/family/familyI18n.ts` | Twenty new keys in every family language; non-English additions marked machine drafted. |
| `resources/vue-app/views/family/locales/es.ts` | Twenty new keys in every family language; non-English additions marked machine drafted. |
| `resources/vue-app/views/family/locales/fa-AF.ts` | Twenty new keys in every family language; non-English additions marked machine drafted. |
| `resources/vue-app/views/family/locales/fr.ts` | Twenty new keys in every family language; non-English additions marked machine drafted. |
| `resources/vue-app/views/family/locales/ps.ts` | Twenty new keys in every family language; non-English additions marked machine drafted. |
| `resources/vue-app/views/family/locales/ur.ts` | Twenty new keys in every family language; non-English additions marked machine drafted. |
| `routes/admin.php` | Register office/family endpoints under existing realm stacks and independent grant middleware. |
| `routes/family.php` | Register office/family endpoints under existing realm stacks and independent grant middleware. |
| `tests/Feature/App101OffContractTest.php` | Pin three guarded intake retention reads separately from the original fixture. |
| `tests/Feature/FamilyPortalTest.php` | Pin the two new guarded verbs separately; preserve the exact legacy route list. |
| `tests/Feature/MemberAccountDeletionCoverageTest.php` | Classify all new direct contact references as office records. |
| `tests/Feature/SchoolAdmissionsSchemaTest.php` | Primary keys, short indexes, PII policy, actor retention and SQLite rollback. |
| `tests/Feature/SchoolAdmissionsTest.php` | Behavior, authority, privacy, deposits, identities, mail, history and retention. |
| `tests/Feature/StudentAllergiesTest.php` | Seed new route IDs in the privacy sweep and inspect the disabled refusal. |
| `tests/Feature/Studio/CapabilityCatalogueEndpointTest.php` | Extend the school-key inventory and hidden-key exclusions for both grants. |
| `tests/Mysql/SchoolAdmissionsSchemaMysqlTest.php` | MySQL primary keys, types, rollback/forward and JSON-child matching (not executed locally). |

Local ignored working notes `STATE.md` and `PLAN.md` were updated for this build. Generated test artifacts were removed; the pre-existing untracked artifact was retained (the suite can regenerate that path). Tracked whitespace and non-whitespace diff statistics match; `git diff --check` is clean. A scan of current added/untracked source is recorded in /tmp/admissions-public-scan.log.

## Walk-fix round, base 78620885

All twelve owner findings have regression coverage. The final original-source reproduction (fixed sources restored byte for byte afterward) observed 11 backend failures, one uniqueness-schema failure and 10 mounted SPA failures. Evidence: `/tmp/admissions-walk-final-red-php.log`, `/tmp/admissions-walk-final-red-schema.log`, `/tmp/admissions-walk-final-red-spa.log`.

Final targeted checks: **84 passed, 1544 assertions** in 6.80 seconds, `/tmp/admissions-walk-targeted.log` and `/tmp/admissions-walk-targeted.xml`:

| Suite | Tests | Assertions |
| --- | ---: | ---: |
| SchoolAdmissionsTest | 42 | 336 |
| SchoolAdmissionsSchemaTest | 4 | 93 |
| StagingScrubCoverageTest | 4 | 900 |
| App101OffContractTest | 1 | 20 |
| SchoolCalendarOffQueryDifferentialTest | 13 | 26 |
| ClassSubjectReportSummaryTest | 20 | 169 |

Admissions SPA: **20 passed**, `/tmp/admissions-walk-spa.log`. Coverage includes successful and failed save suppression reset, row changes, paid/unpaid existing deposit status without a card-return query, payable/unpayable unpaid handles, refusal sentences, deposit dashes, closed office controls, reminder confirmation/cancellation and last delivery, and immediate/reloaded roster feedback. The original suppressed-save and office-action tests remain behavioral mounted tests.

The fix adds `2026_10_14_235100_guard_school_admission_deposits`; the original migration is unchanged. Existing duplicate child/campaign links refuse the upgrade before schema changes, preserving every response/payment for an operator decision. A new private batch UUID gives exact delivery counts for separate reminder requests. The existing whole-table staging policy covers the column, and scrub coverage passed. MySQL rollback/index checks were extended but are not locally executed.

Entire SPA run: **2183 passed, 2 failed, 1 skipped (2186 tests)**, `/tmp/admissions-walk-full-spa.log`. Both failures are outside this diff in `class-subjects-screens.test.ts`: "photo: mounted empty-only fill, suggestion edits and deferred staff photo save" (2165) and "photo: mounted failed read keeps the form and never displays provider words" (2205). They fail in isolation with the current local clock and pass together with `TZ=UTC` (2/2), `/tmp/admissions-walk-unrelated-photo.log` and `/tmp/admissions-walk-photo-utc-control.log`. Their `photoSetup` fixture (2120) uses `new Date().toISOString().slice(0, 10)` while the screen selects the local calendar day; after UTC midnight the fixture is tomorrow. Those files were not edited. The extra checkout-reason case was added after the entire SPA began: it first failed while the other 18 passed (`/tmp/admissions-walk-checkout-reason-red.log`), then all 19 passed. It covers a message together with `data.uuid`, which the shared validation-bag helper previously displayed before the actual server sentence.

A second deposit recovery regression reproduced a checkout-refusal handle forcing Pay now even with `can_pay: false` (1 failed / 19 passed, `/tmp/admissions-walk-confirming-red.log`). Recovery now reads the saved response status, and all **20** admissions SPA tests pass.

Entire PHP suite **ONCE**: **11273 passed, 7 skipped, 11 failed; 184139 assertions; 838.90s**, `/tmp/admissions-walk-full-php.log` and `/tmp/admissions-walk-full-php.xml`. PHP source stayed unchanged throughout the run. All eleven failures match the owner's known categories: `BackupRunTest` (2 archive); `ExampleTest` and `HostHeaderUrlIntegrityTest` (1 missing-build each); `ResendTransportTimeoutTest` (4 socket); `TenancyCanaryTest` (3 socket). There are no other PHP failures. The three TenancyCanary socket errors are reported at suite/provider level in JUnit rather than as testcase elements, so counting only error children of testcase nodes understates the total.

Local SPA build passed in **10.69s**, `/tmp/admissions-walk-build.log`; the manifest's deposit, office re-enrolment and Applications chunks contain the expected changed markers, `/tmp/admissions-walk-build-inspection.log`. Existing Browserslist data, unresolved font and large-chunk advisories remain. The generated build and new test artifacts were removed; the pre-existing `artifacts/subject-report-summary-query-counts.json` was retained. Final whitespace checks passed, with close whitespace/non-whitespace diff sizes and no formatter run. MySQL execution, real browser/deployed behavior, actual mail/payment and fluent translation review remain unverified. No commit, deployment, switch-on, real email/payment, production data operation, fixture refresh or CHANGELOG change. Added-line privacy checks found no private-key markers, live provider tokens or non-synthetic email domains; `git diff --check` is clean.

Walk-fix coverage map (each owner fault was observed red before its correction):

| Finding | Implemented behavior | Regression |
| --- | --- | --- |
| 1 | Linked deposit responses excluded from application lists/direct decisions/rosters and skipped by application mail worker | `deposit_responses_are_excluded_from_every_application_action_and_queued_mail` |
| 2 | Mail suppression resets after every save attempt and row change | Mounted status-control save/failure/row tests |
| 3 | Existing paid/unpaid response always opens status, no fresh form; duplicate submit rolls back with 409; database unique child/campaign key | Data-provider paid/unpaid API cases, three mounted paid/payable/unpayable cases, schema key/upgrade tests |
| 4 | Server formats office instants in school timezone | School-local timestamp API assertions for answers, reminders, submissions and history |
| 5 | Display readable server reasons from either envelope; checkout metadata cannot mask message | Mounted data/message/checkout-UUID refusals |
| 6 | Unanswered/not-returning deposit cells show a dash, excluded from deposit filters | Mounted office deposit cells |
| 7 | Propose only a grade currently served at this school; blank prompts office choice | Served/unserved/foreign-school/frozen-blank API tests and mounted placeholder |
| 8 | Counted confirmation with cancel; last batch time/count; family-card links in reminders | Reminder API/count/link/same-second batch tests and mounted confirmation/cancellation |
| 9 | Independent admissions rate-limit bucket at the existing pace | Named limiter and key/pace assertions |
| 10 | Office can record/change late answers with office attribution; families remain closed | Closed-date API and mounted paper-answer controls |
| 11 | Added child displays its current class immediately and after reload | API roster-class projection and mounted action replacement |
| 12 | Applying-grade keys and labels precede current-grade fields | Repeated and flat form-grade API cases |
