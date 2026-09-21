/**
 * Teachers — the admin-facing provisioning of a scoped staff login.
 *
 * A Teacher is created by an admin from the dashboard: they get a login (an
 * emailed invite) and are assigned the classes they lead. The teacher's own
 * scoped shell lives elsewhere (see teacherRoutes.ts / TeacherApiService.ts);
 * this type is only what the ADMIN screen reads and submits.
 *
 * `TeacherClass` is intentionally the narrow `{id, name}` the teachers endpoint
 * eager-loads onto each row — it is NOT the full `Group`. What a "class" is
 * CALLED for this tenant ("Classrooms" / "Halaqat" / "Teams") comes from the
 * terminology pack via `useMasjidStore().term('groups')`, never hardcoded here.
 */

/** The narrow class row the teachers endpoint attaches to each teacher. */
export type TeacherClass = {
    id: number;
    name: string;
};

/** One teacher row as the admin index returns it. */
export type Teacher = {
    id: number;
    name: string;
    email: string;
    /**
     * True while the emailed invite is still outstanding — the login exists but
     * the teacher has not yet accepted it. Rendered as an "Invited" vs "Active"
     * badge. Read defensively: anything falsy is treated as active.
     */
    invited: boolean;
    classes: TeacherClass[];
};

/**
 * What a teacher teaches in ONE class (GroupStaff::SUBJECTS; owner, 2026-09-21).
 * Keyed by class id. A class left out, or given an empty list, teaches everything —
 * which is what every full-time teacher is.
 */
export type TeacherSubject = 'quran' | 'arabic' | 'islamic_studies';
export type ClassSubjects = Record<number, TeacherSubject[] | null>;

/** Shape submitted by the "Add Teacher" form (server stamps masjid_id). */
export type TeacherPayload = {
    name: string;
    email: string;
    phone: string;
    /** The ids of the classes this teacher leads — at least one is required. */
    class_ids: number[];
    class_subjects?: ClassSubjects;
};

/**
 * The single-teacher read used to PRE-FILL the edit form (GET /teachers/{id}).
 *
 * Unlike the index `Teacher` row (which carries the eager-loaded `{id, name}`
 * class chips as `classes`), this returns the raw `class_ids` so the multiselect
 * can tick the currently-assigned boxes directly. `email` is read here only to
 * display it disabled — it is NOT editable.
 */
export type TeacherDetail = {
    id: number;
    name: string;
    email: string;
    phone: string;
    class_ids: number[];
    class_subjects?: ClassSubjects;
};

/**
 * Shape submitted by the "Edit Teacher" form (PUT /teachers/{id}).
 *
 * Email is intentionally absent: it is not editable. `class_ids` is the FULL new
 * set the server syncs to — at least one is required. Phone is optional and is
 * omitted from the body when blank (an empty string can trip a format rule).
 */
export type TeacherUpdatePayload = {
    name: string;
    phone: string;
    class_ids: number[];
    class_subjects?: ClassSubjects;
};
