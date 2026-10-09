# Switching meeting weekdays and dated terms

The capability `school_calendar_terms` is OFF by default. Production switch-on
requires the owner's approval for the specific school after staging proof.
Only a SuperAdmin can change it, using the single capability PATCH or the bulk
capability PATCH; the catalogue exposes the OFF grant only with
`include_calendar_terms=1`.

1. Switch on outside school hours; do not edit the calendar while switching.
2. Review the school's years and retained terms before switching. Enable initializes
   existing NULL weekdays from each year's legacy weekday and validates retained
   terms in one transaction. A NULL year committed later still resolves to that
   legacy weekday in `SchoolDateAuthority::weekdays`.
3. If refused, correct the named configuration and retry. On a lock wait timeout or
   deadlock, the response is 422 with `data.capability`:
   “The school calendar is being edited. Try again in a few moments.”
   The switch rolls back completely, with no partial initialization, override or
   audit write. MySQL uses a five-second lock wait for this transaction and restores
   the previous session value afterwards. It makes one attempt. SQLite is unchanged.
4. After success, reload the office calendar and verify the weekdays and terms.
   Switching OFF also uses the same transaction and refuses configurations that
   cannot be represented by the legacy single-weekday calendar. It retains terms.

## Accepted operator limitations

“A year deletion dispatched while OFF can miss a form answer if switch-on and an
ON year edit change its dates or weekdays before the deletion commits. Switch on
outside school hours; do not edit the calendar while switching.”

“An in-flight OFF closure or year edit either completes first or, if InnoDB chooses
it as the deadlock victim, fails once. The office must retry that edit.”

The switch is atomic and fails cleanly; it is not designed to win races against
live traffic. The OFF delete and closure paths keep main's lock order and SQL.
ON year edits refuse removed weekdays or shorter bounds while sourced form
answers name dates that would leave the calendar; typed choices and a form's own
reservable dates do not hold school meeting days.

## History visibility

The capability history query keeps main's newest 25 rows. When the calendar is OFF
and the opt-in catalogue is not requested, PHP removes calendar-term history rows
from that window, after actor names are loaded. Dormant hidden rows may therefore
leave fewer than 25 visible rows, including none. A school never switched on has
exactly main's history and SQL.


## Enabled readers (slice C)

With `school_calendar_terms` ON, office, teacher and family calendar reads expand
through `SchoolDateAuthority`. Each year adds resolved `meeting_weekdays`,
`term_system` and `terms[{id,name,starts_on,ends_on,position}]`; the legacy
`meeting_weekday` stays the weekday of `first_day`. Teacher and family term lists
are read-only. NULL weekday storage is resolved only by the authority; an empty
array stays empty. OFF calendars omit the additive fields and use main's serializer.

Coming up remains twelve successive school days, including closures marked
“No school” with their reason. Attendance supplements actual marks with every open
school date and keeps its denominators and 40-column fallback. A closed day still
refuses a register; make-up dates remain permitted. Teacher lesson weeks list open school dates and any other date with a saved,
authorized plan in the requested interval. The ON payload supplies exact
`week_dates` and `day_notices`; Day remains writable on closed/nonmeeting dates.
An empty Week says “No school this week.” Enabled default register dates and
lesson weeks follow the school's clock, including midnight and DST boundaries.

School-day form choices offer future open dates and label historical/closed answers
without changing stored answers. The public option shape stays `value`/`label`
(with existing closure `detail` for historical labels). Selection errors say
“Pick exactly N days.”, “Pick at least N days.” or “Pick no more than N days.”
The singular count remains “day”. Unavailable questions say “No school days are open
right now.” or “Not enough school days are open right now.” while ON; OFF keeps
the original cleaning-Sunday wording. Typed and reservable-date sources keep their rules.

The weekly points email retains its send schedule. ON, it skips a week only when
there is no open school day in that week; OFF, any closure still skips the week.
The scheduled command resolves the switch and calendar fresh. It stores no request
memo, as do queue and console callers. Only the global HTTP middleware owns a
memo: it covers office, teacher, family and public forms, and clears it in finally.
Enabled readers share eager years, closures and terms within the same school and
tenant binding for that request. Saving or deleting a year, closure or term
invalidates all of that school’s cached authorities; a local switch also clears
its cached decision and organisation row. Model events do not cover bulk Eloquent
or query-builder writes. There are no such HTTP calendar writers currently; a new
bulk writer must call `SchoolCalendarReaders::forget($schoolId)` after its write
for each affected school. Staging scrub’s bulk term-name write runs in console,
where readers have no cache.

Staging walks remain necessary before enabling a real school. The slice C evidence
and the desktop, 390/320 px and RTL checklist are in `artifacts/slice-c-report.md`.


## Office year drafts and report cards

With dated terms ON, year create/update accepts `terms` as the complete draft
list (`id` on retained terms, `name`, `starts_on`, `ends_on`, `position`). All
fields and term additions/edits/removals commit in one transaction under the
organisation then year mutex. Omitted `terms` leaves existing terms alone for
cached clients; `terms: []` removes all terms. Separate term routes are removed.
Validation keys are `terms.<index>.<field>` with the term name in the message.
Term numbers remain stable after removal. Existing IDs keep their card links,
including number swaps through a temporary 0 within the same transaction.
Removing a term clears only the card's FK through ON DELETE SET NULL; cards and
their historical year text/quarter remain. Office terms add `report_card_count`
from one tenant-scoped grouped query, used for the draft removal warning.

The year window stages edits and Cancel discards them. New meeting-day choices
come from the most recent year by first day, or start empty. Office day lists
are collapsed month groups showing open and closed counts and retaining reasons
when expanded. These additions apply only with dated terms ON.

Report-card linking uses exact year names first; only when none match does it
compare the first/last calendar years of the school's stored date bounds with
`YYYY-YYYY`. Ambiguity still refuses linking. The link command shares that rule
in write and dry run and prints `rule=name` or `rule=dates` when a year matched.
The ON teacher report list supplies `school_years` derived from date bounds,
newest first; the browser also retains the last opened card's year while in this
class. Linked teacher cards use the term's own name and dates in `period_label`.
Unlinked labels, PDF/model labels and all OFF payloads retain their existing form.
