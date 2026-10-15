# Transcripts, credits and GPA

Built on base `30cc9708`. The `transcripts` school grant defaults OFF for every organisation type. No school was enabled by this build. It requires the `letter_grades` grant; disabling both in one operation is supported. Disabled grants, overrides, operator history/catalogues, Studio choices and navigation remain hidden. No `masjids` column was added.

## Office workflow

Open **Transcripts and GPA** after the grant is enabled. Expand **School GPA policy and course settings**. Set credit for each high-school class subject as a decimal with at most two places, and choose Regular, Honors or AP. Credit has no guessed default; official issue refuses an unset credit. The screen initially offers the existing school scale and the default points table; custom scale letters can be assigned points, and additional prior-school letters can be added. Save the complete points table, failing letters and extras together.

The defaults are A+ 4.00, A 4.00, A− 3.70, B+ 3.30, B 3.00, B− 2.70, C+ 2.30, C 2.00, C− 1.70, D+ 1.30, D 1.00, D− 0.70, F 0.00; Honors +0.50, AP +1.00; F fails. Credits are 0.00–100.00, points/extras 0.00–10.00, at most two decimal places. These are input ceilings, not estimates of a school's actual courses.

Choose a student, including a retained former student, to inspect the live unofficial document, annual finals, prior years, official issues, outgoing requests and received copies. Student search is paginated. A final override requires a current scale letter and a staff-only reason; the server records staff identity and time. Clearing it restores the calculated letter. Teachers have the same annual-final editor under Reports, restricted to their own class and assigned subject IDs. Teachers never receive birth dates, prior-school years, other subjects or full transcript documents from that endpoint.

Prior years require a school name, year and grade 9–12. Enter courses with letter, credit and level, upload a PDF, or do both. Optional dates are retained as calendar dates. Uploads require sniffed PDF content and a .pdf filename, and use the existing private class-files writer, with staff visibility; the office selects this student's roster row for the file. Existing prior years can be edited. The full school name is retained in its TEXT column; the reused class-file title is limited to that table's existing 200-character ceiling. A file alone contributes neither GPA nor credits, and this is stated on the transcript. Prior courses use the receiving/issuing school's current points and failing-letter policy; an unknown letter requires a points-table entry. Prior years below grade 9 cannot be entered through this screen.

**Issue a new official transcript** freezes a new JSON document and PDF, issuer, issue time and UUID serial. Repeating the action creates another issue; it never edits an earlier one. Download the desired issue for manual sending. Official PDFs include school name, address and embedded raster logo if held; student name, held birth date, grade and held enrolment dates; annual courses, final letters, credits attempted/earned, both annual and cumulative GPAs; signature line, issue date, issuer and serial. No attendance or rank is added.

Families see a live **Unofficial** document and downloadable watermarked PDF on their class's Transcript tab. They see their ward only. They do not see override reasons, staff provenance or another family's records. All new portal labels have English, Arabic, Urdu, Pashto, Dari, Spanish and French keys; additions are machine drafts awaiting fluent review. Academic document PDFs follow the existing report-card English document convention, with mpdf's script/font handling for names and course text.

## Exact method

For each student, class subject and school year, sum the percentages from `term_grades` whose source report card is still published, and divide by the number of non-null percentages, with equal weight. Compare that rational average directly with the school's letter-scale thresholds. A term override letter does not replace that term's percentage; the annual override is the explicit way to set a different annual letter. Missing percentages do not become zero or F. The year's grade comes from its earliest published dated report-card grade snapshot through `GradeLevel`; current roster promotion cannot retroactively admit a pre-grade-9 year. The student header separately shows the held current or last retained roster grade, and an existing official header stays frozen.

Credits, points and extras are integer hundredths. Published DECIMAL percentages are read without the legacy model's float cast, converted to integer hundredths, and averaged as a sum/count. For graded courses:

`plain = sum(credit_hundredths * points_hundredths) / (100 * sum(graded credit_hundredths))`

`weighted = sum(credit_hundredths * (points_hundredths + level_extra_hundredths)) / (100 * sum(graded credit_hundredths))`

The level extra applies according to the chosen course level. Failed letters remain in the GPA denominator and earn zero credit. Configured ungraded courses still show attempted credit, earn none and contribute no GPA. Zero total graded credit produces no GPA. Yearly and cumulative figures use the original integer products; cumulative GPA never averages rounded yearly figures. Only the displayed final ratio rounds half up to two places. The staff screen states the method.

Hand fixture proved by the feature suite:

| Course | Letter | Credit | Level | Plain contribution | Weighted contribution |
|---|---|---:|---|---:|---:|
| Language | A | 1.00 | Honors | 4.00 | 4.50 |
| Science | B | 1.00 | AP | 3.00 | 4.00 |
| Art | A | 0.50 | Regular | 2.00 | 2.00 |
| History | F | 1.00 | Regular | 0.00 | 0.00 |

3.50 attempted, 2.50 earned; plain 9/3.5 = 18/7 → **2.57**; weighted 10.5/3.5 = **3.00**. Another fixture proves 89.995% selects B+ before its displayed percentage rounds to 90.00.

## Sharing state machine

| State | Allowed action | Next state | Receiving office disclosure |
|---|---|---|---|
| awaiting_family | Guardian portal consent, or office record of signed paper consent | pending | Nothing; even the request ID is 404 |
| awaiting_family | Sending office or guardian withdraws | withdrawn | Nothing |
| pending | Receiving office accepts and chooses its enrolled student, or keeps unlinked | accepted | Copy of precisely this student's issued official snapshot and PDF |
| pending | Receiving office declines | declined | Header and its decision history |
| pending | Sending office or guardian withdraws | withdrawn | Header and withdrawal time |
| accepted / declined / withdrawn | Further consent, accept, decline or withdraw | Refused | No new copy or state change |

A pending header has exactly request ID, sending school name, this student's frozen name and birth date as given, status and requested time. It contains no grades, file, staff reasons, source contact ID or issue pointer. Both schools must have transcripts enabled for consent and acceptance. A receiving office may decline without the sender remaining enabled. An issued copy remains downloadable at the receiving school after the sender disables transcripts or its original PDF becomes unavailable.

Acceptance uses the latest already-issued official transcript. A later grade change does not rewrite that issue; the sender should issue a new one if it wants newer grades sent. When no issue exists, acceptance creates one under the sending office request author's recorded authority. A deleted request author without a surviving issue requires the sender to issue a copy explicitly. Acceptance stores the copied bytes in the receiver's own directory and copies the JSON into a receiver-owned row. `source_request_id` is an inert uniqueness/provenance token, with no foreign key or live source reader. Received records contain no file path or live record link back to the source. No received snapshot is silently added to the receiving student's GPA; the office can separately enter prior courses using its own grading policy.

The sender retains requested/consented/resolved actors and times, consent method/evidence and the sent issue ID. The receiver sees header history and its own read-only received copies. An unlinked copy can later be linked once to an enrolled student's local record. It cannot be edited or re-linked through the product. Requests and accepts lock both schools in ascending ID order, then the request. Source issue creation runs only inside this bounded, consented transfer, for that request's student. The ordinary tenant scope remains in place on receiving copies.

## Storage and retention

All seven new tables have primary IDs and `BelongsToMasjid`; no new table keys a roster row, so `AcademicRecordsHeld` needs no new entry. Existing report-card records already hold the source roster rows.

| Table | Ownership and contents |
|---|---|
| transcript_settings | One school; complete integer points/extras and failing letters |
| transcript_courses | One school/class subject; exact credit hundredths and level |
| transcript_finals | One school/student/subject/year; annual override, staff-only reason, actor/time |
| transcript_prior_years | One school/student; prior-school text, dates, typed courses, private class-resource link, entry actor |
| transcript_issues | Issuing school/student; immutable JSON, UUID serial, private PDF pointer/SHA-256, issuer/time |
| transcript_share_requests | Sending school/student; receiving school, frozen header, state and consent/resolution provenance |
| transcript_received | Receiving school; optional local student, inert request token, frozen origin/serial, copied JSON/PDF/SHA-256, acceptor/time |

Official files: private `local` disk, `transcripts/{school}/issued/{uuid}.pdf`. Received files: `transcripts/{receiver}/received/{uuid}.pdf`. Prior PDFs: existing `GroupResourceFiles` path `group-resources/{school}/{class}/{random}.pdf`, staff-only. Downloads require authenticated ownership checks, return private/no-store responses, and expose no public or signed URL. Issued and received PDF downloads verify SHA-256. Failed acceptance removes newly written transfer/issue files.

Contact FKs on new academic tables are RESTRICT; user attribution FKs are nullable/SET NULL following existing academic conventions. Member login deletion uses five separately pinned table-exists/existence reads and keeps academic and consent records. The original App101 fixture remains untouched. Ordinary contact merge refuses to erase a transcript identity or consent history. A retained prior PDF is refused before the existing class-file hook can delete its bytes, including after switch-off. Rollback refuses populated academic tables before dropping anything; empty rollback/forward is tested locally and provided for the MySQL CI suite.

Staging drops all seven tables' rows, child first. This classifies every column, including snapshot JSON, staff reasons, prior-school text, birth dates, filenames and consent evidence, as private production material or configuration reset with it. The database scrub does not erase disk bytes; exclude the private transcript and class-file roots from staging data refreshes.

## Reuse

- `LetterGrades`, `term_grades`, dated `SchoolTerm`/`SchoolYear`, and frozen `ReportCard.grade_label`; publication behavior and existing letter/report-card tests are unchanged.
- `GradeLevel` for supported grade order and high-school eligibility.
- Existing `ClassSubject` identity and `SubjectFence` for teacher course authority.
- `Contact::dateOfBirthOrNull()` for the encrypted held birth date; no new birth-date store on contacts.
- `FamilyController::subject`, `GroupAudience`, named-school teacher routes, tenant context and office contacts permissions.
- `GroupResourceFiles`/`GroupResource` for uploaded prior PDFs; no second public attachment mechanism.
- Existing mpdf Letter format, private temp directory and automatic script/language font conventions for PDFs.
- Existing hidden-grant catalogue/writer/ledger patterns and additive response middleware, preserving OFF source bodies and fixtures.

Verification and exact totals are recorded in `transcripts-build-report.md`. The later demo/guide walk, real browser behavior, fluent translation review, MySQL execution and deployed behavior are not claimed by this local build.
