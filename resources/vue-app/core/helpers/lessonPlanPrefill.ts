/**
 * What a pacing-guide week writes into a lesson plan, as plain functions (no
 * Vue, no HTTP), so `node --test` can prove them: the plan form is a component
 * and the test runner cannot mount one.
 *
 * Two rules live here.
 *
 * ISLAMIC INTEGRATION. A plan's "Islamic integration" box is written from the
 * same week's OTHER subjects. It used to take the first sibling whose subject
 * matches /Qur|Islamic/, because the guide had one combined "Qur'an & Islamic
 * Studies" column. The school's separated plan (Pre-K to Grade 2, Quarter 1) has
 * Qur'an and Islamic Studies as two subjects, so:
 *   - exactly one Islamic sibling: its focus alone, exactly as before (with its
 *     Objective after it when it has one, which only the separated weeks do);
 *   - several: one `Subject: focus` line each, so neither is dropped;
 *   - Arabic Language stays with the other subjects (it is not Islamic Studies).
 *
 * LEARNING OUTCOME. The school's plan gives each week a Learning Outcome beside
 * its Objective. It fills the plan's outcomes only where the teacher has written
 * none or the guide wrote the only one there, which is the rule `autoFill` in
 * TeacherClass.vue applies to every other field: what a teacher wrote or edited
 * is theirs and is never touched.
 */

export interface GuideSibling {
    subject: string;
    focus: string;
    /** Only the school's separated weeks have one; it holds the surah and the specifics. */
    objective?: string | null;
}

/** A sibling's words: its Focus Skill, and its Objective after it when it has one. */
const siblingText = (s: GuideSibling): string =>
    s.objective ? `${s.focus} — ${s.objective}` : s.focus;

/** The test the form has always used for "this subject is Islamic". */
const ISLAMIC = /Qur|Islamic/i;

export function islamicIntegration(siblings: GuideSibling[]): { islamic: string; others: string } {
    const hits = siblings.filter((s) => ISLAMIC.test(s.subject));
    const islamic = hits.length === 1
        ? siblingText(hits[0])
        : hits.map((s) => `${s.subject}: ${siblingText(s)}`).join('\n');
    const others = siblings.filter((s) => !hits.includes(s))
        .map((s) => `${s.subject}: ${siblingText(s)}`).join('\n');
    return { islamic, others };
}

/**
 * The outcomes list a guide week's Learning Outcome should write, or `null` to
 * leave the field alone.
 *
 * `current` is what the form holds. `lastAuto` is the outcome the guide last
 * wrote there, if it did. It writes `[outcome]` when `current` has no non-blank
 * entry, or is exactly `[lastAuto]`; anything else is the teacher's. When the
 * guide has no outcome and the list is exactly `[lastAuto]`, the guide's own
 * earlier text is emptied (`[]`), as `autoFill` empties every other field it
 * wrote, so a Qur'an week's outcome does not stay beside an ELA objective.
 * `null` when the guide has no outcome and there is nothing of the guide's to
 * empty, and when the list already is `[outcome]`.
 */
export function outcomeFill(
    current: string[] | null | undefined,
    outcome: string | null | undefined,
    lastAuto: string | null | undefined,
): string[] | null {
    const held = (current ?? []).map((o) => String(o ?? '').trim()).filter((o) => o !== '');
    const guideWrote = held.length === 1 && lastAuto != null && held[0] === String(lastAuto).trim();
    if (outcome == null || outcome === '') return guideWrote ? [] : null;
    const untouched = held.length === 0 || guideWrote;
    if (!untouched) return null;
    if (held.length === 1 && held[0] === outcome) return null;
    return [outcome];
}

/**
 * True when a plan already holds a week the guide's list for its grade and
 * subject does not carry. The school's separated Qur'an, Arabic and Islamic
 * Studies weeks run 1-8 only, so a plan for week 12 must keep the free number
 * input rather than a select with no option for it.
 */
export function weekOutsideGuide(
    week: number | string | null | undefined,
    weeks: { week_no: number }[],
): boolean {
    if (week == null || week === '' || !weeks.length) return false;
    return !weeks.some((w) => w.week_no === Number(week));
}
