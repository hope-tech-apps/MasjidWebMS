# Transcript build evidence

Uncommitted build on `feature/transcripts-gpa`, base `30cc9708`. No deployment, production data change, switch-on, email, payment or commit. Existing query-count artifact preserved.

Implementation and local verification are complete. The full PHP suite was run once; subsequent corrections were checked with targeted suites, without repeating the full run. Product behavior, exact arithmetic, reuse, storage and state machine: [transcripts.md](transcripts.md).

## Verification

- Initial domain red: 7 failed, 0 assertions, missing implementation; `/tmp/transcripts-red.log`. Initial mounted SPA red: 2 failed; `/tmp/transcripts-spa-red.log`.
- Final domain/schema: **22 passed, 250 assertions**, 3.48s; `/tmp/transcripts-final-domain.log` and `/tmp/transcripts-final-domain.xml`. Includes exact arithmetic, held birth date in consented header/copy, current header grade versus frozen yearly grade, genuine PDF bytes with an unsafe extension, long school-name title capacity, malformed multipart JSON, optional dates, both consent paths and source-independent received files.
- Tenant/staging/deletion/source/OFF/Studio targeted guards with the then-current 16 domain cases: 110 passed, 1997 assertions; `/tmp/transcripts-final-target.log`. The existing-issued-snapshot and unrounded-average cases were added before the full run. The malformed-JSON and optional-date cases were added during the final audit after that run.
- Teacher route/family integration sweep: 92 passed, 656 assertions; `/tmp/transcripts-sweep-green.log`. New teacher routes have real positive ON controls, including tenant/subject attacks; prior report-card control paths retain their OFF policy.
- Final scoped roles ON diagnostic: **92 passed, 4 failed, 577 assertions**. All four match the unchanged expected-failures list, and the judge prints Exactly the expected tests failed, and nothing else. `/tmp/transcripts-roles-final.log`, `/tmp/transcripts-roles-final.xml` and `/tmp/transcripts-roles-final-judged.log`. This was the diagnostic subset, not a second full PHP run.
- Entire SPA: 2189 passed, 2 failed, 1 skipped (2192 tests), 213868.769958 ms; `/tmp/transcripts-full-spa.log`. Failures are unchanged photo-fill cases at class-subjects-screens.test.ts:2165 and :2205, whose ISO-date fixture disagrees with the screen after UTC/local midnight. Isolated default-zone control reproduces both failures (0 passed, 2 failed); the same two cases pass with TZ=UTC (2 passed, 0 failed). Logs: `/tmp/transcripts-photo-local.log` and `/tmp/transcripts-photo-utc.log`. The source test file is unchanged.
- New mounted transcript checks cover guardian consent, decimal course transport and no invented consent, teacher final override transport/reason, and visible GPA/absence of staff reasons. Seven-language key parity checks pass.
- `git diff --check` clean; tracked whitespace/non-whitespace diff sizes are close. No formatter or frozen-fixture capture. Added-source scan: no non-synthetic emails or secret-like markers.

## Entire PHP run and final corrections

Printed totals from the one ENTIRE PHP run: **16 failed, 7 skipped, 11288 passed (185031 assertions)**. Duration **818.16s**; process exit 2. `/tmp/transcripts-full-php.log` and `/tmp/transcripts-full-php.xml`. PHP sources stayed fixed throughout this run; the 40-file PHP hash inventory was checked before subsequent edits. The full suite was not repeated after the fixes below.

Eleven failures were the specified local environment cases: BackupRunTest 2 archive cases; ExampleTest and HostHeaderUrlIntegrityTest 1 missing-build case each; ResendTransportTimeoutTest 4 socket cases; TenancyCanaryTest 3 socket cases.

Five additional failures were found and corrected:

| Test | Failure and final correction |
|---|---|
| StudentAllergiesTest | Its family GET sweep expected every new read to succeed while transcripts were OFF. Give transcript routes positive ON controls and inspect their actual JSON and decoded PDF streams for the existing private-health sentinel. |
| StudentBirthDateTest | Extend its accessor-reader allowlist for the two owner-authorized academic readers. Preserve its prohibition on direct Contact date-property reads: the inbox now projects a distinct stored-header alias rather than reading a Contact property. |
| Studio\CapabilityCatalogueEndpointTest | Add transcripts to the ordered school-key inventory and hidden-grant exclusions; explicitly assert it is absent from the Studio catalogue. |
| TeacherRealmTest | Pin the one new subject-fenced, capability-gated annual-final write. |
| UploadFileNameCoverageTest | Add extensions:pdf beside sniffed MIME validation, with a plain refusal sentence. A real UploadedFile holding genuine PDF bytes under .html was observed accepted before correction and is refused after it. |

Final targeted run covering all five guards, transcript behavior/schema, tenant coverage, staging and the frozen App101 contract: **302 passed (4675 assertions)**, **32.62s**; `/tmp/transcripts-final-green.log` and `/tmp/transcripts-final-green.xml`. The preceding source/OFF-reader run passed its unchanged fixtures; `/tmp/transcripts-final-corrections.log` also shows those source guards passing. The remaining failure in that intermediate run led to the stronger inbox projection above. Two audit regressions were observed red for the stale header grade and a non-string courses_json server error; `/tmp/transcripts-audit-red.log`. Final source/behavior fixes and further held-DOB assertions are included in the final 22-case domain/schema run and the final roles diagnostic.

## Build and artifact review

One `npm run build`: **passed in 10.91s**, process exit 0. `/tmp/transcripts-build.log`. Existing Browserslist, font-resolution and chunk-size advisories remain. The generated manifest had 230 entries, including TranscriptsView, FamilyTranscript and the shared document/final-editor chunks. Inspected compiled office/family/teacher labels, including unofficial status, consent, credit totals, staff-only reason and annual-final entry. All six marker checks passed; `/tmp/transcripts-build-inspection.json`. **public/build was then removed.** No second build was run.

Newly generated untracked test artifacts were removed, with their inventory in `/tmp/transcripts-cleaned-artifacts.txt`. The pre-existing untracked subject-report-summary-query-counts.json was retained. The full suite unconditionally refreshes it; its original bytes were not captured, so original byte identity is NOT verified. No frozen fixture or CHANGELOG was changed, no CAPTURE_* variable was set, and no formatter was run.

## NOT verified

MySQL DDL/rollback and two-connection locking (MySQL CI test supplied); CI, staging or production; a real-browser walk or accessibility/layout; fluent translations; the later demo/guide session. No live student data or organisations were used. PDF tests render actual mpdf bytes locally but do not claim visual approval.

## Changed files and purpose

| File | Purpose |
|---|---|
| `ASSUMPTIONS.md` | Record owner decisions, current-header versus annual-grade policy, and verification assumptions. |
| `DECISIONS.md` | Record owner decisions, current-header versus annual-grade policy, and verification assumptions. |
| `app/Http/Controllers/AdminDashboard/ContactsController.php` | Refuse destruction of retained prior PDF bytes or transcript identity before existing destructive work. |
| `app/Http/Controllers/AdminDashboard/MasjidsController.php` | Extend hidden-grant writer/serializer behavior while preserving existing OFF reader bodies. |
| `app/Http/Controllers/AdminDashboard/TranscriptsController.php` | Extend hidden-grant writer/serializer behavior while preserving existing OFF reader bodies. |
| `app/Http/Controllers/Family/TranscriptsController.php` | Extend hidden-grant writer/serializer behavior while preserving existing OFF reader bodies. |
| `app/Http/Controllers/Teacher/TranscriptFinalsController.php` | Extend hidden-grant writer/serializer behavior while preserving existing OFF reader bodies. |
| `app/Http/Middleware/AppendTranscriptAccess.php` | Extend hidden-grant writer/serializer behavior while preserving existing OFF reader bodies. |
| `app/Models/Contact.php` | Document the newly authorized academic birth-date accessor uses; accessor implementation unchanged. |
| `app/Models/GroupResource.php` | Refuse destruction of retained prior PDF bytes or transcript identity before existing destructive work. |
| `app/Models/Masjid.php` | Extend hidden-grant writer/serializer behavior while preserving existing OFF reader bodies. |
| `app/Models/TranscriptCourse.php` | Tenant-owned configuration/academic storage; immutable issued and received documents. |
| `app/Models/TranscriptFinal.php` | Tenant-owned configuration/academic storage; immutable issued and received documents. |
| `app/Models/TranscriptIssue.php` | Tenant-owned configuration/academic storage; immutable issued and received documents. |
| `app/Models/TranscriptPriorYear.php` | Tenant-owned configuration/academic storage; immutable issued and received documents. |
| `app/Models/TranscriptReceived.php` | Tenant-owned configuration/academic storage; immutable issued and received documents. |
| `app/Models/TranscriptSetting.php` | Tenant-owned configuration/academic storage; immutable issued and received documents. |
| `app/Models/TranscriptShareRequest.php` | Tenant-owned configuration/academic storage; immutable issued and received documents. |
| `app/Services/Member/MemberAccountDeletion.php` | Guarded existence reads retain academic/consent records during member login erasure. |
| `app/Services/Schools/TranscriptPdf.php` | Published-grade projection, GPA, PDF rendering or consented snapshot transfer. |
| `app/Services/Schools/TranscriptShares.php` | Published-grade projection, GPA, PDF rendering or consented snapshot transfer. |
| `app/Services/Schools/Transcripts.php` | Published-grade projection, GPA, PDF rendering or consented snapshot transfer. |
| `app/Support/CapabilityCatalogue.php` | Extend hidden-grant writer/serializer behavior while preserving existing OFF reader bodies. |
| `app/Support/CapabilityWriter.php` | Extend hidden-grant writer/serializer behavior while preserving existing OFF reader bodies. |
| `app/Support/SchoolCalendarCapabilityWriter.php` | Extend hidden-grant writer/serializer behavior while preserving existing OFF reader bodies. |
| `app/Support/SchoolSettings.php` | Extend hidden-grant writer/serializer behavior while preserving existing OFF reader bodies. |
| `app/Support/TranscriptMath.php` | Exact arithmetic, hidden grant/dependencies or retention guards. |
| `app/Support/TranscriptMode.php` | Exact arithmetic, hidden grant/dependencies or retention guards. |
| `app/Support/TranscriptRecords.php` | Exact arithmetic, hidden grant/dependencies or retention guards. |
| `config/capabilities.php` | Default-OFF grant or complete staging column classification via dropped rows. |
| `config/staging_scrub.php` | Default-OFF grant or complete staging column classification via dropped rows. |
| `database/migrations/2026_10_15_000100_create_transcripts.php` | Seven tenant tables, primary keys, restrictive academic references and guarded rollback. |
| `docs/transcripts-build-report.md` | Focused recon, complete behavior/storage/method/state machine or build evidence. |
| `docs/transcripts-recon.md` | Focused recon, complete behavior/storage/method/state machine or build evidence. |
| `docs/transcripts.md` | Focused recon, complete behavior/storage/method/state machine or build evidence. |
| `resources/views/pdf/transcript.blade.php` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `resources/vue-app/components/classes/TeacherTranscriptFinals.vue` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `resources/vue-app/components/classes/TranscriptDocument.vue` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `resources/vue-app/components/classes/TranscriptFinalEditor.vue` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `resources/vue-app/core/access/orgAccess.ts` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `resources/vue-app/core/constants/dashboardAsideMenuItems.ts` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `resources/vue-app/core/types/data/Capability.ts` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `resources/vue-app/router/router.ts` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `resources/vue-app/router/routes/dashboardLayoutRoutes.ts` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `resources/vue-app/tests/transcripts.test.ts` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `resources/vue-app/views/dashboard/TranscriptsView.vue` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `resources/vue-app/views/family/FamilyClass.vue` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `resources/vue-app/views/family/FamilyTranscript.vue` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `resources/vue-app/views/family/familyI18n.ts` | Seven-language portal word parity with machine-draft markings. |
| `resources/vue-app/views/family/locales/es.ts` | Seven-language portal word parity with machine-draft markings. |
| `resources/vue-app/views/family/locales/fa-AF.ts` | Seven-language portal word parity with machine-draft markings. |
| `resources/vue-app/views/family/locales/fr.ts` | Seven-language portal word parity with machine-draft markings. |
| `resources/vue-app/views/family/locales/ps.ts` | Seven-language portal word parity with machine-draft markings. |
| `resources/vue-app/views/family/locales/ur.ts` | Seven-language portal word parity with machine-draft markings. |
| `resources/vue-app/views/teacher/TeacherClass.vue` | Office, teacher or guardian controls/document/PDF display and hidden navigation. |
| `routes/admin.php` | Named-school office/teacher/guardian endpoints under existing guards. |
| `routes/family.php` | Named-school office/teacher/guardian endpoints under existing guards. |
| `routes/teacher.php` | Named-school office/teacher/guardian endpoints under existing guards. |
| `tests/Feature/App101OffContractTest.php` | Domain/schema/security/privacy/OFF/realm/Studio regression coverage; no fixture recapture. |
| `tests/Feature/FamilyPortalTest.php` | Domain/schema/security/privacy/OFF/realm/Studio regression coverage; no fixture recapture. |
| `tests/Feature/MemberAccountDeletionCoverageTest.php` | Domain/schema/security/privacy/OFF/realm/Studio regression coverage; no fixture recapture. |
| `tests/Feature/StudentAllergiesTest.php` | Domain/schema/security/privacy/OFF/realm/Studio regression coverage; no fixture recapture. |
| `tests/Feature/StudentBirthDateTest.php` | Domain/schema/security/privacy/OFF/realm/Studio regression coverage; no fixture recapture. |
| `tests/Feature/Studio/CapabilityCatalogueEndpointTest.php` | Domain/schema/security/privacy/OFF/realm/Studio regression coverage; no fixture recapture. |
| `tests/Feature/TeacherMultiSchoolTest.php` | Domain/schema/security/privacy/OFF/realm/Studio regression coverage; no fixture recapture. |
| `tests/Feature/TeacherRealmTest.php` | Domain/schema/security/privacy/OFF/realm/Studio regression coverage; no fixture recapture. |
| `tests/Feature/TranscriptsSchemaTest.php` | Domain/schema/security/privacy/OFF/realm/Studio regression coverage; no fixture recapture. |
| `tests/Feature/TranscriptsTest.php` | Domain/schema/security/privacy/OFF/realm/Studio regression coverage; no fixture recapture. |
| `tests/Mysql/TranscriptsSchemaMysqlTest.php` | Domain/schema/security/privacy/OFF/realm/Studio regression coverage; no fixture recapture. |

Local working checkpoints `STATE.md` and `PLAN.md` were also updated. The retained pre-existing query-count artifact is outside this change inventory.
