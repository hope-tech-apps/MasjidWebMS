# Transcript walk corrections

Fix round on `feature/transcripts-gpa`, starting at `37ee3f7a` over main `0e0bdb51`. No commit, push, deployment, production interaction or school switch-on. The owner's explicit receiving-school student fence remains intact.

## Changes

- Per-class-subject **On the transcript: Yes / No** persists beside nullable credit and level. Existing course settings default to Yes. Excluded courses remain in staff final editors, while transcript documents and totals exclude them.
- Ungraded courses need no credit to issue and show **Not graded** with empty attempted/earned cells. Attempted credit sums only included courses with an annual final letter; earned credit sums only their passing courses. Exact integer arithmetic and the hand fixture remain unchanged: **3.50 attempted, 2.50 earned, 2.57 plain GPA, 3.00 weighted GPA**.
- Office issue/share/received/inbox timestamps use the existing school clock; birth and attendance dates keep their calendar day. Dates attended use earliest joining day, otherwise recorded move day or school-local roster creation day, through Present or the final leaving date. Staff/guardian names replace numeric actor labels. The PDF carries only the issuing office identity and school.
- PDF grades read **Grade 10**; cumulative content, method and signature form one page-break group.
- Share forms warn when grade records are newer than the latest official issue and offer **Issue a new official copy first**. Existing issued copies remain immutable.
- Family history retains accepted, declined and withdrawn requests; acceptance names the receiving school and local acceptance day, with no remaining consent/withdraw controls.
- Student search defaults to grade 9 and above through GradeLevel before pagination, with a **Show all students** toggle.

## Verification

- `php -d memory_limit=-1 vendor/bin/pest tests/Feature/TranscriptsTest.php tests/Feature/TranscriptsSchemaTest.php tests/Feature/StagingScrubCoverageTest.php tests/Feature/App101OffContractTest.php tests/Feature/SchoolCalendarOffQueryDifferentialTest.php tests/Feature/ClassSubjectReportSummaryTest.php --log-junit /tmp/transcripts-walk-gates.xml`: **66 passed, 1462 assertions**, 7.63s. Domain 26 tests, schema 2, scrub 4; frozen contracts 34. `/tmp/transcripts-walk-gates.log` and `.xml`.
- `node --experimental-strip-types --test resources/vue-app/tests/transcripts.test.ts resources/vue-app/tests/family-i18n-keys.test.ts`: **10 passed**, including 7 mounted transcript tests and 3 translation-key tests. `/tmp/transcripts-walk-spa.log`.
- `npm run build`: **passed**, 12.59s; known font/Browserslist/chunk advisories. Inspected manifest and compiled transcript chunks for visibility, stale-copy reissue, show-all and retained outcomes. Generated `public/build` removed. `/tmp/transcripts-walk-build.log` and `/tmp/transcripts-walk-build-inspection.json`.
- Fourteen synthetic PDFs with 1–50 courses: `pdftotext -layout` verified signature and cumulative content share a page, readable dates, Grade 10 and empty ungraded credit cells. Visually inspected the last page of the 18-course/two-page case. `/tmp/transcripts-walk-pdf-inspection.json`, `/tmp/transcripts-walk-layout-*.pdf`, `/tmp/transcripts-walk-last-page.png`.
- `php -d memory_limit=-1 vendor/bin/pest --log-junit /tmp/transcripts-walk-full.xml`: **11,340 passed, 7 skipped, 11 failed; 186,980 assertions**, 871.81s. Ran the entire default PHP suite exactly once after the fixes. Every transcript case passed. The 11 failures are exactly the expected seven socket, two archive and two missing-build failures; **no other failures**. `/tmp/transcripts-walk-full.log` and `/tmp/transcripts-walk-failures.json`.

Expected failure inventory: ResendTransportTimeoutTest (4) and TenancyCanaryTest (3), all loopback bind denied; Backup\BackupRunTest (2), archive file-count differences; ExampleTest (1) and HostHeaderUrlIntegrityTest (1), absent Vite manifest. No fixture or failure-expectation list was changed.

The full-run JUnit logger undercounted after the Resend setup errors: its root reports 7,947 tests and later suites have zero testcase nodes despite their passing text output. Totals above come from the complete CLI summary, independently checked against exactly 11,340 rendered pass marks and 11 final failure blocks. Targeted JUnit output records all 66 cases normally. This discrepancy is recorded in the lessons inbox; its cause is unchecked.

`git diff --check` and PHP syntax checks of all 11 changed PHP/Blade files passed. Tracked whitespace/non-whitespace diff counts match. Added-source scan found no non-synthetic emails or secret-like tokens. Removed 39 new suite artifacts and retained the pre-existing subject-report-summary query-count artifact, which matches its pre-full-suite backup. No formatter, fixture capture or CHANGELOG change.

New school-local-date tests travel to fixed UTC instants, including Oct 16 at 03:06 UTC (Oct 15 at 11:06 PM at the school). Frozen contract fixtures remain unchanged. The new migration is covered by staging scrub classification and local rollback/forward checks. MySQL execution and deployed browser behavior remain unverified. Added family translations retain the existing machine-draft status.

## Code pointers

- `app/Support/TranscriptMath.php:44`: graded-only credit/GPA totals and visibility exclusion.
- `app/Services/Schools/Transcripts.php:50`: included documents and staff final provenance; `:86` stale-issue test; `:103` graded-only issue requirement.
- `app/Http/Controllers/AdminDashboard/TranscriptsController.php:32`: GradeLevel student filter; `:73` nullable credit and visibility save; `:198` names and school-local history.
- `resources/views/pdf/transcript.blade.php:1`: readable dates and Grade 10; `:24` cumulative/signature page grouping.
- `resources/vue-app/views/dashboard/TranscriptsView.vue:57`: stale-issue warning and reissue action.
- `app/Http/Controllers/Family/TranscriptsController.php:22` and `resources/vue-app/views/family/FamilyTranscript.vue:10`: retained resolution history.
- `tests/Feature/TranscriptsTest.php:380`: new regression cases.
