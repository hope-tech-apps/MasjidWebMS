<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Support\SchoolCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The school-wide attendance log — every class, a window of days, read only.
 *
 * THREE DIFFERENT THINGS PRODUCE NO ROW in `attendance_records`, and a screen
 * that cannot tell them apart is worse than no screen at all:
 *
 *   1. NOBODY TOOK THE REGISTER. The class met and the tab was never opened.
 *      Twelve children hold no mark and not one of them was away.
 *   2. THE REGISTER WAS TAKEN AND THIS CHILD WAS SKIPPED. Eleven marks exist for
 *      that day and the twelfth cell is blank. That is a gap in a child's record
 *      and the one case the office actually has to chase.
 *   3. THERE WAS NO SCHOOL. A closure, or a day outside the school year. No
 *      register was owed, so nothing is missing.
 *
 * Every shape in this payload exists to keep those three apart. `taken_by` names
 * the classes that hold at least one mark on a day, so the client can draw "this
 * class's register was not taken" without a request per class per day. A child's
 * `unmarked` counts only the days their OWN class took a register while they
 * were enrolled — a missing cell is never an absence. Closed days are lifted out
 * into `closures` instead of standing as empty columns.
 *
 * AND IT IS WHY THERE IS NO PERCENTAGE ANYWHERE IN HERE. An attendance rate
 * needs a denominator of days the child was OWED a register, and no such column
 * exists in this codebase. The only honest denominator this data supports is
 * `registers` — the days their class actually took one, clipped to their
 * enrolment — and that is served as a count beside the four statuses rather than
 * divided into them. Inventing a days_possible is how "your daughter attended
 * 62%" reaches a parent on the strength of a fortnight a teacher forgot to mark.
 *
 * READ ONLY, and structurally so: two public methods, both GETs, no form request
 * and no write on any path. Taking and correcting a register is the teacher's
 * verb (Teacher\AttendanceController) because a mark stamps `marked_by_user_id`
 * — the same line the gradebook mount draws between reading the office's own
 * records and putting an administrator's name on a judgement.
 *
 * Tenant isolation is the BelongsToMasjid global scope on Group, GroupMembership
 * and AttendanceRecord, bound from the route's {masjid_id} by `tenant`. Nothing
 * here hand-filters on masjid_id (.claude/rules/tenant-scoping.md), so another
 * school's class or membership id is a 404 rather than a filtered row.
 */
class AttendanceLogController extends Controller
{
    /**
     * How many day columns the grid will draw.
     *
     * Past this the payload refuses the grid outright rather than truncating it.
     * A register that silently stopped at the fortieth column is a screen that
     * says a child has no marks in October, and somebody would read it.
     */
    public const COLUMN_CAP = 40;

    /** The default window: today and the twenty-nine days before it. */
    private const DEFAULT_WINDOW_DAYS = 30;

    /** The default and the ceiling for `per_page`. */
    private const PER_PAGE = 50;
    private const PER_PAGE_MAX = 100;

    /**
     * The marks that put a child on the office's morning call list.
     *
     * NOT `! wasPresent()`. `excused` is missing on purpose — the office already
     * accepted that absence, and listing it would have somebody phone a family
     * that rang ahead. `late` is here even though it counts as attendance
     * everywhere a total is computed (AttendanceRecord::PRESENT_STATUSES),
     * because a late arrival is a conversation rather than a statistic.
     */
    private const AWAY_STATUSES = [AttendanceRecord::STATUS_ABSENT, AttendanceRecord::STATUS_LATE];

    /**
     * The grid: the classes, the days, and one row per child.
     */
    public function index(Request $request, $masjid_id): JsonResponse
    {
        $calendar = SchoolCalendar::for((int) $masjid_id);
        [$from, $to] = $this->window($request, $calendar);

        $classes = $this->classesInScope($request->query('group_id'));
        $classIds = $classes->map(fn (Group $g) => (int) $g->id)->all();
        $classNames = $classes->mapWithKeys(fn (Group $g) => [(int) $g->id => $g->name])->all();

        $columns = $this->columns($classIds, $from, $to, $calendar);
        $omitted = count($columns) > self::COLUMN_CAP;

        $rosters = $this->rosterSizes($classIds);

        $query = $this->studentQuery($request, $classIds);
        $perPage = $this->perPage($request);
        $page = max(1, $this->intOr($request->query('page'), 1));

        // Counted before the page is fetched and NOT derived from it, so `total`
        // answers "how many children are in this window" even when the grid
        // below is omitted and the page is never read.
        $total = (clone $query)->count();

        $rows = $omitted
            ? new Collection()
            : $query->with('contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS)
                ->forPage($page, $perPage)
                ->get();

        $cells = $this->cellsFor($rows->map(fn (GroupMembership $m) => (int) $m->id)->all(), $from, $to);

        $students = $rows->map(function (GroupMembership $membership) use ($columns, $cells, $classNames): array {
            $tally = $this->tally($membership, $columns, $cells->get((int) $membership->id) ?? new Collection());

            return $this->studentHeader($membership, $classNames[(int) $membership->group_id] ?? null) + [
                // An object even when empty: a client indexing `cells[date]`
                // would otherwise meet a JSON array on exactly the children who
                // hold no marks, which is every child on a fresh term.
                'cells' => (object) $tally['cells'],
                'totals' => $tally['totals'],
            ];
        })->values()->all();

        return response()->json([
            'status' => 'success',
            'data' => [
                'from' => $from,
                'to' => $to,
                'timezone' => $calendar->timezone(),
                'has_calendar' => $calendar->hasCalendar(),
                'days' => $omitted ? [] : $this->dayPayload($columns),
                // Still served when the grid is omitted: a no-school day needs
                // no column to be worth saying out loud.
                'closures' => $this->closures($from, $to, $calendar),
                'students' => $students,
                'classes' => $this->classPayload($classes, $columns, $rosters, $from, $to),
                'today' => $this->todayBand($classes, $rosters, $calendar),
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'column_cap' => self::COLUMN_CAP,
                    'grid_omitted' => $omitted,
                    'grid_omitted_reason' => $omitted ? sprintf(
                        'That window covers %d school days. The grid shows at most %d — narrow the dates.',
                        count($columns),
                        self::COLUMN_CAP,
                    ) : null,
                    'columns' => count($columns),
                ],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * One child's window, opened from their row in the grid.
     */
    public function forMember(Request $request, $masjid_id, $membership_id): JsonResponse
    {
        $calendar = SchoolCalendar::for((int) $masjid_id);
        [$from, $to] = $this->window($request, $calendar);

        // Resolved through the same class filter the grid uses, so a roster row
        // in a group that takes no register is a 404 here rather than a page of
        // zeroes. The masjid global scope has already answered for another
        // school's membership id, before any of this runs.
        $membership = GroupMembership::query()
            ->participants()
            ->whereHas('group', fn ($q) => $q->whereIn('kind', [Group::KIND_CLASS, Group::KIND_HALAQA]))
            ->with(['contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS, 'group:id,name'])
            ->findOrFail($membership_id);

        // THE COLUMNS ARE THIS CHILD'S CLASS'S COLUMNS, which is not a narrowing
        // of the grid's arithmetic — it is the same arithmetic. A child's
        // `unmarked` and `registers` count only the days whose `taken_by` names
        // their own group, so the other classes' columns contribute nothing to
        // either number. Restricting the set keeps the drill-down off a query
        // over every class in the school and still lands on the same totals,
        // which is the one thing this endpoint must never get wrong: a
        // drill-down that disagrees with the row it was opened from tells the
        // office that neither number can be trusted.
        $columns = $this->columns([(int) $membership->group_id], $from, $to, $calendar);

        $marks = $this->marksIn(
            AttendanceRecord::query()->where('group_membership_id', $membership->id),
            $from,
            $to,
        )->orderBy('session_date')->get()->keyBy(fn (AttendanceRecord $r) => $r->session_date->toDateString());

        $tally = $this->tally($membership, $columns, $marks);

        return response()->json([
            'status' => 'success',
            'data' => [
                'student' => $this->studentHeader($membership, $membership->group?->name),
                'from' => $from,
                'to' => $to,
                'timezone' => $calendar->timezone(),
                'totals' => $tally['totals'],
                'entries' => $marks->values()->map(fn (AttendanceRecord $r): array => [
                    'date' => $r->session_date->toDateString(),
                    'status' => $r->status,
                    'note' => $r->note,
                ])->all(),
                'not_marked' => $tally['not_marked'],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * THE PER-CHILD ARITHMETIC, and the only place it happens.
     *
     * Both the grid row and the drill-down come through here, because the two
     * disagreeing is this screen's worst possible failure: the office would be
     * left with two numbers for one child and no way to tell which is the record.
     *
     * `unmarked` is the count of columns where the child's class took a register
     * AND the child was enrolled AND no cell exists. A missing cell on a day
     * nobody marked is not one of them.
     *
     * `registers` is the UNION of those enrolment-clipped register days and the
     * days the child holds a mark on — not the larger of the two counts, which
     * is what this method did first. Enrolment dates are typed by hand, so a
     * joined_at entered a week late puts a real mark outside the clip; taking a
     * max() refilled that hole with an unrelated unmarked day and printed "2 of
     * 2" directly above a register the child was never marked on. A union cannot:
     * it makes `marked` + `unmarked` <= `registers` structural rather than
     * coincidental, and it also absorbs a mark whose day was later closed.
     *
     * @param  list<array{date:string,taken_by:array<int,bool>}>  $columns
     * @param  Collection<string,AttendanceRecord>  $marks  keyed by 'Y-m-d'
     * @return array{cells:array<string,array{status:string,note:?string}>,not_marked:list<string>,totals:array<string,int>}
     */
    private function tally(GroupMembership $membership, array $columns, Collection $marks): array
    {
        $groupId = (int) $membership->group_id;
        $joined = $membership->joined_at?->toDateString();
        $left = $membership->left_on?->toDateString();

        $cells = [];
        $counts = array_fill_keys(AttendanceRecord::STATUSES, 0);

        foreach ($marks as $day => $mark) {
            $cells[$day] = ['status' => $mark->status, 'note' => $mark->note];
            $counts[$mark->status] = ($counts[$mark->status] ?? 0) + 1;
        }

        $notMarked = [];
        $counted = [];

        foreach ($columns as $column) {
            if (! isset($column['taken_by'][$groupId])) {
                continue;
            }

            // Clipped to the enrolment. A child who joined in November owes
            // nothing for October, and a child who left in March owes nothing
            // after it — counting those days would turn a roster edit into an
            // attendance problem.
            if (($joined !== null && $column['date'] < $joined) || ($left !== null && $column['date'] > $left)) {
                continue;
            }

            $counted[$column['date']] = true;

            if (! array_key_exists($column['date'], $cells)) {
                $notMarked[] = $column['date'];
            }
        }

        $marked = array_sum($counts);

        return [
            'cells' => $cells,
            'not_marked' => $notMarked,
            'totals' => [
                'present' => $counts[AttendanceRecord::STATUS_PRESENT],
                // Reported on its own line, never folded into `present`. Late is
                // attendance (AttendanceRecord::PRESENT_STATUSES) and it is also
                // the thing a parent conversation is about, so the payload
                // states both and divides neither.
                'late' => $counts[AttendanceRecord::STATUS_LATE],
                'absent' => $counts[AttendanceRecord::STATUS_ABSENT],
                'excused' => $counts[AttendanceRecord::STATUS_EXCUSED],
                'marked' => $marked,
                'unmarked' => count($notMarked),
                // The key-union of the days owed and the days marked. Both are
                // keyed 'Y-m-d', so `+` is the union and its count is the honest
                // denominator; see the docblock for the compound case a max()
                // got wrong.
                'registers' => count($counted + $cells),
            ],
        ];
    }

    /**
     * THE DAY COLUMNS: the union of the days somebody actually marked and the
     * days the school's calendar says it met, with the closed days removed.
     *
     * Both halves are needed. A calendar-only set would drop a make-up day a
     * teacher took a register on — the register accepts one, treating everything
     * but `closed` as a hint (Teacher\AttendanceController::index). A marks-only
     * set would hide the failure this screen exists to show: a Sunday the school
     * met and NOBODY opened the register has no rows anywhere, and only the
     * calendar can say a column was owed.
     *
     * An organisation with no school_years row (Al-Razi) has no calendar and gets
     * columns exactly where somebody took a register — the same "behave as you
     * did before calendars existed" contract SchoolCalendar states.
     *
     * Dropping the closed days loses nothing: SchoolCalendarController::
     * writeClosure refuses to close a day that already holds marks, counted under
     * the school year's row lock, so a closed column can never have had a
     * register in it.
     *
     * @param  list<int>  $classIds
     * @return list<array{date:string,taken_by:array<int,bool>}>
     */
    private function columns(array $classIds, string $from, string $to, SchoolCalendar $calendar): array
    {
        $marked = $this->marksIn(
            AttendanceRecord::query()->whereIn('group_id', $classIds),
            $from,
            $to,
        )->select('group_id', 'session_date')->distinct()->get();

        $days = [];

        foreach ($marked as $row) {
            $days[$row->session_date->toDateString()][(int) $row->group_id] = true;
        }

        if ($calendar->hasCalendar()) {
            foreach ($calendar->labelledDays() as $day) {
                if ($day['closed'] || $day['date'] < $from || $day['date'] > $to) {
                    continue;
                }

                $days[$day['date']] ??= [];
            }
        }

        foreach ($this->closures($from, $to, $calendar) as $closure) {
            unset($days[$closure['date']]);
        }

        ksort($days);

        $columns = [];

        foreach ($days as $date => $takenBy) {
            $columns[] = ['date' => (string) $date, 'taken_by' => $takenBy];
        }

        return $columns;
    }

    /**
     * The columns as the client reads them. `taken_by` goes out as a sorted list
     * of ids; the internal form is a set because tally() asks it one membership
     * at a time and a linear search per child per day is the N+1 of arrays.
     *
     * @param  list<array{date:string,taken_by:array<int,bool>}>  $columns
     * @return list<array{date:string,weekday:string,taken_by:list<int>}>
     */
    private function dayPayload(array $columns): array
    {
        return array_map(function (array $column): array {
            $takenBy = array_keys($column['taken_by']);
            sort($takenBy);

            return [
                'date' => $column['date'],
                // 'Mon'. Carried so the header row does not have to reparse
                // forty dates in the browser to draw a weekday it already knows.
                'weekday' => SchoolCalendar::day($column['date'])?->format('D') ?? '',
                'taken_by' => array_map('intval', $takenBy),
            ];
        }, $columns);
    }

    /**
     * The no-school days inside the window, with the reason the office gave.
     *
     * Read from SchoolCalendar's labelled day set rather than from
     * school_closures directly, so "was there school on the 22nd" has one answer
     * here and in the register.
     *
     * @return list<array{date:string,reason:?string}>
     */
    private function closures(string $from, string $to, SchoolCalendar $calendar): array
    {
        $out = [];

        foreach ($calendar->labelledDays() as $day) {
            if ($day['closed'] && $day['date'] >= $from && $day['date'] <= $to) {
                $out[] = ['date' => $day['date'], 'reason' => $day['reason']];
            }
        }

        return $out;
    }

    /**
     * Per-class totals for the window. Counted in SQL, one grouped query for the
     * whole school — the alternative, a pass per class, is the shape that makes
     * this screen unusable at twelve classes and fine at one.
     *
     * @param  Collection<int,Group>  $classes
     * @param  list<array{date:string,taken_by:array<int,bool>}>  $columns
     * @param  array<int,int>  $rosters
     * @return list<array<string,int|string|null>>
     */
    private function classPayload(Collection $classes, array $columns, array $rosters, string $from, string $to): array
    {
        $classIds = $classes->map(fn (Group $g) => (int) $g->id)->all();

        $counts = [];

        foreach ($this->marksIn(AttendanceRecord::query()->whereIn('group_id', $classIds), $from, $to)
            ->groupBy('group_id', 'status')
            ->selectRaw('group_id, status, COUNT(*) as marks')
            ->get() as $row) {
            $counts[(int) $row->group_id][$row->status] = (int) $row->marks;
        }

        return $classes->map(function (Group $group) use ($columns, $counts, $rosters): array {
            $id = (int) $group->id;
            $mine = $counts[$id] ?? [];

            $taken = 0;

            foreach ($columns as $column) {
                if (isset($column['taken_by'][$id])) {
                    $taken++;
                }
            }

            return [
                'group_id' => $id,
                'name' => $group->name,
                'roster' => $rosters[$id] ?? 0,
                'registers_taken' => $taken,
                'marked' => array_sum($mine),
                'present' => $mine[AttendanceRecord::STATUS_PRESENT] ?? 0,
                'late' => $mine[AttendanceRecord::STATUS_LATE] ?? 0,
                'absent' => $mine[AttendanceRecord::STATUS_ABSENT] ?? 0,
                'excused' => $mine[AttendanceRecord::STATUS_EXCUSED] ?? 0,
            ];
        })->values()->all();
    }

    /**
     * Today, on the SCHOOL's clock — who has not taken a register yet and who is
     * away. The band the office reads at nine in the morning, which is why it is
     * computed for today rather than for the end of the window: a principal
     * looking at last month still wants to know about this morning.
     *
     * Who counts as away is AWAY_STATUSES, and that constant carries the reason
     * it is not simply `! wasPresent()`.
     *
     * @param  Collection<int,Group>  $classes
     * @param  array<int,int>  $rosters
     */
    private function todayBand(Collection $classes, array $rosters, SchoolCalendar $calendar): array
    {
        $today = $calendar->today();
        $classIds = $classes->map(fn (Group $g) => (int) $g->id)->all();
        $names = $classes->mapWithKeys(fn (Group $g) => [(int) $g->id => $g->name])->all();

        $marks = $this->marksIn(
            AttendanceRecord::query()->whereIn('group_id', $classIds),
            $today,
            $today,
        )->select('group_id', 'group_membership_id', 'status')->get();

        $marked = [];
        $awayIds = [];

        foreach ($marks as $mark) {
            $groupId = (int) $mark->group_id;
            $marked[$groupId] = ($marked[$groupId] ?? 0) + 1;

            if (in_array($mark->status, self::AWAY_STATUSES, true)) {
                $awayIds[] = (int) $mark->group_membership_id;
            }
        }

        $away = [];

        if ($awayIds !== []) {
            $rows = GroupMembership::query()
                ->whereKey($awayIds)
                ->with('contact:id,first_name,last_name,'.Contact::AVATAR_COLUMNS)
                ->get()
                ->keyBy(fn (GroupMembership $m) => (int) $m->id);

            // Walked class by class in DISPLAY ORDER, then by name inside each,
            // so the call list reads down the screen in the same order as the
            // classes above it rather than in whatever order the marks were
            // saved in.
            foreach ($classIds as $groupId) {
                $inClass = [];

                foreach ($marks as $mark) {
                    $membership = $rows->get((int) $mark->group_membership_id);

                    if ($membership === null || (int) $mark->group_id !== $groupId
                        || ! in_array($mark->status, self::AWAY_STATUSES, true)) {
                        continue;
                    }

                    $contact = $membership->contact;

                    $inClass[] = [
                        'membership_id' => (int) $membership->id,
                        'first_name' => $contact?->first_name,
                        'last_name' => $contact?->last_name,
                        'group_name' => $names[$groupId] ?? null,
                        'status' => $mark->status,
                    ];
                }

                usort($inClass, fn (array $a, array $b) => [$a['last_name'], $a['first_name']]
                    <=> [$b['last_name'], $b['first_name']]);

                $away = array_merge($away, $inClass);
            }
        }

        return [
            'date' => $today,
            // What the calendar says about today, in the same shape the teacher's
            // register answers with (Teacher\AttendanceController::index). Without
            // it a closed day reads as twelve classes that forgot to take the
            // register — the grid below goes to real trouble to keep those two
            // apart, and the band must not undo that at the top of the screen.
            'school_day' => $calendar->schoolDay($today),
            'classes' => $classes->map(fn (Group $group): array => [
                'group_id' => (int) $group->id,
                'name' => $group->name,
                'roster' => $rosters[(int) $group->id] ?? 0,
                'marked' => $marked[(int) $group->id] ?? 0,
                'taken' => ($marked[(int) $group->id] ?? 0) > 0,
            ])->values()->all(),
            'away' => $away,
        ];
    }

    /**
     * The classes this log covers: teaching groups, in the order the office
     * arranged them. Group::scopeInDisplayOrder is the one definition every
     * school screen sorts by, so this log lists a school's classes in the same
     * order the roster and the teacher's own list do.
     *
     * Narrowing by ?group_id= goes through findOrFail, so another school's id —
     * invisible under the masjid global scope — is a 404 rather than an empty
     * grid, which would read as "nobody attended".
     *
     * @return Collection<int,Group>
     */
    private function classesInScope(mixed $groupId): Collection
    {
        $query = Group::query()->whereIn('kind', [Group::KIND_CLASS, Group::KIND_HALAQA]);

        if (! is_scalar($groupId) || (string) $groupId === '') {
            return $query->inDisplayOrder()->get();
        }

        return new Collection([$query->findOrFail((int) $groupId)]);
    }

    /**
     * The roster rows the grid will show, ordered but not yet paged.
     *
     * Ordered by class display order, then last name, then first name. The class
     * order is a CASE over the ids scopeInDisplayOrder already returned rather
     * than a second copy of its ORDER BY, so the two cannot drift apart when the
     * office reorders its classes.
     *
     * The join onto `contacts` is what makes the name ordering and the name
     * search happen in SQL, which is what makes the paging honest — sorting a
     * page in PHP sorts the wrong fifty children. It is unambiguous because
     * `contacts` carries neither `role` nor `left_on`, the two columns the
     * participants()/current() scopes name.
     *
     * @param  list<int>  $classIds
     * @return \Illuminate\Database\Eloquent\Builder<GroupMembership>
     */
    private function studentQuery(Request $request, array $classIds)
    {
        $raw = $request->query('search');
        $search = is_scalar($raw) ? trim((string) $raw) : '';

        // A query string has no booleans: '1' is what the contract names and
        // 'true' is what a client library will put there.
        $includeWithdrawn = filter_var($request->query('include_withdrawn'), FILTER_VALIDATE_BOOLEAN);

        $query = GroupMembership::query()
            ->participants()
            ->whereIn('group_memberships.group_id', $classIds)
            ->join('contacts', 'contacts.id', '=', 'group_memberships.contact_id')
            ->select('group_memberships.*');

        if (! $includeWithdrawn) {
            $query->current();
        }

        if ($search !== '') {
            // Two columns rather than ContactsController's CONCAT() pair: that
            // function is not portable to the SQLite the suite runs on, and a
            // log filtered by a name is asking about a first or a last one.
            $query->where(function ($q) use ($search): void {
                $q->where('contacts.first_name', 'like', "%{$search}%")
                    ->orWhere('contacts.last_name', 'like', "%{$search}%");
            });
        }

        [$sql, $bindings] = $this->classOrder($classIds);

        return $query->orderByRaw($sql, $bindings)
            ->orderBy('contacts.last_name')
            ->orderBy('contacts.first_name')
            ->orderBy('group_memberships.id');
    }

    /**
     * A CASE that reproduces the class order already decided by
     * scopeInDisplayOrder, for use in a query that cannot join to it.
     *
     * @param  list<int>  $classIds
     * @return array{0:string,1:list<int>}
     */
    private function classOrder(array $classIds): array
    {
        if ($classIds === []) {
            return ['(0)', []];
        }

        $bindings = [];
        $whens = '';

        foreach (array_values($classIds) as $position => $id) {
            $whens .= ' WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $position;
        }

        $bindings[] = count($classIds);

        return ['CASE group_memberships.group_id'.$whens.' ELSE ? END', $bindings];
    }

    /**
     * How many children are currently on each class's roster. One grouped query,
     * and the same number feeds the class totals and the today band so the two
     * cannot print different roster sizes for one class on one screen.
     *
     * @param  list<int>  $classIds
     * @return array<int,int>
     */
    private function rosterSizes(array $classIds): array
    {
        return GroupMembership::query()
            ->participants()->current()
            ->whereIn('group_id', $classIds)
            ->groupBy('group_id')
            ->selectRaw('group_id, COUNT(*) as roster')
            ->get()
            ->mapWithKeys(fn (GroupMembership $row) => [(int) $row->group_id => (int) $row->roster])
            ->all();
    }

    /**
     * Every mark the page's children hold in the window, in one query, grouped by
     * child. Fetching a child's cells inside the row loop is the defect this
     * exists to avoid: fifty children is fifty queries, and it only shows up
     * once a school has a term of data.
     *
     * @param  list<int>  $membershipIds
     * @return Collection<int,Collection<string,AttendanceRecord>>
     */
    private function cellsFor(array $membershipIds, string $from, string $to): Collection
    {
        if ($membershipIds === []) {
            return new Collection();
        }

        return $this->marksIn(
            AttendanceRecord::query()->whereIn('group_membership_id', $membershipIds),
            $from,
            $to,
        )->select('group_membership_id', 'session_date', 'status', 'note')
            ->get()
            ->groupBy(fn (AttendanceRecord $r) => (int) $r->group_membership_id)
            ->map(fn (Collection $rows) => $rows->keyBy(fn (AttendanceRecord $r) => $r->session_date->toDateString()));
    }

    /**
     * The window, as a HALF-OPEN range on the raw `session_date` column.
     *
     * Half-open rather than whereDate() on both ends because this is the read
     * that has to use `attendance_class_day_idx`, and DATE() around the column
     * stops MySQL reaching for it. Raw rather than BETWEEN because the `date`
     * cast stores 'Y-m-d 00:00:00' on SQLite, which sorts outside a BETWEEN
     * against bare day strings and silently drops the last day of the window —
     * the failure LessonPlanController::index records, and the reason
     * SchoolCalendar::closureFor is written this way too.
     *
     * @template TBuilder of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    private function marksIn($query, string $from, string $to)
    {
        return $query->where('session_date', '>=', $from)
            ->where('session_date', '<', $this->shift($to, 1));
    }

    /**
     * A child's identity on this screen — NAMES ONLY.
     *
     * The same boundary Teacher\TeacherController::student() draws, restated here
     * because this controller is in the admin realm and cannot inherit it.
     * Contact and GroupMembership have no $hidden, so letting either model into a
     * payload publishes email, phone, staff notes and every login_* column to
     * whoever opened an attendance screen. `grade_label` is the one non-name
     * field and it belongs for the same reason it does there: it is roster data
     * the office typed, not a disclosure about a family.
     */
    private function studentHeader(GroupMembership $membership, ?string $groupName): array
    {
        $contact = $membership->contact;

        return [
            'membership_id' => (int) $membership->id,
            'group_id' => (int) $membership->group_id,
            'group_name' => $groupName,
            'grade_label' => $membership->grade_label,
            // BOTH ENDS OF THE ENROLMENT, present on every row rather than only
            // where they are set, so a client never has to infer "still here"
            // from a missing key.
            //
            // `joined_at` travels for a reason beyond symmetry: the clip that
            // decides `unmarked` is applied to both ends on the server, and a
            // grid that had only `left_on` would draw a mid-year joiner's empty
            // cells before their first day as ordinary gaps — days the child's
            // own record, reading the same clip, does not list under "not
            // marked". Sending it is what lets one screen say one thing.
            'joined_at' => $membership->joined_at?->toDateString(),
            'left_on' => $membership->left_on?->toDateString(),
            'contact' => $contact ? [
                'id' => (int) $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'avatar' => $contact->avatar,
            ] : null,
        ];
    }

    /**
     * The window being asked about, as two 'Y-m-d' strings.
     *
     * "Today" is the SCHOOL's today (SchoolCalendar::today), never
     * Carbon::today(): at eight in the evening Eastern the server's day has
     * already rolled over, and the log would open on a day nobody has taught yet.
     *
     * An unparseable date falls back to the default instead of a 422, and ends
     * that arrive backwards are swapped instead of refused. This is a date picker
     * on a read-only screen; the office must not be able to produce an error page
     * from it — the same tolerance LessonPlanController::dateOr states for the
     * teacher's week strip.
     *
     * @return array{0:string,1:string}
     */
    private function window(Request $request, SchoolCalendar $calendar): array
    {
        $to = $this->dateOr($request->query('to'), $calendar->today());
        $from = $this->dateOr($request->query('from'), $this->shift($to, -(self::DEFAULT_WINDOW_DAYS - 1)));

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /** A query date, or the fallback when it is absent or not a real 'Y-m-d'. */
    private function dateOr(mixed $raw, string $fallback): string
    {
        return SchoolCalendar::isIsoDate($raw) ? $raw : $fallback;
    }

    /**
     * A day shifted by whole days. Through SchoolCalendar::day(), which is UTC
     * midnight arithmetic with no DST in it — adding a day to a spring-forward
     * Sunday in a local zone can land back on the same date.
     */
    private function shift(string $day, int $days): string
    {
        return SchoolCalendar::day($day)?->addDays($days)->toDateString() ?? $day;
    }

    /** The page size, defaulted and capped. A payload has to end somewhere. */
    private function perPage(Request $request): int
    {
        $per = $this->intOr($request->query('per_page'), self::PER_PAGE);

        return $per < 1 ? self::PER_PAGE : min($per, self::PER_PAGE_MAX);
    }

    /**
     * A whole number from the query string, or the fallback.
     *
     * Through is_scalar rather than a bare (int) cast because `?page[]=1` hands
     * the cast an array, which PHP answers with a warning and a 1 — a crafted URL
     * should not be able to raise anything on a read-only screen.
     */
    private function intOr(mixed $raw, int $fallback): int
    {
        return is_scalar($raw) && $raw !== '' ? (int) $raw : $fallback;
    }
}
