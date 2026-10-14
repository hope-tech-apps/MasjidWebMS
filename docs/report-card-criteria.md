# Report-card criteria by grade band

The `report_card_criteria` school grant is default off. This migration never enables
it. While off, the editor, teacher entry, capability catalogue entry and history are
hidden; report cards use the existing template. Letter grades remain an independent
feature.

## Staff walkthrough

With the grant on, the office opens **General Settings → Report-card criteria**,
beside the letter-grade settings. A teacher opens a class, then **Criteria** beside
**Reports**. The first opening seeds Pre-K, Kindergarten and Grade 1 and up from the
existing template, including the school's core-subject restriction. Each band has
the existing learning behaviours.

The office can rename a band, adjust its first and last grade, choose the default,
split a range beside its original band, or merge a band into its neighbour by
removing it. Bands must cover Pre-K through 12th in the existing grade order, with
no gaps or overlaps and exactly one default. Grade aliases use `GradeLevel`.
Unknown or missing student grades use the default; the teacher's card explains why.
A combined class uses each student's grade, rather than the class's oldest grade.

Subjects use the existing report-card names or visible class-subject names when
class subjects are enabled. Staff can add, rename, reorder and remove subjects and
criteria. Learning behaviours have their own editable ordered list. Criteria must
be non-empty and unique within a subject, at most 200 characters each, and a band
can have at most 200 skill/behaviour rows. The three core subject topics remain the
only academic topics shown for a core-subject school.

School admins edit everything. Teachers edit only subjects they teach in a class
with current students in that band. Class-subject mode uses the assigned class
subject IDs and existing subject-name aliases; the older mode uses the existing
staff-subject fence. Only admins edit learning behaviours, including in classes
whose teacher has no subject restriction. Teachers cannot change bands, ranges,
defaults, unassigned subjects or learning behaviours. The server returns 403 for these
writes. Curriculum is shared school reference material; reading another band's
criteria gives no access to students in that band.

## Publication and next-term changes

As soon as a report card or progress report using a band is sent, that band's
criteria are read-only for the reporting period. Dated terms come from
`school_terms` when `school_calendar_terms` is enabled. Without dated terms, the
period is the school year. A persistent publication lock survives taking a card
back. Cards already sent before the first editor opening also establish locks.

Choose **Changes apply from next term**, edit, then **Save criteria** to save a
deferred draft. A school using dated terms must configure its next term first;
the editor explains this if there is no next term. The draft is associated with
that term and follows a change to its start date. Without dated terms, the next
configured school-year start is used. If the next year is not configured, the
current year's start repeats one year later; with no year dates, the existing
August rollover is used. This fallback is recorded in `ASSUMPTIONS.md`.

The editor reopens a saved draft automatically. **Edit current criteria** returns
to the current configuration; frozen bands remain locked. Unrelated edits to an
unfrozen band are carried into a waiting draft. Conflicting changes require the
staff member to edit the saved draft explicitly. A revision check refuses stale
saves from another tab or staff member. Recent changes show staff and school-local
time, for example **Oct 14, 2026, 5:17 PM**. Applies-from dates read **Oct 26, 2026**;
they are calendar dates and are never shifted by a browser's timezone.

The next reader/editor/preparation request at or after the school's local start
date activates a due draft under the school lock. No scheduler is needed. Existing
cards, including unfinished cards, keep their saved mark rows. A new card uses the
configuration in force for its own dated term, or its school year without dated
terms, using the same period identity as the publication lock. A late-started Q1
card keeps Q1's criteria after Q2 or Q3 drafts apply. A draft affects its effective
period and later periods only. The editor continues to show current criteria and
the pending draft. A later editor save never renames, deletes or reorders a card's
saved marks. PDF, family views, records exports and term letters read those marks.

## Storage and HTTP contract

- `report_card_bands`: tenant, name, ordered inclusive grade positions, default,
  order and archive flag. Removed bands stay available to old card references.
- `report_card_criteria`: tenant, band, subject, kind, subject order, criterion order
  and curriculum text. Band/subject-position/criterion-position is unique.
- `report_card_criteria_drafts`: tenant, effective period/date, complete band draft,
  staff attribution and application timestamp. Tenant/effective-period is unique.
- `report_card_criteria_histories`: tenant, nullable staff key, fixed action and time;
  each applied configuration also stores `effective_period`, `effective_on` and
  complete `bands` JSON, including band identities/ranges, subjects and behaviours.
  Draft-save audit entries have no applied version. Versions are retained even
  after they leave the editor's 15 most recent changes. New bands receive their
  real IDs before their applied version is stored.
- `report_card_band_freezes`: tenant, band and reporting-period key, unique per pair.
- `report_cards.criteria_band_id`: hidden reference. Existing subject/criterion mark
  snapshots remain the document content. No new `masjids` column is needed.

Every table has a primary key and uses `BelongsToMasjid`. Staff attribution foreign
keys become null on hard deletion; curriculum remains. No contact references or
new member-account deletion statements are introduced. Staging keeps curriculum
text as school material and scrubs staff through the existing staff policy. A
populated migration rollback refuses to discard curriculum or publication locks;
disable the grant instead.

An incremental migration adds the nullable version fields to the existing history
table, preserving its tenant scope and primary key. Before replacing an older
build's unversioned configuration, the reader preserves the available current
configuration. Configurations already overwritten by that older build cannot be
reconstructed from its action-only audit; issued and unfinished cards still keep
their saved rows. Cards dated before the first recoverable configuration use the
original template.

Office: `GET|PUT /api/admin/masjids/{id}/report-card-criteria`, under the tenant,
capability and `manage contacts` gates. Teacher:
`GET|PUT /api/teacher/masjids/{id}/groups/{group}/report-card-criteria`, under the
teacher's tenant, class-lead and capability gates. Both use the same service.

PUT requires `revision` and `bands`; `next_term` defaults to false. Each band has
`id` (null for new), `name`, `from_grade`, `to_grade`, `is_default`, `subjects`
(`name`, ordered `criteria` strings), and ordered `behaviours` strings. Browser
boolean strings are normalized before validation. Saves return the full editor
payload, including permissions, freeze flags, revision, pending draft/date,
`draft_effective_on_label` and a 15-entry history. `draft_effective_on` remains an
ISO calendar date for logic; history `at` and the date label are formatted by the
server using the school's timezone. Validation and stale/conflicting changes return 422; staff
outside a subject/class fence receive 403. Foreign band IDs are rejected.

`ReportCardCriteria` is the sole executable caller of `ReportCardTemplate`.
`template()` selects period curriculum when given a card and current curriculum
for previews; `marks()` and `markQuery()` expose saved snapshots.
The teacher save request shares its maximum-row contract, rather than accepting
subject or criterion text in mark updates. The legacy template file is unchanged.
