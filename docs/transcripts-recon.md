# Focused transcript recon

Base 30cc970831d3840921b3d112ea68c05cf297c01b, 2026-10-14, focused mode.

Laravel 12/PHP ^8.2, Vue 3/Vite 6/TypeScript 5.7/Pinia. Native PHP 8.3.35 and Node 22.12.0 observed. Existing copies of dependencies; mpdf present. MySQL production, in-memory SQLite PHP tests.

Reuse: LetterGrades::snapshot writes term_grades only during report-card publication; TermGrade stores percentage as a DECIMAL in schema but its model casts float, so transcript calculations must read decimal strings directly. ReportCard.grade_label freezes the year's grade at publication. GradeLevel defines grade order. SchoolTerm links SchoolYear. Contact::dateOfBirthOrNull() reads encrypted date_of_birth safely. GroupResourceFiles stores prior PDFs privately and GroupResourceRecipients addresses a file to one student. FamilyController::subject and GroupAudience enforce the ward gate. ReportCardPdfService establishes Letter paper/mpdf script/font settings. Office uses admin/tenant/crm/view or manage contacts; teacher uses named-school routes and SubjectFence.

New records must use BelongsToMasjid. Received copies are owned entirely by receiver; source requests remain sender-owned. Cross-tenant inbox needs a single explicit, constrained projection, never a sender model serialization. Snapshot JSON contains only document fields, never final override reasons or school/internal IDs. No masjids column is needed. Hidden disabled grant must preserve source-contract method bodies and OFF fixtures.
