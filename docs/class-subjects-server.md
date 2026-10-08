# Class subjects: server contracts

Report cards remain class-staff-wide ON and OFF, including teachers assigned no subjects, by the owner's review-5 decision (2026-10-08).

ON saved-work authorization remains the single ID predicate in `SubjectFence::allowsWork`; general lesson plans retain shared access. Saved display text is a snapshot and renaming a subject does not replace it. An ON edit that omits `subject` preserves its snapshot and link, including an unchanged-ID-only edit. Explicitly making a named/linked plan general requires access to the original work and an unrestricted assignment; the model enforces both. Clients can request that choice with `subject: null, class_subject_id: null`. A date-only edit selects the existing general plan, or the sole permitted named plan when no general plan exists; several permitted named plans require an explicit choice (409). An empty day may still create a general plan. An unchanged subject ID can reference existing hidden work without making a new picker choice. Other lesson-plan prose retains the whole-object replacement contract. OFF request semantics remain origin/main's.

The tool middleware reads the assignment for its check, then the tool controller writes without re-reading subject permission. A write authorized immediately before revocation may complete. This is the existing legacy property; tool writes do not acquire the school mutex. Later requests see the new restriction.

ON curriculum resolves guide links per class. No class means the union of links from permitted subjects in led classes. All subjects in one class means only that class's links, also for a class-scoped request. OFF requests without a class retain the original whole-school guide. Teacher subject-list responses omit office guide/tool metadata.

Capability decisions for response serialization are passed from the controller through request attributes and reused by response helpers. They do not survive the request. Model writers use fresh capability reads and their existing school/current-row guards, never response flags.

## Activation and reactivation

Use `php artisan class-subjects:initialize --masjid=ID --dry-run` to preview, and `--enable` to activate. Dry run is ordinary autocommit SELECTs: no transaction, locking read or write. MySQL's normal metadata locks on SELECTs are not eliminated; these are not school row locks that block ordinary FK-backed writes. A preview can observe concurrent changes between statements. Activation recomputes the same report under its school mutex.

A staff row translates only if both `class_subjects_mapped_at` and `class_subject_ids_edited_at` are NULL. The former records activation translation; the latter records an explicit ON office ID choice, including newly created ON assignments. Thus OFF-created rows without either fact translate on activation; previously translated/office-chosen rows never translate again. Their IDs, activation audit snapshot and timestamps survive reactivation. The edit-marker reactivation blocker was removed. NULL remains stored as NULL and prints `all subjects`. Late OFF inserts without either authority fact fail closed while ON until activation or an explicit office choice.

Guide columns split on `&`, whole-word `and`, `/` or `,` are skipped during seeding when all parts' SubjectKeys are represented in the class's existing/planned subjects. Setup reports the combined column and applicable grades. Office creation remains explicit and allowed; saved guide content is not altered. The same seeding function serves setup, ON lifecycle and add-for-current-grades.

## Deliberate disable

Generic capability toggles cannot switch this feature OFF after activation. Use `php artisan class-subjects:disable --masjid=ID --dry-run`. The real command takes the school mutex, builds/rechecks the report and locks current staff rows, then updates legacy subjects and flips OFF in one transaction. Blockers prevent all writes. Subjects, links, IDs and their authority facts remain stored.

NULL maps to legacy NULL/all. A nonempty ID set is expressible only when it is exactly a union of the unique Hifdh holder, Arabic-letters holder and Islamic Studies subject of that class. Empty, malformed, foreign or other-subject restrictions cannot be expressed. Each requires its staff row id in `--accept-unrestricted=ROW_ID,ROW_ID`; accepted rows get legacy NULL, while IDs stay as stored. Missing, foreign-school, unnecessary and malformed acceptances are refused. This describes the owner's specified legacy assignment representation; switching OFF restores the historical route behavior, including the whole-school no-class guide and legacy English-letter ownership.

Hidden OFF-to-OFF capability no-ops write neither an override nor a ledger row. Feature history is omitted from existing capability responses while OFF. Other capability ledger policies remain intact.

## Next screens

Teacher screens use `class_subject_id`, current permitted class subjects and the class's guide links; they do not require office metadata. Editors preserve omitted subject choices; only unrestricted teachers can explicitly choose General for named work. Office all-subject choices send NULL. The platform switch must show the disable command refusal; the office command distinguishes staff row ids from teacher ids. No frontend behavior was changed in this server round.
