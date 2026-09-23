/**
 * The attendance log — what the office reads over
 * /api/admin/masjids/{masjid_id}/attendance.
 *
 * Mirrors the payload the admin AttendanceController answers with. Nothing in
 * this file derives a figure and nothing in the screen may either: the
 * enrolment clip, the union of columns and the six counts are computed once on
 * the server, against the rows and the school calendar, and a second
 * implementation in TypeScript is how the grid row and the child's own record
 * start telling a parent two different things.
 *
 * Three properties of this payload are load-bearing:
 *
 *  1. **A missing cell is never an absence.** `cells` holds only the days
 *     somebody actually marked. Whether a blank means "the register was not
 *     taken", "it was taken and this child was missed" or "this child was not
 *     enrolled yet" is decided from `days[].taken_by` and the enrolment
 *     bounds — never from the absence of a key.
 *  2. **There is no percentage here, and there must never be one.** The four
 *     statuses are reported separately and `registers` is the honest
 *     denominator. This codebase has no `days_possible`, and inventing one is
 *     how a wrong attendance rate reaches a family.
 *  3. **`totals` on a student row and `totals` on that child's record are the
 *     same server helper.** A drill-down that disagrees with the row it was
 *     opened from is the worst failure this screen can have, so the screen
 *     prints both and computes neither.
 */

/** The four marks a register can hold. `late` is being in the room, late. */
export type AttendanceStatus = 'present' | 'late' | 'absent' | 'excused';

/**
 * The avatar block PersonAvatar.vue takes. Mirrored here rather than imported
 * because the component declares its prop inline; keep the two in step.
 */
export type AttendanceAvatar = {
    character?: string;
    tone?: string;
    color?: string;
    url?: string;
} | null;

/**
 * Names only. The server hand-builds this: a Contact model has no `$hidden`, so
 * serializing one would publish email, phone, notes and the login columns.
 *
 * Every field is nullable because the controller reads them off a contact that
 * may itself be missing (`'contact' => $contact ? [...] : null`) — a membership
 * whose contact row was removed still has a register behind it.
 */
export type AttendanceContact = {
    id: number;
    first_name: string | null;
    last_name: string | null;
    avatar?: AttendanceAvatar;
};

/**
 * One column of the grid.
 *
 * `taken_by` is the ids of the classes holding at least one mark that day. It
 * exists so the screen can draw "this class's register was not taken" without a
 * request per class per day — read it, never infer it from empty cells.
 */
export type AttendanceDay = {
    date: string;
    weekday: string;
    taken_by: number[];
};

/** A no-school day inside the window. Excluded from the columns, listed here. */
export type AttendanceClosure = {
    date: string;
    reason: string;
};

/** One mark. `note` is the teacher's own words and is shown, never summarised. */
export type AttendanceCell = {
    status: AttendanceStatus;
    note: string | null;
};

/**
 * The six counts plus the denominator.
 *
 * `registers` is the UNION of the days this child's class took a register while
 * they were enrolled and the days they hold a mark on — a union rather than the
 * larger of the two, so a bad joined_at or left_on can neither drop a real mark
 * out of the denominator nor absorb a real gap into it, and
 * `marked` + `unmarked` <= `registers` always holds. `unmarked` counts only the
 * columns where the class DID take a register while the child was enrolled and
 * no mark exists for them.
 */
export type AttendanceTotals = {
    present: number;
    late: number;
    absent: number;
    excused: number;
    marked: number;
    unmarked: number;
    registers: number;
};

/**
 * One row of the grid.
 *
 * Both ends of the enrolment are always sent, and either may be null — a
 * membership with no recorded start has no joined_at. The grid clips its cells
 * with the same two dates the server clips its counts with, which is the point
 * of sending them: a mid-year joiner's row draws its pre-enrolment days as
 * outside the enrolment rather than as days somebody forgot to mark, and the
 * row then agrees with the child's own record, which does not list them under
 * "Not marked" either. A null joined_at means every day up to `left_on` counts.
 */
export type AttendanceStudentRow = {
    membership_id: number;
    group_id: number;
    group_name: string | null;
    grade_label: string | null;
    left_on: string | null;
    joined_at?: string | null;
    contact: AttendanceContact | null;
    /** Keyed by 'Y-m-d'. Only the days somebody marked are present. */
    cells: Record<string, AttendanceCell>;
    totals: AttendanceTotals;
};

/** One class's totals over the window. Served even when the grid is omitted. */
export type AttendanceClassTotals = {
    group_id: number;
    name: string;
    roster: number;
    registers_taken: number;
    marked: number;
    present: number;
    late: number;
    absent: number;
    excused: number;
};

/** One class this morning: whether the register exists yet, and how far it got. */
export type AttendanceTodayClass = {
    group_id: number;
    name: string;
    roster: number;
    marked: number;
    taken: boolean;
};

/** A child marked away today. The list an office works through every morning. */
export type AttendanceAwayToday = {
    membership_id: number;
    first_name: string | null;
    last_name: string | null;
    group_name: string | null;
    status: 'absent' | 'late';
};

/** The school's today, in the school's own timezone — never the browser's. */
/**
 * What the school's calendar says about today, in the same shape the teacher's
 * own register answers with. It is what lets the band say "there was no school
 * today" instead of listing every class as one that never took its register.
 * An organisation with no school year has has_calendar false and the band says
 * nothing about school days at all.
 */
export type AttendanceSchoolDay = {
    has_calendar: boolean;
    in_year: boolean;
    meeting_day: boolean;
    closed: boolean;
    reason: string | null;
};

export type AttendanceToday = {
    date: string;
    school_day: AttendanceSchoolDay;
    classes: AttendanceTodayClass[];
    away: AttendanceAwayToday[];
};

/**
 * `column_cap` is the most columns the grid will draw. Past it the server
 * answers 200 with `grid_omitted` and the real `columns` count, and says so in
 * `grid_omitted_reason` — a register is never silently truncated.
 */
export type AttendanceLogMeta = {
    page: number;
    per_page: number;
    total: number;
    column_cap: number;
    grid_omitted: boolean;
    grid_omitted_reason: string | null;
    columns: number;
};

export type AttendanceLog = {
    from: string;
    to: string;
    timezone: string;
    /**
     * False for an organisation with no school year (Al-Razi). It gets columns
     * only where somebody took a register, which is correct rather than empty:
     * there are no meeting days to fill in.
     */
    has_calendar: boolean;
    days: AttendanceDay[];
    closures: AttendanceClosure[];
    students: AttendanceStudentRow[];
    classes: AttendanceClassTotals[];
    today: AttendanceToday;
    meta: AttendanceLogMeta;
};

/** One line of a child's own record. */
export type AttendanceEntry = {
    date: string;
    status: AttendanceStatus;
    note: string | null;
};

/** The child, without their cells: the drill-down lists entries instead. */
export type AttendanceMemberStudent = {
    membership_id: number;
    group_id: number;
    group_name: string | null;
    grade_label: string | null;
    left_on: string | null;
    contact: AttendanceContact | null;
};

/**
 * One child's record over the same window as the grid.
 *
 * `not_marked` is the days their class took a register while they were
 * enrolled and nobody marked them — the same set the grid draws as a middot,
 * spelled out so the office can chase it.
 */
export type AttendanceMemberRecord = {
    student: AttendanceMemberStudent;
    from: string;
    to: string;
    timezone: string;
    totals: AttendanceTotals;
    entries: AttendanceEntry[];
    not_marked: string[];
};

/**
 * What the screen asks for. Every field is optional to the server: an absent
 * or unparseable date falls back to the default window rather than 422ing, so
 * a half-typed date in the picker never blanks the page.
 */
export type AttendanceLogFilters = {
    from: string;
    to: string;
    group_id: number | '';
    search: string;
    include_withdrawn: boolean;
};

/** Both bounds, for one child's record. It takes no other filter. */
export type AttendanceMemberFilters = {
    from: string;
    to: string;
};

/** The letter in a grid cell. Kept beside the statuses so they cannot drift. */
export const ATTENDANCE_LETTERS: Record<AttendanceStatus, string> = {
    present: 'P',
    late: 'L',
    absent: 'A',
    excused: 'E',
};

/**
 * The word an office reads. Matches the teacher's register buttons
 * (TeacherClass.vue's ATT_OPTIONS) so both screens name a mark the same way.
 */
export const ATTENDANCE_LABELS: Record<AttendanceStatus, string> = {
    present: 'Present',
    late: 'Late',
    absent: 'Absent',
    excused: 'Excused',
};
