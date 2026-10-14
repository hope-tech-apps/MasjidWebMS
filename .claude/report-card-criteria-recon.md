# Report-card criteria focused recon

Base: c155f2d0. Date: 2026-10-14. Mode: focused, report cards only.

Laravel 12 / PHP 8.3; Vue 3 / Vite 6; package-lock is the web lockfile.
Grade ordering is GradeLevel::LEVELS/position, already used by LetterGrades.
Before this build ReportCardService::ensureRows was the only executable caller of
ReportCardTemplate. Teacher/PDF/family views and the records export read stored
ReportCardMark rows; SaveReportCardRequest validates mark edits, not curriculum.
The existing backend/web recon headers describe older revisions and were not used
as current evidence. Current implementation and contract: docs/report-card-criteria.md.
