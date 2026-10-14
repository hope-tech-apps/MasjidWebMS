# Letter grades build handback

Built on `feature/letter-grades`, base `aafbf7ba`, without commits or deployment. The rollout grant defaults off everywhere. No client data, switch-ons, notifications or guide installation were performed.

## Evidence

- Tests first: initial backend tests failed on the missing implementation; initial four SPA tests failed on missing components.
- Entire PHP suite, run once: **17 failed, 7 skipped, 11093 passed (181496 assertions)**, 805.21 seconds. Log: `/tmp/letter-full-php.log`.
- The expected environment failures were seven socket tests (`ResendTransportTimeoutTest` four, `TenancyCanaryTest` three), two archive tests (`BackupRunTest`), and two missing-build tests (`ExampleTest`, `HostHeaderUrlIntegrityTest`).
- Additional full-run failures: `ClassSubjectReportSummaryTest` (one literal SQL/data assertion), `MemberAccountDeletionCoverageTest` (one contact-reference classification), `SchoolCalendarOffQueryDifferentialTest` (three request shapes), and `SubjectWorkOffTest` (one literal SQL assertion). **All six were fixed** without recapturing or changing fixtures.
- Post-fix affected/CI guard run: **99 passed (1802 assertions)**. Log: `/tmp/letter-after-full.log`. Includes tenant coverage, staging scrub, both account-deletion suites and the source/literal OFF contracts.
- Final broader grading/report regression: **147 passed (1686 assertions)**. Log: `/tmp/letter-final-regression.log`. Includes letter grades, unchanged report-card and PDF tests, gradebook weighting/subjects, dated-term links and disabled-path SQL/data.
- Final dated-heading, school-local midnight and younger-class checks: **105 passed (1424 assertions)**, `/tmp/letter-final-evidence.log`. Final school-only grant/catalogue and grading checks: **70 passed (2636 assertions)**, `/tmp/letter-final-grant.log`.
- `npm run -s test:spa`: **2143 passed, 0 failed, 1 skipped**, 2144 total. Log: `/tmp/letter-full-spa-final.log`. The first full SPA run found test harness imports and a select-helper issue; these were corrected before the successful full rerun.
- Final changed-UI checks after minor policy/type refinements: four component tests and the actual Reports integration test passed. Logs: `/tmp/letter-ui-final.log`, `/tmp/letter-report-final.log`.
- One `npm run build`: passed in 11.10 seconds. Log: `/tmp/letter-build.log`. The manifest’s referenced files existed and settings/staff/family strings were inspected in the built JavaScript. Vite reported its large-chunk warning. `public/build` was then removed. Test-generated untracked artifacts were removed too.
- `git diff --check` is clean. Whitespace-sensitive and whitespace-ignored shortstats match. No fixture files, `CHANGELOG.md` or commits changed; no `CAPTURE_*` variable was set.

The full PHP suite was not repeated after fixes, following the requested one-run limit. Its totals above are the actual printed totals, not an extrapolated green total.

## Reuse and storage

The gradebook percentage is the existing `app/Support/GradeRecord.php` function chain: `forTerm` calls `summaryForClassSubjects`, which calls the existing `bySubject` and `weighted` arithmetic. `app/Models/ClassGradeWeight.php` and `app/Models/AssignmentScore.php::COUNTS_TOWARD_AVERAGE` are unchanged and reused. Points alone feed the letter; `app/Models/SubjectPieceMark.php` and the existing subject/plan tables supply level work omitted from letters.

`app/Models/ReportCard.php`, `app/Models/ReportCardMark.php` and `app/Services/Schools/ReportCardService.php` retain one shared card/mark system, preparation, lock/take-back and attendance snapshots. Publication adds frozen subject letters/percentages/comments in that same transaction. `app/Services/Schools/ReportCardPdfService.php` and `resources/views/pdf/report-card.blade.php` remain the PDF implementation. The existing term-mark criterion also freezes the dated heading for the family page, PDF and filename. `app/Support/SchoolDateAuthority.php` supplies the school-local day for default term selection. Class subjects, student membership grade labels and existing `app/Support/GradeLevel.php` normalization/order are reused. Dated terms reuse `app/Models/SchoolTerm.php` and the actual `school_terms` table; `school_calendar_terms` is the capability key.

Scale storage: one hidden nullable `masjids.letter_grade_settings` JSON configuration `{enabled, from_grade, scale: [{letter, minimum}]}`. The scale is validated and saved as a whole under the school mutex, because individual scale rows have no independent lifecycle. A separate default-off rollout grant controls whether office settings are offered.

Term record: `term_grades` with `id`, `masjid_id`, `contact_id`, `class_subject_id`, `school_term_id`, `report_card_id`, frozen `subject`, final letter, calculated percentage, family comment, publication time and timestamps. Unique on `(contact_id, class_subject_id, school_term_id)`. Contact identity persists across roster moves; publication writes the record, and take-back plus re-send replaces it. Another still-sent card cannot be overwritten.

Point 5 decision: hide a subject’s family/PDF percentage when the teacher’s final letter differs from the calculated letter. Staff retain the calculated percentage, both letters, reason and actor/time. Recorded in `DECISIONS.md`. Sent cards use stored values after scale/gradebook edits.

## NOT verified

MySQL execution/locking or migration on staging/production; deployed browser walkthrough or the demo guide session; installed guide pages; fluent review of the new non-English labels. No full PHP rerun after the fixes. Automated SQLite, endpoint/PDF, mounted SPA and build checks above are verified.

## Files changed and why

| File | Purpose |
| --- | --- |
| [ASSUMPTIONS.md](../ASSUMPTIONS.md) | Records rollout policy and percentage precision assumptions. |
| [DECISIONS.md](../DECISIONS.md) | Records JSON storage, student identity, family percentage choice and actual dated-term table name. |
| [app/Http/Controllers/AdminDashboard/LetterGradeSettingsController.php](../app/Http/Controllers/AdminDashboard/LetterGradeSettingsController.php) | Office whole-configuration settings read/save, browser boolean handling and dated-term prerequisites. |
| [app/Http/Controllers/AdminDashboard/MasjidsController.php](../app/Http/Controllers/AdminDashboard/MasjidsController.php) | Omits the disabled grant and its history from organisation switch catalogues. |
| [app/Http/Controllers/AdminDashboard/SchoolCalendarConfigurationController.php](../app/Http/Controllers/AdminDashboard/SchoolCalendarConfigurationController.php) | Keeps terms/years that have issued academic grade records. |
| [app/Http/Controllers/Family/ReportCardsController.php](../app/Http/Controllers/Family/ReportCardsController.php) | Conditional final-only subject snapshots under existing guardian and publication scopes. |
| [app/Http/Controllers/Teacher/GradebookController.php](../app/Http/Controllers/Teacher/GradebookController.php) | Existing gradebook read accepts a tenant-scoped dated term and calls the same summary as letters. |
| [app/Http/Controllers/Teacher/ReportCardController.php](../app/Http/Controllers/Teacher/ReportCardController.php) | Conditional staff grades, unknown-grade notice, dated selectors and school-local current-term defaults; existing report routes reused. |
| [app/Http/Requests/Teacher/SaveReportCardRequest.php](../app/Http/Requests/Teacher/SaveReportCardRequest.php) | Validates optional term-letter edit rows; actor and time remain server-owned. |
| [app/Models/Masjid.php](../app/Models/Masjid.php) | Hidden school JSON configuration, default-off grant serialization and public denylist. |
| [app/Models/ReportCardMark.php](../app/Models/ReportCardMark.php) | Term-grade kind and casts; hides all new raw provenance columns. |
| [app/Models/TermGrade.php](../app/Models/TermGrade.php) | Tenant-scoped last issued student/subject/term record. |
| [app/Services/Member/MemberAccountDeletion.php](../app/Services/Member/MemberAccountDeletion.php) | Classifies the student contact reference as retained office academic history. |
| [app/Services/Schools/ReportCardPdfService.php](../app/Services/Schools/ReportCardPdfService.php) | Uses the family allowlist, preserving the exact PDF data shape while letters are off. |
| [app/Services/Schools/ReportCardService.php](../app/Services/Schools/ReportCardService.php) | Reuses template creation, draft save, publication, attendance snapshot and take-back; letters join their transactions. |
| [app/Support/CapabilityCatalogue.php](../app/Support/CapabilityCatalogue.php) | Keeps the new grant out of Studio provisioning catalogues, matching existing dark grants. |
| [app/Support/CapabilityWriter.php](../app/Support/CapabilityWriter.php) | Preserves the hidden grant’s OFF-to-OFF no-op behavior. |
| [app/Support/GradeLevel.php](../app/Support/GradeLevel.php) | Position lookup in the existing normalized teaching order. |
| [app/Support/GradeRecord.php](../app/Support/GradeRecord.php) | Inclusive date filters around the existing subject pooling and weighting; exposes the shared forTerm call. |
| [app/Support/LetterGrades.php](../app/Support/LetterGrades.php) | Whole-scale validation, student/class eligibility, staff evidence, override attribution, final-only allowlist and publication/dated-heading snapshots; reuses the request organisation for unchanged OFF SQL. |
| [app/Support/SchoolCalendarCapabilityWriter.php](../app/Support/SchoolCalendarCapabilityWriter.php) | Same hidden no-op behavior when a calendar switch is written in the same transaction. |
| [app/Support/SchoolSettings.php](../app/Support/SchoolSettings.php) | Prevents removing letter-grade prerequisites while the office policy remains enabled. |
| [app/Support/SimpleMark.php](../app/Support/SimpleMark.php) | Applies the same optional term window to the gradebook’s word-mark tally. |
| [app/Support/SubjectFence.php](../app/Support/SubjectFence.php) | Advertises letter-enabled Reports using the existing organisation and subject authority. |
| [config/capabilities.php](../config/capabilities.php) | New school grant, false for every organisation type and hidden when off. |
| [config/staging_scrub.php](../config/staging_scrub.php) | Classifies every new column; anonymizes child comments, reasons and final comments. |
| [database/migrations/2026_10_14_200000_add_letter_grades.php](../database/migrations/2026_10_14_200000_add_letter_grades.php) | Adds the school JSON, existing-mark snapshot columns and unique term record; rollback refuses to erase drafts or issued grades. |
| [docs/letter-grades-build-report.md](../docs/letter-grades-build-report.md) | This handback and complete changed-file inventory. |
| [docs/letter-grades.md](../docs/letter-grades.md) | Contracts, storage, reused arithmetic, publication behavior and synthetic guide-session walkthrough. |
| [resources/views/pdf/report-card.blade.php](../resources/views/pdf/report-card.blade.php) | Prints final term letter, permitted percentage and frozen comment; retains skill lines. |
| [resources/vue-app/components/classes/FamilyLetterGrades.vue](../resources/vue-app/components/classes/FamilyLetterGrades.vue) | Family final letter, permitted percentage and comment, with localized no-grade text. |
| [resources/vue-app/components/classes/LetterGradeSettings.vue](../resources/vue-app/components/classes/LetterGradeSettings.vue) | School switch, starting-grade picker, whole editable scale and prerequisite explanation. |
| [resources/vue-app/components/classes/ReportLetterGrade.vue](../resources/vue-app/components/classes/ReportLetterGrade.vue) | Staff calculated/set grades, reason, actor/time, omitted work and teacher editing controls. |
| [resources/vue-app/composables/useClassSubjects.ts](../resources/vue-app/composables/useClassSubjects.ts) | Offers the office Reports tab when letters are enabled. |
| [resources/vue-app/core/types/data/Capability.ts](../resources/vue-app/core/types/data/Capability.ts) | Mirrors the new school-only grant key and English staff label. |
| [resources/vue-app/core/types/data/masjid-related/ClassSubject.ts](../resources/vue-app/core/types/data/masjid-related/ClassSubject.ts) | Types the optional letter-enabled Reports flag. |
| [resources/vue-app/tests/admin-guide-forms.test.ts](../resources/vue-app/tests/admin-guide-forms.test.ts) | Supplies the new unrelated settings widget in the existing legacy-form harness. |
| [resources/vue-app/tests/class-subjects-screens.test.ts](../resources/vue-app/tests/class-subjects-screens.test.ts) | Actual teacher Reports override/comment save and clean-state acknowledgement. |
| [resources/vue-app/tests/family-left-class-notice.test.ts](../resources/vue-app/tests/family-left-class-notice.test.ts) | Supplies the new unrelated family-card widget in the existing consent-notice harness. |
| [resources/vue-app/tests/letter-grades.test.ts](../resources/vue-app/tests/letter-grades.test.ts) | Mounted settings, teacher-box and final-only family-card behavior; observed red before implementation. |
| [resources/vue-app/views/dashboard/GeneralSettingsView.vue](../resources/vue-app/views/dashboard/GeneralSettingsView.vue) | Hosts school letter settings only after the rollout grant is present. |
| [resources/vue-app/views/dashboard/GroupDetailView.vue](../resources/vue-app/views/dashboard/GroupDetailView.vue) | Permits the office’s existing Reports panel for letter-enabled schools. |
| [resources/vue-app/views/dashboard/groups/GroupReportsTab.vue](../resources/vue-app/views/dashboard/groups/GroupReportsTab.vue) | Read-only staff grade boxes and dated term names. |
| [resources/vue-app/views/family/FamilyClass.vue](../resources/vue-app/views/family/FamilyClass.vue) | Renders the new final-only family subject rows only when present. |
| [resources/vue-app/views/family/familyI18n.ts](../resources/vue-app/views/family/familyI18n.ts) | New English and machine-drafted Arabic family labels. |
| [resources/vue-app/views/family/locales/es.ts](../resources/vue-app/views/family/locales/es.ts) | New machine-drafted family labels; retains the existing fluent-review warning and locale key parity. |
| [resources/vue-app/views/family/locales/fa-AF.ts](../resources/vue-app/views/family/locales/fa-AF.ts) | New machine-drafted family labels; retains the existing fluent-review warning and locale key parity. |
| [resources/vue-app/views/family/locales/fr.ts](../resources/vue-app/views/family/locales/fr.ts) | New machine-drafted family labels; retains the existing fluent-review warning and locale key parity. |
| [resources/vue-app/views/family/locales/ps.ts](../resources/vue-app/views/family/locales/ps.ts) | New machine-drafted family labels; retains the existing fluent-review warning and locale key parity. |
| [resources/vue-app/views/family/locales/ur.ts](../resources/vue-app/views/family/locales/ur.ts) | New machine-drafted family labels; retains the existing fluent-review warning and locale key parity. |
| [resources/vue-app/views/teacher/TeacherClass.vue](../resources/vue-app/views/teacher/TeacherClass.vue) | Integrates letter drafts, save/reload/dirty tracking, lock state and dated term names into Reports. |
| [routes/admin.php](../routes/admin.php) | Office settings read/write and existing read-only Reports access through the new grant. |
| [tests/Feature/LetterGradesTest.php](../tests/Feature/LetterGradesTest.php) | Scale/order/threshold/window parity, missing/excused/levels, no work, override attribution, privacy/PDF, snapshots/re-send, combined grades, settings/isolation and schema/rollback tests; observed red first. |
| [tests/Feature/MemberAccountDeletionCoverageTest.php](../tests/Feature/MemberAccountDeletionCoverageTest.php) | Pins term grades to retained office records. |
| [tests/Feature/SchoolCalendarTermsTest.php](../tests/Feature/SchoolCalendarTermsTest.php) | Asserts the new school configuration is null before excluding it from literal old row comparisons; fixtures unchanged. |
| [tests/Feature/SchoolReportCardTermLinkTest.php](../tests/Feature/SchoolReportCardTermLinkTest.php) | Asserts all new mark columns are null on the legacy path before excluding them from literal old row comparisons; fixtures unchanged. |
| [tests/Feature/Studio/CapabilityCatalogueEndpointTest.php](../tests/Feature/Studio/CapabilityCatalogueEndpointTest.php) | Pins the new school-only hidden grant and preserves old offered catalogue entries. |
| [tests/Feature/Studio/ProvisionResponseSnapshotTest.php](../tests/Feature/Studio/ProvisionResponseSnapshotTest.php) | Asserts/excludes the new hidden null school column, preserving original provisioning fixtures. |

Local working files `STATE.md` and `PLAN.md` were also created and kept current; they are ignored by the repository.
