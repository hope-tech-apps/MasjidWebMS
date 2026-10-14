# Term letters and report cards

The `letter_grades` rollout grant is off for every organisation by default and absent from disabled catalogues, capability maps and raw overrides. Once given to a school, its office sees the letter-grade controls under General Settings. The office saves its on/off choice, starting grade and whole letter scale together. Class subjects and dated terms must exist before letters can be enabled; settings explain the prerequisite before enabling is available.

The school selects a starting grade using the existing `GradeLevel` teaching order. Each child's `group_memberships.grade_label` decides eligibility, including combined classes. Unknown grades retain levels and the staff card explains why. Progress reports and existing academic skill/behaviour lines retain their current behavior.

## Calculation and publication

`GradeRecord::forTerm()` calls the gradebook's `summaryForClassSubjects()`, with inclusive assignment dates. It uses the existing type pools, relative weights and per-assignment overrides. Missing points count as zero, excused work stays out, and levels/simple marks are never converted into percentages. Classes without weights use the existing pooled points percentage. Letters compare against the gradebook's displayed one-decimal percentage. The scale lookup itself never rounds: 89.99 is below a 90 cut-off.

Teachers see calculated letter/percentage and may choose another scale letter with a required reason and a family comment. The server owns actor and time. Only the subject's assigned teacher can edit it; the office is read-only. Skill edits and letter edits save atomically. Dated term names, including semesters and trimesters, are offered in Reports for classes with eligible students. A class containing only younger grades keeps its existing Reports controls. Current-term defaults use the existing school-local day authority, including near UTC midnight. A selected term can be inspected through the staff gradebook's existing `members/{id}/grades?school_term_id={id}` read, using the same function and subject fence.

Sending the card freezes calculated percentage/letter, final letter, family percentage/comment, and publication time on `report_card_marks` rows whose kind is `term_grade`. A teacher override that differs from the calculated letter hides the percentage on that subject's family page and PDF. Both outputs share an explicit final-only allowlist; no reason, calculated grade or actor appears. No counted points produce a null calculated letter, never an F. A teacher may still explicitly set a letter with evidence.

The term-grade criterion also freezes the dated term heading, reusing its existing 200-character snapshot text (term names are limited to 80). A later scale, cutoff, gradebook, calendar heading/date or subject-name edit does not recompute a sent card. Take-back preserves the last issued term record but withdraws the family card; re-send replaces the snapshot and record. A second card for the same student/subject/term cannot overwrite another card that is still sent. School terms and years containing issued term grades cannot be removed.

## Storage and contracts

`masjids.letter_grade_settings` is nullable hidden JSON: `{enabled, from_grade, scale: [{letter, minimum}]}`. A scale has 1–40 rows, unique nonblank letters of at most 20 characters, strictly descending unique numeric cut-offs from 0–100, and a final cut-off of 0. Saving the whole school row under its mutex prevents partial scales; there is no separate row-level scale lifecycle to justify another table.

`term_grades` has an `id` primary key and a unique `(contact_id, class_subject_id, school_term_id)` index. Each row also carries `masjid_id`, source `report_card_id`, snapshotted `subject`, nullable `final_letter`, nullable calculated `percentage`, family `comment`, `published_at`, and normal timestamps. Contact identity survives roster moves. The table uses `BelongsToMasjid`, and references use restrictive foreign keys so academic history is retained. The existing dated table is `school_terms`; `school_calendar_terms` is the grant's name.

Office settings use `GET` and `PUT /api/admin/masjids/{id}/letter-grade-settings`. PUT requires the complete configuration, accepts browser-encoded booleans, validates the entire scale, and checks prerequisites under the school lock. Teacher saves reuse the existing report-card PUT with an optional `letter_grades` array of `{id, override_letter, override_reason, comment}`. Staff report responses conditionally include `letter_grades` and `letter_scale`; family responses conditionally include only final subject rows. Disabled and younger-grade responses add no letter keys.

Comments, override reasons and final comments are child-related free text and use the existing staging scrub policy. Every new column is classified. Raw mark serialization hides every new column. The snapshot guards exclude only explicitly checked empty new fields, leaving their original fixtures unchanged. New family labels exist in all seven languages; new non-English labels are machine-drafted and await fluent review.

## Local walkthrough for the guide session

Use a synthetic school with class subjects, dated quarters and letters from Grade 3. Enable the rollout grant and then the office setting. Set type weights, enter points work within the first term, include a missing and an excused mark, plus one level-marked assignment and one subject-piece level. Open the teacher's Reports box and compare its percentage with the term gradebook read. Set another letter with a reason and a family comment, send it, then inspect staff, family JSON, page and PDF. Edit the scale and points and verify the sent card stays unchanged. Take back and re-send to refresh it. Repeat with a Grade 2 student in the combined class and with the school switch off to confirm the existing level card stays unchanged.

Automated evidence and any unverified manual/deployment checks are recorded in the build report. This change does not install guide pages or enable a school in production.
