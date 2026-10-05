/**
 * A student's age on a class list: the WORDS only.
 *
 * The number itself is worked out on the server, in whole years on the school's
 * clock (App\Support\StudentAge), and travels as `age: number | null`. Nothing
 * here does date arithmetic, and nothing here ever holds a date of birth except
 * the office's own form, which reads it from its own endpoint.
 *
 * `null` means "no age to show", and there are several reasons the server sends
 * it: no date on file, a row that is not a student in a class (a guardian, a
 * leader, a ḥalaqa or a team), or a stored date that could not be read. The
 * screens treat them alike: nothing on a row, "—" in a table cell, and one
 * sentence in the teacher's sheet.
 */

/** What the server sends for a teacher's student (TeacherController::classPayload). */
export type TeacherStudent = {
    membership_id: number;
    grade_label?: string | null;
    age?: number | null;
    contact?: { id?: number; first_name?: string | null; last_name?: string | null; avatar?: unknown } | null;
};

/** The facts a roster row needs to be counted below. */
type RosterRow = { role?: string | null; left_on?: string | null; age?: number | null; age_given?: boolean | null };

/** A whole number of years, or null for anything else (absent, null, a string, a fraction, a negative). */
function wholeYears(age: unknown): number | null {
    return typeof age === 'number' && Number.isInteger(age) && age >= 0 ? age : null;
}

/**
 * "Age 7", or '' when there is no age to show. For a row, where nothing is drawn
 * for unknown.
 *
 * `given` is the office roster's `age_given`: the number is the age the family
 * gave at registration, brought up to today, because no date of birth is on
 * file. It can be a year behind after a birthday, so the office is told which
 * ages those are. A teacher's payload carries no such flag and reads "Age 7".
 */
export function ageLabel(age: unknown, given: unknown = false): string {
    const years = wholeYears(age);

    if (years === null) return '';

    return given === true ? `Age ${years}, as the family gave it at registration` : `Age ${years}`;
}

/** The word beside a number in the office's Age column when the family gave it, and what it means. */
export const AGE_GIVEN_MARK = 'given';
export const AGE_GIVEN_TITLE = 'The age their family gave at registration. Add a date of birth for an exact age.';

/** Is this row's age the one the family gave (and is there an age at all)? */
export function ageWasGiven(row: { age?: unknown; age_given?: unknown } | null | undefined): boolean {
    return row?.age_given === true && wholeYears(row?.age) !== null;
}

/** "7", or "—". For the Age column of a table, where an empty cell would read as a fault. */
export function ageCell(age: unknown): string {
    const years = wholeYears(age);

    return years === null ? '—' : String(years);
}

/** The teacher's sheet: the age, or who can add it. A teacher cannot, and never sees the date. */
export const AGE_NOT_ON_FILE = 'Age not on file. The school office can add a date of birth.';

export function sheetAgeLine(age: unknown): string {
    return ageLabel(age) || AGE_NOT_ON_FILE;
}

/**
 * The one line the teacher's sheet says about families. Parents' phone numbers
 * are for the office (the owner's decision, 2026-10-04), so the sheet names the
 * office instead of a parent.
 */
export const EMERGENCY_LINE = 'In an emergency, contact the school office. The office holds each family\'s phone numbers.';

/**
 * How many CURRENT students on this roster have no age, for the office's line
 * above the table. A student who has left is not counted: nobody is asked to
 * complete the record of a child who is no longer in the class.
 */
export function studentsMissingAnAge(rows: RosterRow[] | null | undefined): number {
    return (rows ?? []).filter((r) => r?.role === 'member' && !r.left_on && wholeYears(r.age) === null).length;
}

/**
 * "3 students have no date of birth on file, so no age is shown for them. Tap a
 * name to add it." Empty when every current student has one, and for a group
 * that is not a class (`isClass` false), where no age is shown for anybody.
 */
export function missingBirthDatesLine(rows: RosterRow[] | null | undefined, isClass = true): string {
    const n = isClass ? studentsMissingAnAge(rows) : 0;
    const given = isClass ? studentsWithAGivenAge(rows) : 0;

    const missing = n === 0
        ? ''
        : n === 1
            ? '1 student has no date of birth on file, so no age is shown for them. Tap their name to add it.'
            : `${n} students have no date of birth on file, so no age is shown for them. Tap a name to add it.`;

    // The ages families gave are shown, and said to be that: one can be a year
    // behind after a birthday, and only a date of birth makes it exact.
    const fromFamilies = given === 0
        ? ''
        : given === 1
            ? `1 age marked "${AGE_GIVEN_MARK}" is the one the family gave at registration. Tap the name to add a date of birth for an exact age.`
            : `${given} ages marked "${AGE_GIVEN_MARK}" are the ones families gave at registration. Tap a name to add a date of birth for an exact age.`;

    return [missing, fromFamilies].filter(Boolean).join(' ');
}

/** How many CURRENT students on this roster show the age their family gave. */
export function studentsWithAGivenAge(rows: RosterRow[] | null | undefined): number {
    return (rows ?? []).filter((r) => r?.role === 'member' && !r.left_on && ageWasGiven(r)).length;
}

/**
 * What the teacher's student sheet shows, and ALL it shows: the avatar, the
 * name, the grade and the age.
 *
 * Built here, key by key, rather than by handing the sheet the student object:
 * whatever the class payload grows later (and a guardian is exactly what it
 * must never grow), the sheet cannot start printing it. student-age.test.ts
 * pins the key list.
 */
export type StudentSheetModel = {
    avatar: any;
    name: string;
    grade: string;
    age: number | null;
};

export function studentSheetModel(student: TeacherStudent | null | undefined): StudentSheetModel {
    const contact = student?.contact ?? null;

    return {
        avatar: contact?.avatar ?? null,
        name: [contact?.first_name, contact?.last_name].filter(Boolean).join(' ') || 'Student',
        grade: student?.grade_label ?? '',
        age: wholeYears(student?.age),
    };
}

// ------------------------------------------------------------------ the office's date form

/** The earliest day the server accepts (`after:1900-01-01`). */
export const BIRTH_DATE_MIN = '1900-01-02';

const isDay = (value: unknown): value is string => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);

/**
 * The latest day the date field offers: the SCHOOL's today, as the server says
 * it (`school_today`), because that is the day the server judges "not in the
 * future" by. The browser's own day is the fallback only while the server has
 * not answered; a wrong guess there is corrected by the server's refusal.
 */
export function birthDateMax(schoolToday: unknown, now: Date = new Date()): string {
    if (isDay(schoolToday)) return schoolToday;

    const pad = (n: number) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** Why this day cannot be saved, or '' when it can. The same three rules the server keeps. */
export function birthDateProblem(value: unknown, schoolToday: unknown, now: Date = new Date()): string {
    if (!isDay(value)) return 'Enter the date of birth.';
    if (value < BIRTH_DATE_MIN) return 'The date of birth cannot be before 1900.';
    if (value > birthDateMax(schoolToday, now)) return 'The date of birth cannot be in the future.';

    return '';
}

/** "March 9, 2017" from 'Y-m-d', printed in UTC so the browser's zone cannot move the day. */
export function birthDateWords(ymd: unknown): string {
    if (!isDay(ymd)) return '';

    const [y, m, d] = ymd.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-US', {
        month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC',
    });
}
