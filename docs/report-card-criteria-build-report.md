# Report-card criteria build handback

Built uncommitted on `feature/report-card-criteria`, base `c155f2d0` from
`feature/letter-grades`. The letter-grade branch must ship first. The new grant
remains default off everywhere. No deployments, switch-ons, notifications,
`CAPTURE_*` variables, fixture recaptures or changelog edits were performed.

## Result and reader routing

The office has a criteria editor in General Settings beside letter grades; teachers
have Criteria beside Reports. Bands, defaults, subjects, ordered criteria and
learning behaviours persist per school. Grade order/aliases reuse `GradeLevel`;
teacher authority reuses `SubjectFence` and class-subject assignments. Staff and time
appear in the history. The original `ReportCardTemplate.php` is unchanged.

A final source search shows `ReportCardCriteria` is the only executable caller of
`ReportCardTemplate`. `ReportCardService` uses its curriculum selector; the teacher
controller, family controller, PDF and `LetterGrades` use its saved-mark reader.
The records export uses `markQuery()`. `SaveReportCardRequest` shares `MAX_ROWS` and
continues accepting mark IDs/levels/comments, never curriculum edits. Comments
that mention the legacy template are documentation, not remaining callers.

## Storage and freeze rule

Five tenant-scoped tables: `report_card_bands`, `report_card_criteria`,
`report_card_criteria_drafts`, `report_card_criteria_histories`,
`report_card_band_freezes`. All have primary keys. Criteria order, deferred period
and publication-lock identities have unique keys. `report_cards.criteria_band_id`
is hidden. No new `masjids` column was needed, so provisioning snapshots need no
new masjids-column stripping. Curriculum is explicitly classified as school text
in staging scrub policy. Nullable user attribution uses `nullOnDelete`; its
regression proves curriculum survives staff deletion. No contact references or
new member-account deletion statements were introduced; App101’s fixture and
existing deletion SQL remain unchanged.

Sending either kind of card locks its band for that dated term, or for the school
year without dated terms. Taking a card back does not unlock it. Existing sent
cards establish locks when the editor first seeds; current publications are also
reconciled after a temporary switch-off. Editor saves and publication share the
school mutex. Deferred drafts take effect on the school-local next start date,
on the first relevant reader/editor/preparation request; rescheduled terms move
the draft’s start with them. Unrelated current edits merge into waiting drafts;
conflicts and stale revisions are refused. No criteria edit rewrites any card’s
saved mark rows, including unfinished cards.

Without a next dated term, a dated-term school must configure one before saving a
draft. Without dated terms, the next configured year start is used; absent one,
the current start repeats a year later, or the existing August rollover when no
year is configured. These assumptions are recorded in `ASSUMPTIONS.md`.

## Verification

- Tests-first failures: `/tmp/criteria-red.log` (12 backend failures before the
  implementation) and `/tmp/criteria-spa-red.log` (four missing-component failures).
- Entire PHP suite **once**: **11,162 passed, 26 failed, 7 skipped**, **182,253
  assertions**, 811.68 s. `/tmp/report-card-criteria-php-full.log`.
- Expected environment failures: `ResendTransportTimeoutTest` (4 socket cases),
  `TenancyCanaryTest` (3 socket cases), `BackupRunTest` (2 archive cases),
  `ExampleTest` and `HostHeaderUrlIntegrityTest` (one missing-build case each).
- Every additional full-run failure is named below. All 15 were fixed without
  changing fixtures and are green in the final affected run.
- Final affected PHP/CI guard run: **317 passed, 6,078 assertions**, 58.08 s.
  `/tmp/report-card-criteria-final-php.log`. This includes all 25 new feature tests,
  unchanged report-card/PDF/export and letter-grade suites, all failed contracts,
  tenant isolation coverage, staging scrub, member-account deletion coverage,
  App101’s literal OFF contract, provisioning snapshots and both-school route sweeps.
- Final `npm run -s test:spa`: **2,160 passed, 0 failed, 1 skipped** (2,161 total).
  `/tmp/report-card-criteria-spa-complete.log`. All six new mounted editor tests pass.
- Exactly one `npm run build`: passed in 11.35 s.
  `/tmp/report-card-criteria-build.log`. Its 217-entry manifest referenced a bundle
  containing the editor’s next-term control. `public/build` was then removed.
  Warnings: old Browserslist data, an unresolved font URL left for runtime, and
  large chunks.
- `git diff --check` is clean. Whitespace-sensitive and whitespace-ignored tracked
  shortstats agree. The added-text private-name/address and email-domain checks
  pass. Forty generated test artifacts were removed. No fixture or changelog diff.

| Additional full-run class | Failed cases | Fix proved by final affected run |
|---|---:|---|
| `ClassLocationVisibleTest` | 1 | Reuse the loaded school; decorate class responses without changing OFF SQL. |
| `ClassSubjectsOffSourceContractTest` | 1 | Restore the frozen teacher method body; use response middleware. |
| `SchoolReportCardTermLinkTest` | 5 | Assert-null and strip only the new hidden raw band column before unchanged fixture comparison. |
| `SchoolTimetableOffTest` | 1 | Remove the extra OFF school lookup. |
| `StudentAllergiesTest` | 1 | Remove the extra OFF school lookup. |
| `Studio\CapabilityCatalogueEndpointTest` | 1 | Add the intentionally hidden school grant to exact inventories. |
| `SubjectWorkOffTest` | 1 | Reuse existing school reads for OFF preparation and responses. |
| `TeacherMultiSchoolTest` | 2 | Add the enabled editor and its valid/foreign payloads to both route sweeps. |
| `TeacherRealmTest` | 1 | Pin the newly authorized criteria write explicitly. |
| `TeacherTimetableTest` | 1 | Preserve literal OFF teacher/class responses and SQL. |

## NOT verified

MySQL migration execution, real InnoDB locking/concurrency, staging/production,
an actual browser walkthrough, or strict TypeScript checking. No entire PHP rerun
after the targeted fixes; the single full run and final 317-test affected run are
the evidence. Nothing has been committed or enabled.

## Files changed and why

| File | Purpose |
|---|---|
| [.claude/report-card-criteria-recon.md](../.claude/report-card-criteria-recon.md) | Focused recon stamped with the base commit and scope. |
| [ASSUMPTIONS.md](../ASSUMPTIONS.md) | Records the year-date fallback, complete grade coverage and behaviour authority. |
| [DECISIONS.md](../DECISIONS.md) | Records all six design calls and the superseding staff-editing decision. |
| [app/Http/Controllers/AdminDashboard/MasjidsController.php](../app/Http/Controllers/AdminDashboard/MasjidsController.php) | Hides the disabled criteria grant and its panel history. |
| [app/Http/Controllers/AdminDashboard/ReportCardCriteriaController.php](../app/Http/Controllers/AdminDashboard/ReportCardCriteriaController.php) | Shared office/teacher editor endpoints with boolean normalization and revision validation. |
| [app/Http/Controllers/AdminDashboard/SchoolRecordsExportController.php](../app/Http/Controllers/AdminDashboard/SchoolRecordsExportController.php) | Reads exported marks through the snapshot reader. |
| [app/Http/Controllers/Family/ReportCardsController.php](../app/Http/Controllers/Family/ReportCardsController.php) | Reads issued criteria through the snapshot reader. |
| [app/Http/Controllers/Teacher/ReportCardController.php](../app/Http/Controllers/Teacher/ReportCardController.php) | Reads saved marks and conditionally explains unplaced grades. |
| [app/Http/Middleware/AppendReportCardCriteria.php](../app/Http/Middleware/AppendReportCardCriteria.php) | Adds the Criteria entry to authorized class responses using the loaded school; OFF adds no SQL. |
| [app/Http/Requests/Teacher/SaveReportCardRequest.php](../app/Http/Requests/Teacher/SaveReportCardRequest.php) | Shares the reader’s skill-row limit; curriculum text remains outside mark updates. |
| [app/Models/Masjid.php](../app/Models/Masjid.php) | Omits the disabled grant from computed capabilities and raw overrides. |
| [app/Models/ReportCard.php](../app/Models/ReportCard.php) | Hides the internal band reference from existing card payloads. |
| [app/Models/ReportCardBand.php](../app/Models/ReportCardBand.php) | Tenant-owned active/archived grade ranges and default band. |
| [app/Models/ReportCardBandFreeze.php](../app/Models/ReportCardBandFreeze.php) | Tenant-owned persistent band/period publication locks. |
| [app/Models/ReportCardCriteriaDraft.php](../app/Models/ReportCardCriteriaDraft.php) | Tenant-owned dated deferred configurations and attribution. |
| [app/Models/ReportCardCriteriaHistory.php](../app/Models/ReportCardCriteriaHistory.php) | Tenant-owned staff/time history. |
| [app/Models/ReportCardCriterion.php](../app/Models/ReportCardCriterion.php) | Tenant-owned ordered academic/behaviour curriculum rows. |
| [app/Services/Schools/ReportCardPdfService.php](../app/Services/Schools/ReportCardPdfService.php) | Reads PDF criteria from saved mark rows through the reader. |
| [app/Services/Schools/ReportCardService.php](../app/Services/Schools/ReportCardService.php) | Selects new-card curriculum, preserves started cards and locks publication against editor writes. |
| [app/Support/CapabilityCatalogue.php](../app/Support/CapabilityCatalogue.php) | Keeps the rollout grant out of Studio’s default catalogue. |
| [app/Support/CapabilityWriter.php](../app/Support/CapabilityWriter.php) | Treats hidden OFF-to-OFF writes as no-ops. |
| [app/Support/LetterGrades.php](../app/Support/LetterGrades.php) | Reads term-letter snapshots through the same mark reader, preserving list indices. |
| [app/Support/ReportCardCriteria.php](../app/Support/ReportCardCriteria.php) | Central curriculum/snapshot reader, seeding, validation, authority, revisions, history, freeze and draft activation. |
| [app/Support/SchoolCalendarCapabilityWriter.php](../app/Support/SchoolCalendarCapabilityWriter.php) | Preserves the hidden no-op when capabilities are written with calendar changes. |
| [app/Support/SchoolSettings.php](../app/Support/SchoolSettings.php) | Rejects enabling criteria for a non-school organisation. |
| [config/capabilities.php](../config/capabilities.php) | Defines the everywhere-default-off school grant. |
| [config/staging_scrub.php](../config/staging_scrub.php) | Classifies school curriculum and attribution; no child observations are stored in these tables. |
| [database/migrations/2026_10_14_210000_add_report_card_criteria.php](../database/migrations/2026_10_14_210000_add_report_card_criteria.php) | Adds five keyed tenant tables and a hidden card/band reference; populated rollback refuses loss. |
| [docs/report-card-criteria-build-report.md](../docs/report-card-criteria-build-report.md) | This handback and measured verification evidence. |
| [docs/report-card-criteria.md](../docs/report-card-criteria.md) | Staff walkthrough plus storage/HTTP/freeze contract. |
| [resources/vue-app/components/classes/ReportCardCriteriaEditor.vue](../resources/vue-app/components/classes/ReportCardCriteriaEditor.vue) | Office/teacher editing, band split/merge, ordered curriculum, permissions, history and deferred changes. |
| [resources/vue-app/composables/useClassSubjects.ts](../resources/vue-app/composables/useClassSubjects.ts) | Places Criteria beside Reports in the teacher’s subject navigation. |
| [resources/vue-app/core/types/config/BackendApiRoutes.ts](../resources/vue-app/core/types/config/BackendApiRoutes.ts) | Types the two editor endpoint shapes. |
| [resources/vue-app/core/types/data/Capability.ts](../resources/vue-app/core/types/data/Capability.ts) | Types and labels the criteria grant. |
| [resources/vue-app/core/types/data/masjid-related/ClassSubject.ts](../resources/vue-app/core/types/data/masjid-related/ClassSubject.ts) | Types the optional class-response entry flag. |
| [resources/vue-app/tests/admin-guide-forms.test.ts](../resources/vue-app/tests/admin-guide-forms.test.ts) | Supplies the new child-component stub to existing settings tests. |
| [resources/vue-app/tests/class-store-screens.test.ts](../resources/vue-app/tests/class-store-screens.test.ts) | Retains the store visibility assertion alongside the additional criteria condition. |
| [resources/vue-app/tests/report-card-criteria.test.ts](../resources/vue-app/tests/report-card-criteria.test.ts) | Six mounted SPA regressions for saves, order, split/merge, freeze/drafts, permissions and errors. |
| [resources/vue-app/views/dashboard/GeneralSettingsView.vue](../resources/vue-app/views/dashboard/GeneralSettingsView.vue) | Mounts the gated office editor beside letter-grade settings. |
| [resources/vue-app/views/teacher/TeacherClass.vue](../resources/vue-app/views/teacher/TeacherClass.vue) | Mounts the gated teacher editor and card fallback notice. |
| [routes/admin.php](../routes/admin.php) | Registers capability- and permission-gated office editor routes. |
| [routes/teacher.php](../routes/teacher.php) | Registers class-fenced teacher routes and response decoration. |
| [tests/Feature/ReportCardCriteriaTest.php](../tests/Feature/ReportCardCriteriaTest.php) | 25 feature/schema/authority/isolation/snapshot/freeze/deferred-change regressions. |
| [tests/Feature/SchoolReportCardTermLinkTest.php](../tests/Feature/SchoolReportCardTermLinkTest.php) | Asserts the added hidden band column is null on OFF requests before comparing the unchanged fixture. |
| [tests/Feature/Studio/CapabilityCatalogueEndpointTest.php](../tests/Feature/Studio/CapabilityCatalogueEndpointTest.php) | Pins the new hidden school grant in catalogue inventories. |
| [tests/Feature/TeacherMultiSchoolTest.php](../tests/Feature/TeacherMultiSchoolTest.php) | Sweeps the enabled editor in both schools, including cross-school body IDs. |
| [tests/Feature/TeacherRealmTest.php](../tests/Feature/TeacherRealmTest.php) | Explicitly authorizes the new gated criteria PUT in the teacher write inventory. |

Local `STATE.md` and `PLAN.md` also record completion and evidence.
